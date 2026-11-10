<?php

namespace Tests\Feature;

use App\Jobs\RefreshProductSortPrices;
use App\Services\Exceptions\PriceListInvalidException;
use App\Services\PriceListWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\PriceList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6b: the price-list writer — ONE transaction for the list and its scopes, ONE refresh queued after the commit for exactly the products the old and the new scopes touch (never the «unscoped list → all products»
 * pass in the middle of a create), the audit (one entry per changed field, none for a no-op), the four contained hooks and the validation.
 */
class PriceListWriterTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private static int $n = 0;

    /** @var list<array{0: string, 1: array<int, mixed>, 2: int}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $this->makeTag(1, 'A', 'a');
        $this->makeTag(2, 'B', 'b');
        $this->makeProduct(1, ['slug' => 'one', 'price' => '100.00', 'stock' => 1, 'tags' => [1]]);
        $this->makeProduct(2, ['slug' => 'two', 'price' => '100.00', 'stock' => 1, 'tags' => [2]]);
        $this->makeProduct(3, ['slug' => 'three', 'price' => '100.00', 'stock' => 1, 'tags' => [1]]);
        $this->makeProduct(4, ['slug' => 'four', 'price' => '100.00', 'stock' => 1]);
    }

    /** @param array<string, mixed> $over */
    private function data(array $over = []): array
    {
        return $over + ['name' => 'Campaign '.(++self::$n), 'percentage' => '30', 'is_active' => true, 'replaces_manual_sale' => false, 'confirm_all_products' => false, 'scopes' => [['type' => 'tag', 'reference' => '1']]];
    }

    private function record(string $hook): void
    {
        Hook::action($hook, function (...$args) use ($hook): void {
            $this->calls[] = [$hook, $args, DB::transactionLevel()];
        });
    }

    /** @return list<list<string>> */
    private function pushedSets(): array
    {
        return Queue::pushed(RefreshProductSortPrices::class)->map(fn ($job) => $job->productIds)->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return DB::table('activity_log')->where('entity_type', 'price_list')->orderBy('id')->get(['action', 'field', 'old_value', 'new_value'])->map(fn ($r) => (array) $r)->all();
    }

    // ---- one transaction ---------------------------------------------------------------------------------------------

    /** ★ a create with scopes is ONE transaction: when it fails after the list and the scopes were inserted, nothing remains */
    public function test_a_failing_create_leaves_no_list_no_scope_and_no_entry(): void
    {
        $real = app(PriceListRepository::class);
        $this->app->instance(PriceListRepository::class, new class($real) implements PriceListRepository {
            public function __construct(private readonly PriceListRepository $real)
            {
            }

            public function save(PriceList $priceList): void
            {
                $this->real->save($priceList);
            }

            public function saveWithScopes(PriceList $priceList, array $scopes): void
            {
                $this->real->saveWithScopes($priceList, $scopes);

                throw new RuntimeException('the write fails after the list and its scopes were inserted');
            }

            public function findById(string $id): ?PriceList
            {
                return $this->real->findById($id);
            }

            public function existsActiveAtPriority(int $priority, ?string $excludingId = null): bool
            {
                return $this->real->existsActiveAtPriority($priority, $excludingId);
            }

            public function findAllActiveAndValidAt(\DateTimeImmutable $at): array
            {
                return $this->real->findAllActiveAndValidAt($at);
            }

            public function findSystemListByName(string $name): ?PriceList
            {
                return $this->real->findSystemListByName($name);
            }
        });
        Queue::fake();
        $before = PriceListModel::query()->count();

        try {
            app(PriceListWriter::class)->create($this->data());
            $this->fail('expected the failure');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($before, PriceListModel::query()->count(), 'no list row remains');
        $this->assertSame(0, DB::table('pricing_price_list_scopes')->count(), 'no scope row remains');
        $this->assertSame([], $this->entries(), 'no audit entry remains');
        Queue::assertNothingPushed();
    }

    /** P6b.1: two campaigns with an IDENTICAL scope may exist: the writer gives the second one the next free priority (500, 501) */
    public function test_an_identical_scope_gets_the_next_priority(): void
    {
        $first = app(PriceListWriter::class)->create($this->data());
        $second = app(PriceListWriter::class)->create($this->data());

        $this->assertSame([500, 501], [(int) PriceListModel::find($first->id())->priority, (int) PriceListModel::find($second->id())->priority]);
    }

    /** a scoped create does not collide with another UNSCOPED campaign at the same priority (the list is inserted with the signature of its FINAL scope set) */
    public function test_a_scoped_create_does_not_collide_with_an_unscoped_campaign(): void
    {
        app(PriceListWriter::class)->create($this->data(['scopes' => [], 'confirm_all_products' => true]));

        $list = app(PriceListWriter::class)->create($this->data());

        $this->assertNotNull($list->id());
        $this->assertSame(2, PriceListModel::query()->where('is_system', false)->count());
    }

    // ---- the refresh ---------------------------------------------------------------------------------------------------

    /** ★ a scoped create queues refresh jobs ONLY for its products (not the «all products» pass the model events would have queued) */
    public function test_a_scoped_create_queues_jobs_only_for_its_products(): void
    {
        Queue::fake();

        app(PriceListWriter::class)->create($this->data());

        $this->assertSame([['1', '3']], $this->pushedSets());
    }

    /** ★ an unscoped create queues the full pass ONCE */
    public function test_an_unscoped_create_queues_the_full_pass_once(): void
    {
        Queue::fake();

        app(PriceListWriter::class)->create($this->data(['scopes' => [], 'confirm_all_products' => true]));

        $this->assertSame([['1', '2', '3', '4']], $this->pushedSets());
    }

    /** ★ an edit of the scopes refreshes the UNION of the products the old and the new scopes touch */
    public function test_an_edit_of_the_scopes_refreshes_the_union_of_old_and_new(): void
    {
        $list = app(PriceListWriter::class)->create($this->data()); // tag A: products 1, 3
        Queue::fake();

        app(PriceListWriter::class)->update((string) $list->id(), $this->data(['name' => $list->name(), 'scopes' => [['type' => 'tag', 'reference' => '2']]])); // tag B: product 2

        $this->assertSame([['1', '2', '3']], $this->pushedSets());
        $this->assertSame([['tag', '2']], DB::table('pricing_price_list_scopes')->where('price_list_id', $list->id())->get(['scope_type', 'scope_reference_id'])->map(fn ($r) => [$r->scope_type, $r->scope_reference_id])->all());
    }

    /** ★ a no-change save writes nothing, queues nothing, fires nothing */
    public function test_a_no_change_save_writes_nothing(): void
    {
        $list = app(PriceListWriter::class)->create($this->data(['name' => 'Same']));
        DB::table('activity_log')->delete();
        Queue::fake();
        $this->record('pricing.price_list.updated');
        $this->record('pricing.price_list.activated');
        $this->record('pricing.price_list.deactivated');

        app(PriceListWriter::class)->update((string) $list->id(), $this->data(['name' => 'Same']));
        app(PriceListWriter::class)->setActive((string) $list->id(), true);

        $this->assertSame([], $this->entries());
        Queue::assertNothingPushed();
        $this->assertSame([], $this->calls);
    }

    // ---- the audit -----------------------------------------------------------------------------------------------------

    /** ★ one entry per changed field, with readable values; none for the fields that stayed */
    public function test_the_audit_has_one_entry_per_changed_field(): void
    {
        $list = app(PriceListWriter::class)->create($this->data(['name' => 'Spring', 'percentage' => '30']));
        $this->assertSame([['action' => 'created', 'field' => null, 'old_value' => null, 'new_value' => null]], $this->entries());
        DB::table('activity_log')->delete();

        app(PriceListWriter::class)->update((string) $list->id(), $this->data(['name' => 'Spring 26', 'percentage' => '12.5', 'replaces_manual_sale' => true, 'valid_until' => '2030-05-01 18:30', 'is_active' => false, 'scopes' => [['type' => 'tag', 'reference' => '2'], ['type' => 'tag', 'reference' => '1']]]));

        $byField = [];

        foreach ($this->entries() as $entry) {
            $byField[$entry['field']] = [$entry['old_value'], $entry['new_value']];
        }

        $this->assertEqualsCanonicalizing(['name', 'percentage', 'replaces_manual_sale', 'valid_until', 'status', 'scopes'], array_keys($byField));
        $this->assertSame(['Spring', 'Spring 26'], $byField['name']);
        $this->assertSame(['30', '12.5'], $byField['percentage']);
        $this->assertSame(['0', '1'], $byField['replaces_manual_sale']);
        $this->assertSame(['active', 'inactive'], $byField['status']);
        $this->assertSame(['tag:1', 'tag:1, tag:2'], $byField['scopes']);
        $this->assertSame('', $byField['valid_until'][0]);
        $this->assertStringStartsWith('2030-05-01 18:30', $byField['valid_until'][1], 'in the shop time zone, to the minute');
    }

    // ---- the hooks -----------------------------------------------------------------------------------------------------

    /** ★ the four events fire after the commit with the right payloads */
    public function test_the_four_hooks_fire_after_the_commit_with_their_payloads(): void
    {
        foreach (['created', 'updated', 'activated', 'deactivated'] as $event) {
            $this->record('pricing.price_list.'.$event);
        }
        $baseline = DB::transactionLevel();

        $list = app(PriceListWriter::class)->create($this->data(['name' => 'Hooked']));
        $id = (string) $list->id();
        $this->assertSame(['pricing.price_list.created'], array_column($this->calls, 0));
        $this->assertSame($baseline, $this->calls[0][2], 'after the commit');
        $this->assertSame($id, $this->calls[0][1][0]->id());

        $this->calls = [];
        app(PriceListWriter::class)->update($id, $this->data(['name' => 'Hooked 2', 'percentage' => '40']));
        $this->assertSame(['pricing.price_list.updated'], array_column($this->calls, 0));
        $this->assertSame('Hooked', $this->calls[0][1][1]['name'], '$before carries the OLD values');
        $this->assertSame('30', $this->calls[0][1][1]['percentage']);

        $this->calls = [];
        app(PriceListWriter::class)->setActive($id, false);
        app(PriceListWriter::class)->setActive($id, true);
        $this->assertSame(['pricing.price_list.deactivated', 'pricing.price_list.activated'], array_column($this->calls, 0));

        // an update that flips the status fires `updated` AND the status hook, once each
        $this->calls = [];
        app(PriceListWriter::class)->update($id, $this->data(['name' => 'Hooked 2', 'percentage' => '40', 'is_active' => false]));
        $this->assertSame(['pricing.price_list.updated', 'pricing.price_list.deactivated'], array_column($this->calls, 0));
    }

    /** ★ a throwing listener neither breaks nor undoes the write */
    public function test_a_throwing_listener_does_not_break_or_undo_the_write(): void
    {
        foreach (['created', 'updated', 'deactivated'] as $event) {
            Hook::action('pricing.price_list.'.$event, fn () => throw new RuntimeException('listener blew up'));
        }

        $list = app(PriceListWriter::class)->create($this->data(['name' => 'Survives']));
        app(PriceListWriter::class)->update((string) $list->id(), $this->data(['name' => 'Survives 2', 'is_active' => false]));

        $model = PriceListModel::query()->findOrFail($list->id());
        $this->assertSame('Survives 2', $model->name);
        $this->assertSame('inactive', $model->status);
    }

    // ---- the validation ------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusals(): array
    {
        return [
            'percent 0' => [['percentage' => '0'], 'percentage'],
            'percent 100' => [['percentage' => '100'], 'percentage'],
            'percent negative' => [['percentage' => '-5'], 'percentage'],
            'percent text' => [['percentage' => 'abc'], 'percentage'],
            'percent 3 decimals' => [['percentage' => '10.123'], 'percentage'],
            'no name' => [['name' => '  '], 'name'],
            'end before start' => [['valid_from' => '2030-02-01 10:00', 'valid_until' => '2030-01-01 10:00'], 'valid_until'],
            'end equal to start' => [['valid_from' => '2030-02-01 10:00', 'valid_until' => '2030-02-01 10:00'], 'valid_until'],
            'not a date' => [['valid_from' => 'soon'], 'valid_from'],
            'a date past 2037 (the timestamp column)' => [['valid_until' => '2040-01-01 10:00'], 'valid_until'],
            'a scope that does not exist' => [['scopes' => [['type' => 'tag', 'reference' => '999']]], 'scopes'],
            'a customer-group scope' => [['scopes' => [['type' => 'customer_group', 'reference' => '1']]], 'scopes'],
            'an unknown scope kind' => [['scopes' => [['type' => 'galaxy', 'reference' => '1']]], 'scopes'],
            'no scope without the confirmation' => [['scopes' => []], 'confirm_all_products'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function test_a_bad_field_is_a_field_error_and_nothing_is_written(array $override, string $field): void
    {
        $before = PriceListModel::query()->count();

        try {
            app(PriceListWriter::class)->create($this->data($override));
            $this->fail('expected a refusal');
        } catch (PriceListInvalidException $exception) {
            $this->assertArrayHasKey($field, $exception->errors);
        }

        $this->assertSame($before, PriceListModel::query()->count());
        $this->assertSame([], $this->entries());
    }

    /** ★ an unscoped campaign IS created when the confirmation is given */
    public function test_an_unscoped_campaign_with_the_confirmation_is_created(): void
    {
        $list = app(PriceListWriter::class)->create($this->data(['scopes' => [], 'confirm_all_products' => true]));

        $this->assertSame(0, DB::table('pricing_price_list_scopes')->where('price_list_id', $list->id())->count());
        $this->assertSame(500, (int) PriceListModel::query()->findOrFail($list->id())->priority, 'the one campaign priority, below Manual Sale (1000)');
    }

    /** ★ a system list can never be changed through the writer */
    public function test_a_system_list_is_refused(): void
    {
        $manual = (string) PriceListModel::query()->where('name', 'Manual Sale')->value('id');
        $regular = (string) PriceListModel::query()->where('name', 'Regular Prices')->value('id');

        foreach ([$manual, $regular] as $id) {
            foreach ([fn () => app(PriceListWriter::class)->update($id, $this->data()), fn () => app(PriceListWriter::class)->setActive($id, false)] as $attempt) {
                try {
                    $attempt();
                    $this->fail('expected a refusal');
                } catch (PriceListInvalidException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        $this->assertSame('active', PriceListModel::query()->findOrFail($manual)->status);
    }

    /** the dates are typed in the SHOP time zone and stored in UTC */
    public function test_dates_are_shop_time_in_and_utc_stored(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');

        $list = app(PriceListWriter::class)->create($this->data(['valid_from' => '2030-07-01 12:00']));

        $this->assertSame('2030-07-01 09:00:00', DB::table('pricing_price_lists')->where('id', $list->id())->value('valid_from'), 'Sofia summer time is UTC+3');
    }
    /** the Hook Reference has the four rows (the rule: a new hook is a row in the same commit) */
    public function test_the_hook_reference_lists_the_four_price_list_hooks(): void
    {
        $doc = (string) file_get_contents(base_path('packages/EasyCo/documents/extensibility-design-and-hooks.md'));

        foreach (['created', 'updated', 'activated', 'deactivated'] as $event) {
            $this->assertStringContainsString('| `pricing.price_list.'.$event.'` | Action |', $doc, $event);
        }
    }
}
