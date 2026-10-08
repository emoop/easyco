<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingClassInUseException;
use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use App\Services\ShippingClassInput;
use App\Services\ShippingClassWriter;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Exceptions\ShippingClassCodeAlreadyExistsException;
use EasyCo\Shipping\ShippingClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5b (shipping-domain-design.md §3, §12.3.1, §12.6): the class WRITER — create / update / delete — validates
 * through the domain, writes exactly one audit entry, fires exactly one hook after the commit, and refuses in
 * translated messages with nothing written. A class is referenced BY CODE, and the code never changes.
 */
class ShippingClassWriterTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function writer(): ShippingClassWriter
    {
        return app(ShippingClassWriter::class);
    }

    private function input(string $name = 'Heavy', string $code = 'heavy', ?string $description = null): ShippingClassInput
    {
        return new ShippingClassInput($name, $code, $description);
    }

    /** @return array<string, list<string>> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ShippingClassInvalidException $exception) {
            return $exception->errors;
        }

        $this->fail('the write should have been refused');
    }

    private function classCount(): int
    {
        return DB::table('shipping_classes')->count();
    }

    private function hookNames(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->hookCalls);
    }

    // =====================================================================================================
    // Create
    // =====================================================================================================

    public function test_a_class_is_created_with_one_audit_entry_and_one_hook_after_the_commit(): void
    {
        $this->activityLogOn();
        $baseline = $this->spyOnClassHooks();

        $class = $this->writer()->create($this->input('  Heavy parcels ', 'heavy', ' bulky things '));

        $this->assertSame('Heavy parcels', $class->name());
        $this->assertSame('bulky things', $class->description());
        $this->assertNotNull($class->id());
        $this->assertSame('heavy', app(ShippingClassRepository::class)->findById((string) $class->id())->code());

        $rows = $this->classAuditRows();
        $this->assertCount(1, $rows);
        $this->assertSame('created', $rows[0]->action);
        $this->assertSame((string) $class->id(), $rows[0]->entity_id);

        $this->assertSame(['shipping.class.created'], $this->hookNames());
        $this->assertSame($baseline, $this->hookCalls[0][2], 'the hook fires outside the transaction');
        $this->assertSame($class, $this->hookCalls[0][1][0]);
    }

    public function test_an_empty_description_is_stored_as_none(): void
    {
        $class = $this->writer()->create($this->input('Heavy', 'heavy', '   '));

        $this->assertNull($class->description());
        $this->assertNull(DB::table('shipping_classes')->value('description'));
    }

    public function test_every_create_refusal_is_a_translated_field_error_and_writes_nothing(): void
    {
        $this->spyOnClassHooks();

        $cases = [
            'empty name' => [$this->input('   ', 'heavy'), 'name'],
            'overlong name' => [$this->input(str_repeat('n', 256), 'heavy'), 'name'],
            'html in the name is kept as text, a control character is not' => [$this->input("Hea\x07vy", 'heavy'), 'name'],
            'newline in the name' => [$this->input("Heavy\nclass", 'heavy'), 'name'],
            'bidi override in the name' => [$this->input("Heavy\u{202E}", 'heavy'), 'name'],
            'empty code' => [$this->input('Heavy', ''), 'code'],
            'a 100-character code' => [$this->input('Heavy', str_repeat('a', 100)), 'code'],
            'upper-case code' => [$this->input('Heavy', 'Heavy'), 'code'],
            'a space in the code' => [$this->input('Heavy', 'heavy parcels'), 'code'],
            'a dot in the code' => [$this->input('Heavy', 'heavy.parcels'), 'code'],
            'double separator' => [$this->input('Heavy', 'heavy--parcels'), 'code'],
            'leading separator' => [$this->input('Heavy', '-heavy'), 'code'],
            'html in the code' => [$this->input('Heavy', '<b>x</b>'), 'code'],
            'overlong description' => [$this->input('Heavy', 'heavy', str_repeat('d', 501)), 'description'],
            'control character in the description' => [$this->input('Heavy', 'heavy', "a\x00b"), 'description'],
            'newline in the description' => [$this->input('Heavy', 'heavy', "a\nb"), 'description'],
        ];

        foreach ($cases as $label => [$input, $field]) {
            $errors = $this->refusal(fn () => $this->writer()->create($input));

            $this->assertArrayHasKey($field, $errors, $label);
            $this->assertStringNotContainsString('shipping.classes', $errors[$field][0], "{$label}: translated, not a key");
        }

        $this->assertSame(0, $this->classCount(), 'nothing written');
        $this->assertSame([], $this->classAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_the_longest_accepted_values_are_accepted(): void
    {
        $class = $this->writer()->create($this->input(str_repeat('n', 255), str_repeat('a', 64), str_repeat('d', 500)));

        $this->assertSame(255, mb_strlen($class->name()));
        $this->assertSame(64, strlen($class->code()));
    }

    public function test_html_typed_in_a_name_is_stored_as_text_and_never_interpreted(): void
    {
        $class = $this->writer()->create($this->input('<script>alert(1)</script>', 'xss'));

        $this->assertSame('<script>alert(1)</script>', app(ShippingClassRepository::class)->findByCode('xss')->name());
        $this->assertSame('<script>alert(1)</script>', $class->name());
    }

    public function test_a_duplicate_code_is_refused_with_a_translated_message(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->spyOnClassHooks();

        $errors = $this->refusal(fn () => $this->writer()->create($this->input('Another', 'heavy')));

        $this->assertSame(__('shipping.classes.errors.code_taken'), $errors['code'][0]);
        $this->assertSame(1, $this->classCount());
        $this->assertSame([], $this->classAuditRows());
        $this->assertSame([], $this->hookCalls);

        App::setLocale('bg');
        $this->assertSame('Вече има клас с този код.', $this->refusal(fn () => $this->writer()->create($this->input('Друг', 'heavy')))['code'][0]);
    }

    public function test_a_unique_code_race_is_caught_by_the_database_constraint_not_by_a_message(): void
    {
        // The pre-check sees nothing (the other request has not committed yet); the unique index is the last word.
        $inner = app(ShippingClassRepository::class);
        $this->shippingClass('heavy', 'Heavy');

        $this->app->instance(ShippingClassRepository::class, new class($inner) implements ShippingClassRepository {
            public function __construct(private readonly ShippingClassRepository $inner)
            {
            }

            public function save(ShippingClass $class): void
            {
                $this->inner->save($class);
            }

            public function findById(string $id): ?ShippingClass
            {
                return $this->inner->findById($id);
            }

            public function findByCode(string $code): ?ShippingClass
            {
                return null; // blind to the row the other request just inserted
            }

            public function all(): array
            {
                return $this->inner->all();
            }

            public function delete(string $id): void
            {
                $this->inner->delete($id);
            }
        });

        $this->spyOnClassHooks();
        $writer = new ShippingClassWriter(
            app(ShippingClassRepository::class),
            app(\App\Services\ShippingClassUsageReader::class),
            app(\App\Services\ActivityLogger::class),
        );

        try {
            $writer->create($this->input('Another', 'heavy'));
            $this->fail('the unique index should have refused it');
        } catch (ShippingClassInvalidException $exception) {
            $this->assertSame(__('shipping.classes.errors.code_taken'), $exception->messageFor('code'));
        }

        $this->assertSame(1, $this->classCount());
        $this->assertSame([], $this->classAuditRows(), 'no audit entry for a refused write');
        $this->assertSame([], $this->hookCalls, 'no hook for a refused write');

        // and the repository itself translates the SQLSTATE 23000 + driver code into the domain exception
        $this->expectException(ShippingClassCodeAlreadyExistsException::class);
        $inner->save(ShippingClass::create('Dup', 'heavy'));
    }

    // =====================================================================================================
    // Update — the code never changes
    // =====================================================================================================

    public function test_an_update_changes_the_name_and_description_with_one_audit_entry_and_one_hook(): void
    {
        $this->activityLogOn();
        $class = $this->writer()->create($this->input('Heavy', 'heavy', 'old'));
        $this->spyOnClassHooks();
        $baseline = DB::transactionLevel();

        $saved = $this->writer()->update((string) $class->id(), $this->input('Very heavy', 'heavy', 'new'));

        $this->assertSame('Very heavy', $saved->name());
        $this->assertSame('Very heavy', app(ShippingClassRepository::class)->findByCode('heavy')->name());

        $rows = array_values(array_filter($this->classAuditRows(), static fn ($row): bool => $row->action === 'updated'));
        $this->assertCount(1, $rows, 'exactly one entry for an update');
        $this->assertSame('class', $rows[0]->field);
        $this->assertSame('Heavy', json_decode($rows[0]->old_value, true)['name']);
        $this->assertSame('Very heavy', json_decode($rows[0]->new_value, true)['name']);
        $this->assertSame('new', json_decode($rows[0]->new_value, true)['description']);

        $this->assertSame(['shipping.class.updated'], $this->hookNames());
        $this->assertSame($baseline, $this->hookCalls[0][2]);
        $this->assertSame('Heavy', $this->hookCalls[0][1][1]['name'], 'the hook carries the snapshot from before');
        $this->assertSame('old', $this->hookCalls[0][1][1]['description']);
    }

    public function test_an_update_that_changes_nothing_writes_nothing_and_fires_no_hook(): void
    {
        $this->activityLogOn();
        $class = $this->writer()->create($this->input('Heavy', 'heavy', 'same'));
        $auditBefore = count($this->classAuditRows());
        $this->spyOnClassHooks();

        $this->writer()->update((string) $class->id(), $this->input('  Heavy ', 'heavy', ' same '));

        $this->assertCount($auditBefore, $this->classAuditRows());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_the_code_can_never_be_changed_even_when_nothing_uses_the_class(): void
    {
        $class = $this->writer()->create($this->input('Heavy', 'heavy'));
        $this->spyOnClassHooks();

        $errors = $this->refusal(fn () => $this->writer()->update((string) $class->id(), $this->input('Heavy', 'heavier')));

        $this->assertSame(__('shipping.classes.errors.code_immutable'), $errors['code'][0]);
        $this->assertSame('heavy', DB::table('shipping_classes')->value('code'));
        $this->assertSame([], $this->hookCalls);
    }

    public function test_the_code_can_never_be_changed_when_a_method_and_a_variation_use_it(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $id = (string) app(ShippingClassRepository::class)->findByCode('heavy')->id();
        $zone = $this->zone('Z', 0);
        $this->perClassMethod((string) $zone->id(), 'Per class', ['heavy' => 900]);

        $errors = $this->refusal(fn () => $this->writer()->update($id, $this->input('Heavy', 'other')));

        $this->assertArrayHasKey('code', $errors);
        $this->assertSame(1, DB::table('shipping_method_class_rates')->where('class_code', 'heavy')->count(), 'the rate still points at the class');

        // and the database refuses it too: the rate table's foreign key restricts an update of the code
        try {
            DB::table('shipping_classes')->where('id', $id)->update(['code' => 'sneaky']);
            $this->fail('the restrict foreign key should have refused it');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
    }

    public function test_an_update_refuses_bad_values_and_an_unknown_class(): void
    {
        $class = $this->writer()->create($this->input('Heavy', 'heavy'));

        $this->assertArrayHasKey('name', $this->refusal(fn () => $this->writer()->update((string) $class->id(), $this->input('', 'heavy'))));
        $this->assertArrayHasKey('name', $this->refusal(fn () => $this->writer()->update((string) $class->id(), $this->input(str_repeat('n', 256), 'heavy'))));
        $this->assertArrayHasKey('description', $this->refusal(fn () => $this->writer()->update((string) $class->id(), $this->input('Heavy', 'heavy', "a\tb"))));

        $this->expectException(ShippingClassNotFoundException::class);
        $this->writer()->update('999999', $this->input('Heavy', 'heavy'));
    }

    // =====================================================================================================
    // Delete
    // =====================================================================================================

    public function test_an_unused_class_is_deleted_with_one_snapshot_and_one_hook(): void
    {
        $class = $this->writer()->create($this->input('Heavy', 'heavy', 'note'));
        $baseline = $this->spyOnClassHooks();

        $this->writer()->delete((string) $class->id());

        $this->assertSame(0, $this->classCount());

        // A delete is written even while the activity log is off.
        $rows = array_values(array_filter($this->classAuditRows(), static fn ($row): bool => $row->action === 'deleted'));
        $this->assertCount(1, $rows);
        $this->assertSame(['id' => (string) $class->id(), 'code' => 'heavy', 'name' => 'Heavy', 'description' => 'note'], json_decode($rows[0]->old_value, true));

        $this->assertSame(['shipping.class.deleted'], $this->hookNames());
        $this->assertSame($baseline, $this->hookCalls[0][2]);
        $this->assertSame('heavy', $this->hookCalls[0][1][0]['code']);
    }

    public function test_a_class_with_rates_is_refused_with_the_counts_and_nothing_is_cascaded(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->perClassMethod($zone, 'First', ['heavy' => 900]);
        $this->perClassMethod($zone, 'Second', ['heavy' => 700]);
        $id = (string) app(ShippingClassRepository::class)->findByCode('heavy')->id();
        $this->spyOnClassHooks();

        try {
            $this->writer()->delete($id);
            $this->fail('a class in use must not be deleted');
        } catch (ShippingClassInUseException $exception) {
            $this->assertSame(2, $exception->methodCount);
            $this->assertSame(0, $exception->variationCount);
            $this->assertStringContainsString('2 methods', $exception->getMessage());
            $this->assertStringContainsString('0 products', $exception->getMessage());
            $this->assertStringContainsString('Heavy', $exception->getMessage());
        }

        $this->assertSame(1, $this->classCount());
        $this->assertSame(2, DB::table('shipping_method_class_rates')->count(), 'the rates are untouched');
        $this->assertSame([], array_filter($this->classAuditRows(), static fn ($row): bool => $row->action === 'deleted'));
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_class_assigned_to_variations_is_refused_with_the_counts(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $id = (string) app(ShippingClassRepository::class)->findByCode('heavy')->id();
        $this->variationWithClass('heavy');
        $this->variationWithClass('heavy');
        $this->variationWithClass('heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->perClassMethod($zone, 'First', ['heavy' => 900]);

        try {
            $this->writer()->delete($id);
            $this->fail('a class in use must not be deleted');
        } catch (ShippingClassInUseException $exception) {
            $this->assertSame(1, $exception->methodCount);
            $this->assertSame(3, $exception->variationCount);
            $this->assertStringContainsString('1 method', $exception->getMessage());
            $this->assertStringContainsString('3 products and variations', $exception->getMessage());
        }

        App::setLocale('bg');
        try {
            $this->writer()->delete($id);
        } catch (ShippingClassInUseException $exception) {
            $this->assertStringContainsString('1 метод', $exception->getMessage());
            $this->assertStringContainsString('3 продукта и вариации', $exception->getMessage());
            $this->assertStringNotContainsString('shipping.classes', $exception->getMessage());
        }

        $this->assertSame(1, $this->classCount());
    }

    public function test_the_database_foreign_key_refusal_is_translated_to_the_same_exception(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->perClassMethod($zone, 'First', ['heavy' => 900]);
        $id = (string) app(ShippingClassRepository::class)->findByCode('heavy')->id();

        // Blind the application-level check (as a rate added in the instant after it would): only the key is left.
        $blind = new class extends \App\Services\ShippingClassUsageReader {
            private int $calls = 0;

            public function usage(?array $codes = null): array
            {
                return ++$this->calls === 1 ? array_fill_keys($codes ?? [], ['methods' => 0, 'variations' => 0]) : parent::usage($codes);
            }
        };

        $writer = new ShippingClassWriter(app(ShippingClassRepository::class), $blind, app(\App\Services\ActivityLogger::class));

        try {
            $writer->delete($id);
            $this->fail('the restrict foreign key should have refused it');
        } catch (ShippingClassInUseException $exception) {
            $this->assertSame(1, $exception->methodCount);
            $this->assertStringNotContainsString('SQLSTATE', $exception->getMessage());
        }

        $this->assertSame(1, $this->classCount());
        $this->assertSame(1, DB::table('shipping_method_class_rates')->count());
    }

    public function test_deleting_an_unknown_class_is_a_translated_refusal(): void
    {
        $this->expectException(ShippingClassNotFoundException::class);
        $this->writer()->delete('424242');
    }

    public function test_the_repository_delete_of_an_unknown_id_is_a_no_op(): void
    {
        app(ShippingClassRepository::class)->delete('424242');

        $this->assertSame(0, $this->classCount());
    }

    public function test_the_usage_reader_counts_each_class_in_two_grouped_reads(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('light', 'Light');
        $this->shippingClass('idle', 'Idle');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->perClassMethod($zone, 'A', ['heavy' => 900, 'light' => 100]);
        $this->perClassMethod($zone, 'B', ['heavy' => 800]);
        $this->variationWithClass('heavy');
        $this->variationWithClass('light');
        $this->variationWithClass('light');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $usage = app(\App\Services\ShippingClassUsageReader::class)->usage(['heavy', 'light', 'idle']);

        $this->assertSame(2, $queries);
        $this->assertSame(['methods' => 2, 'variations' => 1], $usage['heavy']);
        $this->assertSame(['methods' => 1, 'variations' => 2], $usage['light']);
        $this->assertSame(['methods' => 0, 'variations' => 0], $usage['idle']);
    }

    private function variationWithClass(string $code): string
    {
        static $n = 0;
        $n++;

        $product = \EasyCo\Catalog\Product::createSimple("Class Product {$n}", "CP-{$n}", "class-product-{$n}");
        $product->variations()[0]->setShippingClass($code);
        app(\EasyCo\Catalog\Contracts\ProductRepository::class)->save($product);

        return (string) $product->variations()[0]->id();
    }
}
