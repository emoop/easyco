<?php

namespace Tests\Feature;

use App\Console\Commands\AssignMissingShippingClasses;
use App\Filament\Pages\ShippingOverview;
use App\Services\QuoteDestination;
use App\Services\ShippingClassAssigner;
use App\Services\ShippingClassMissingReader;
use App\Services\ShippingQuoteService;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5e: `php artisan shipping-classes:assign-missing` — gives a class to the variations that have none (NULL,
 * blank, or a code naming no class). Dry run is the default; --force writes through the domain in chunks, never
 * overwrites a valid class, is idempotent, and leaves ONE summary audit entry (plus one per variation up to 200)
 * and ONE hook per run.
 */
class AssignMissingShippingClassesCommandTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    /** @return array{0: int, 1: string} exit code and output */
    private function assign(array $options = []): array
    {
        $code = Artisan::call('shipping-classes:assign-missing', $options);

        return [$code, Artisan::output()];
    }

    private function classId(string $code, ?string $name = null, bool $default = false): string
    {
        $this->shippingClass($code, $name ?? ucfirst($code));
        $id = (string) app(ShippingClassRepository::class)->findByCode($code)->id();

        if ($default) {
            app(ShippingClassRepository::class)->markDefault($id);
        }

        return $id;
    }

    /** A SIMPLE product whose single variation carries the given stored class text (the domain accepts anything). */
    private function variation(?string $stored, ?string $price = null): string
    {
        self::$counter++;
        $n = self::$counter;
        $product = Product::createSimple("Missing Product {$n}", "MP-{$n}", "missing-product-{$n}");
        $product->variations()[0]->setShippingClass($stored);
        app(ProductRepository::class)->save($product);
        $id = (string) $product->variations()[0]->id();

        if ($price !== null) {
            $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
            app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $id, Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0)));
            app(StockLevelRepository::class)->save(StockLevel::forVariation($id, 100));
        }

        return $id;
    }

    private function stored(string $variationId): ?string
    {
        return DB::table('catalog_variations')->where('id', $variationId)->value('shipping_class');
    }

    /** NULL x2, blank x2, unknown x3 (free text, wrong case, HTML), valid x2. @return array<string, string> */
    private function legacyStore(): array
    {
        $ids = [
            'null1' => $this->variation(null),
            'null2' => $this->variation(null),
            'blank1' => $this->variation('   '),
            'blank2' => $this->variation(''),
            'free' => $this->variation('Fragile things'),
            'case' => $this->variation('Heavy'),
            'html' => $this->variation('<script>alert(1)</script>'),
            'valid1' => $this->variation('heavy'),
            'valid2' => $this->variation('light'),
        ];

        return $ids;
    }

    private function hookNames(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->hookCalls);
    }

    // ---- dry run is the default ---------------------------------------------------------------------------------

    public function test_without_force_nothing_is_written_and_the_report_counts_the_three_groups(): void
    {
        $this->activityLogOn();
        $this->classId('heavy');
        $this->classId('light');
        $this->classId('standard', 'Standard', default: true);
        $ids = $this->legacyStore();
        $this->spyOnClassHooks();
        $before = DB::table('catalog_variations')->orderBy('id')->pluck('shipping_class', 'id')->all();

        [$exit, $output] = $this->assign();

        $this->assertSame(0, $exit);
        $this->assertSame($before, DB::table('catalog_variations')->orderBy('id')->pluck('shipping_class', 'id')->all(), 'nothing written');
        $this->assertSame([], $this->hookCalls);
        $this->assertSame(0, DB::table('activity_log')->where('field', 'assign_missing')->count());
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Class: Standard (standard)', $output);
        $this->assertStringContainsString('Variations without a usable class: 7  [NULL: 2, blank: 2, a code naming no class: 3]', $output);
        $this->assertStringContainsString('Would give the class to 7 variation(s).', $output);
        $this->assertCount(9, $ids);
    }

    public function test_dry_run_flag_wins_over_force(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $id = $this->variation(null);

        [$exit, $output] = $this->assign(['--force' => true, '--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertNull($this->stored($id));
        $this->assertStringContainsString('this is a dry run', $output);
    }

    public function test_the_sample_lists_twenty_with_sku_name_and_the_stored_text_neutralised_and_truncated(): void
    {
        $this->classId('standard', 'Standard', default: true);

        for ($i = 0; $i < 22; $i++) {
            $this->variation(null);
        }

        $long = $this->variation(str_repeat('x', 255));
        $control = $this->variation("bad\x1b[31mred\x07");
        $html = $this->variation('<img src=x onerror=alert(1)>');

        [, $output] = $this->assign();

        $this->assertStringContainsString('First 20:', $output);
        $this->assertSame(20, substr_count($output, "\n  #"), 'twenty sample lines');
        $this->assertStringNotContainsString("\x1b", $output, 'no escape character reaches the console');
        $this->assertStringNotContainsString("\x07", $output);
        $this->assertLessThan(3000, strlen($output));

        // the long and the control-character rows are past the first twenty ids? put them first by reading the sample directly
        DB::table('catalog_variations')->whereNotIn('id', [$long, $control, $html])->update(['shipping_class' => 'ok-filler']);
        DB::table('shipping_classes')->insert(['code' => 'ok-filler', 'name' => 'Filler', 'created_at' => now(), 'updated_at' => now()]);
        [, $second] = $this->assign();

        $this->assertStringContainsString('(255 characters)', $second);
        $this->assertStringContainsString('…', $second);
        $this->assertStringNotContainsString(str_repeat('x', 100), $second, 'truncated');
        $this->assertStringContainsString('bad?[31mred?', $second, 'control characters are shown as ?');
        $this->assertStringContainsString('<img src=x onerror=alert(1)>', $second, 'HTML is shown as plain text — a console interprets nothing');
        $this->assertStringContainsString('MP-', $second);
    }

    // ---- writing ------------------------------------------------------------------------------------------------

    public function test_force_gives_the_class_to_every_missing_variation_and_never_touches_a_valid_one(): void
    {
        $this->activityLogOn();
        $this->classId('heavy');
        $this->classId('light');
        $this->classId('standard', 'Standard', default: true);
        $ids = $this->legacyStore();
        $this->spyOnClassHooks();

        [$exit, $output] = $this->assign(['--force' => true]);

        $this->assertSame(0, $exit);

        foreach (['null1', 'null2', 'blank1', 'blank2', 'free', 'case', 'html'] as $key) {
            $this->assertSame('standard', $this->stored($ids[$key]), $key);
        }

        $this->assertSame('heavy', $this->stored($ids['valid1']), 'a valid class is never overwritten');
        $this->assertSame('light', $this->stored($ids['valid2']));
        $this->assertStringContainsString('7 assigned', $output);
        $this->assertSame(0, app(ShippingClassMissingReader::class)->total());
    }

    public function test_the_audit_rule_one_summary_entry_plus_one_per_variation_up_to_200(): void
    {
        $this->activityLogOn();
        $this->classId('heavy');
        $this->classId('light');
        $standard = $this->classId('standard', 'Standard', default: true);
        $ids = $this->legacyStore();

        $this->assign(['--force' => true]);

        $summary = DB::table('activity_log')->where('entity_type', 'shipping_class')->where('field', 'assign_missing')->get();
        $this->assertCount(1, $summary, 'one summary entry per run');
        $this->assertSame($standard, $summary[0]->entity_id);
        $payload = json_decode($summary[0]->new_value, true);
        $this->assertSame(['class' => 'standard', 'count' => 7, 'skipped' => 0, 'groups' => ['null' => 2, 'blank' => 2, 'unknown' => 3]], $payload);

        $perVariation = DB::table('activity_log')->where('entity_type', 'product')->where('field', 'like', 'variation[%].shipping_class')->get();
        $this->assertCount(7, $perVariation, 'at most 200 planned: one entry per changed variation too');

        $row = $perVariation->firstWhere('field', "variation[{$ids['free']}].shipping_class");
        $this->assertSame(['Fragile things', 'standard'], [$row->old_value, $row->new_value]);
    }

    public function test_above_200_planned_only_the_summary_entry_is_written(): void
    {
        $this->activityLogOn();
        $this->classId('standard', 'Standard', default: true);

        for ($i = 0; $i < AssignMissingShippingClasses::PER_VARIATION_AUDIT_MAX + 1; $i++) {
            $this->variation(null);
        }

        [$exit, $output] = $this->assign(['--force' => true, '--chunk' => 100]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, app(ShippingClassMissingReader::class)->total());
        $this->assertSame(0, DB::table('activity_log')->where('field', 'like', 'variation[%].shipping_class')->count(), 'too many for one entry each');
        $this->assertSame(1, DB::table('activity_log')->where('field', 'assign_missing')->count());
        $this->assertSame(201, json_decode(DB::table('activity_log')->where('field', 'assign_missing')->value('new_value'), true)['count']);
        $this->assertStringContainsString('the summary audit entry only', $output);
        $this->assertSame(3, substr_count($output, '  chunk of '), '201 variations in chunks of 100 are three transactions');
    }

    public function test_one_bulk_hook_fires_once_after_the_run_with_the_class_and_the_count(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $this->variation(null);
        $this->variation('nonsense');
        $baseline = $this->spyOnClassHooks();

        $this->assign(['--force' => true]);

        $this->assertSame(['shipping.class.assigned_bulk'], $this->hookNames(), 'one hook, no per-variation hook');
        $this->assertSame(['standard', 2], $this->hookCalls[0][1]);
        $this->assertSame($baseline, $this->hookCalls[0][2]);
    }

    public function test_a_second_run_assigns_nothing_and_writes_no_audit_entry_and_fires_no_hook(): void
    {
        $this->activityLogOn();
        $this->classId('standard', 'Standard', default: true);
        $this->legacyStore();
        $this->assign(['--force' => true]);
        $auditBefore = DB::table('activity_log')->count();
        $this->spyOnClassHooks();

        [$exit, $output] = $this->assign(['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Nothing to assign.', $output);
        $this->assertSame($auditBefore, DB::table('activity_log')->count());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_variation_another_process_already_fixed_is_skipped_never_overwritten(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $heavy = $this->classId('heavy');
        $a = $this->variation(null);
        $b = $this->variation(null);

        // the chunk was planned with both, but between the planning and the write someone gave $b a valid class
        DB::table('catalog_variations')->where('id', $b)->update(['shipping_class' => 'heavy']);

        $result = app(ShippingClassAssigner::class)->assignMissing([$a, $b], (string) DB::table('shipping_classes')->where('code', 'standard')->value('id'), false);

        $this->assertSame(1, $result['assigned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('standard', $this->stored($a));
        $this->assertSame('heavy', $this->stored($b), 'a valid class is never overwritten, even by a stale plan');
        $this->assertNotSame('', $heavy);
    }

    public function test_limit_and_chunk_cap_the_run_and_a_rerun_continues(): void
    {
        $this->classId('standard', 'Standard', default: true);

        for ($i = 0; $i < 5; $i++) {
            $this->variation(null);
        }

        [, $output] = $this->assign(['--force' => true, '--limit' => 3, '--chunk' => 2]);

        $this->assertSame(3, DB::table('catalog_variations')->where('shipping_class', 'standard')->count());
        $this->assertSame(2, app(ShippingClassMissingReader::class)->total());
        $this->assertSame(2, substr_count($output, '  chunk of '), '3 in chunks of 2: a chunk of 2 and a chunk of 1');
        $this->assertStringContainsString('largest chunk 2', $output);

        $this->assign(['--force' => true]);
        $this->assertSame(0, app(ShippingClassMissingReader::class)->total());
    }

    public function test_archived_variations_are_included_and_only_their_class_changes(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $definition = new \EasyCo\Catalog\AttributeDefinition(id: null, code: 'color', name: 'Color', type: \EasyCo\Catalog\Enums\AttributeType::SELECT);
        app(\EasyCo\Catalog\Contracts\AttributeDefinitionRepository::class)->save($definition);
        $black = new \EasyCo\Catalog\AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(\EasyCo\Catalog\Contracts\AttributeValueRepository::class)->save($black);
        $white = new \EasyCo\Catalog\AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(\EasyCo\Catalog\Contracts\AttributeValueRepository::class)->save($white);

        $product = Product::createVariable('Archived Shirt', 'ARCH', 'archived-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $live = $product->addStandardVariation([$definition->id() => $black->id()], 'ARCH-BLACK');
        $live->activate();
        $archived = $product->addStandardVariation([$definition->id() => $white->id()], 'ARCH-WHITE');
        $archived->activate();
        $archived->archive();
        app(ProductRepository::class)->save($product);
        $archivedId = (string) $archived->id();
        $before = (array) DB::table('catalog_variations')->where('id', $archivedId)->first();

        $this->assign(['--force' => true]);

        $after = (array) DB::table('catalog_variations')->where('id', $archivedId)->first();
        $this->assertSame('archived', $after['status']);
        $this->assertSame('standard', $after['shipping_class']);
        unset($before['shipping_class'], $after['shipping_class'], $before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after, 'nothing but the class text changed');
        $this->assertSame('standard', $this->stored((string) $live->id()));
    }

    // ---- which class -----------------------------------------------------------------------------------------------

    public function test_the_class_option_wins_over_the_default(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $this->classId('heavy', 'Heavy');
        $id = $this->variation(null);

        [$exit] = $this->assign(['--force' => true, '--class' => 'heavy']);

        $this->assertSame(0, $exit);
        $this->assertSame('heavy', $this->stored($id));
    }

    public function test_an_unknown_class_refuses_with_a_non_zero_exit_and_changes_nothing(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $id = $this->variation(null);
        $this->spyOnClassHooks();

        [$exit, $output] = $this->assign(['--force' => true, '--class' => 'nope<script>']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('There is no shipping class with the code', $output);
        $this->assertStringContainsString('Nothing was changed.', $output);
        $this->assertStringNotContainsString('SQLSTATE', $output);
        $this->assertNull($this->stored($id));
        $this->assertSame([], $this->hookCalls);
    }

    public function test_with_classes_but_no_default_and_no_class_option_it_stops(): void
    {
        $this->classId('heavy');
        $id = $this->variation(null);

        [$exit, $output] = $this->assign(['--force' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no default class', $output);
        $this->assertNull($this->stored($id));
    }

    public function test_with_no_class_at_all_it_stops_unless_create_default_is_given(): void
    {
        $id = $this->variation(null);

        [$exit, $output] = $this->assign(['--force' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--create-default', $output);
        $this->assertSame(0, DB::table('shipping_classes')->count());
        $this->assertNull($this->stored($id));
    }

    public function test_create_default_creates_the_standard_class_only_on_an_empty_store_and_assigns_it(): void
    {
        $id = $this->variation(null);
        $this->spyOnClassHooks();

        // a dry run creates nothing
        [$exit, $output] = $this->assign(['--create-default' => true]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('would be created', $output);
        $this->assertSame(0, DB::table('shipping_classes')->count());

        [$exit] = $this->assign(['--force' => true, '--create-default' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(['standard'], DB::table('shipping_classes')->where('is_default', 1)->pluck('code')->all());
        $this->assertSame('standard', $this->stored($id));
        $this->assertContains('shipping.class.created', $this->hookNames());
        $this->assertContains('shipping.class.assigned_bulk', $this->hookNames());
    }

    public function test_create_default_takes_a_name_and_the_store_locale_names_the_standard_class(): void
    {
        $this->variation(null);

        $this->assign(['--force' => true, '--create-default' => 'Normal parcels']);

        $this->assertSame('Normal parcels', DB::table('shipping_classes')->value('name'));
    }

    public function test_create_default_is_ignored_with_a_warning_when_classes_exist(): void
    {
        $this->classId('heavy', 'Heavy');
        $standard = $this->classId('standard', 'Standard', default: true);
        $id = $this->variation(null);

        [$exit, $output] = $this->assign(['--force' => true, '--create-default' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('--create-default is ignored', $output);
        $this->assertSame(2, DB::table('shipping_classes')->count(), 'no class was created');
        $this->assertSame('standard', $this->stored($id));
        $this->assertNotSame('', $standard);
    }

    public function test_create_default_never_runs_when_a_named_class_does_not_exist_but_others_do(): void
    {
        $this->classId('heavy', 'Heavy');
        $this->variation(null);

        [$exit] = $this->assign(['--force' => true, '--create-default' => true, '--class' => 'ghost']);

        $this->assertSame(1, $exit);
        $this->assertSame(1, DB::table('shipping_classes')->count());
    }

    public function test_bad_numbers_are_refused_before_anything_is_read(): void
    {
        $this->classId('standard', 'Standard', default: true);
        $id = $this->variation(null);

        foreach ([['--limit' => 'abc'], ['--limit' => '0'], ['--chunk' => '0'], ['--chunk' => '5000'], ['--chunk' => 'x']] as $options) {
            [$exit, $output] = $this->assign(array_merge(['--force' => true], $options));

            $this->assertSame(1, $exit, json_encode($options));
            $this->assertStringContainsString('Nothing was changed.', $output);
        }

        $this->assertNull($this->stored($id));
    }

    // ---- the quote -------------------------------------------------------------------------------------------------

    public function test_a_variation_that_had_no_class_quotes_with_the_class_after_the_command(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->perClassMethod($zone, 'Per class', ['standard' => 900], base: 400);
        $variationId = $this->variation(null, '10.00');

        $quote = function () use ($variationId): array {
            $cart = Cart::forGuest((string) Str::uuid(), new \DateTimeImmutable('+10 days'));
            app(CartRepository::class)->save($cart);
            app(CartLineAdder::class)->addLine($cart, $variationId, 1, null, null);
            $cart = app(CartRepository::class)->findById($cart->id());
            $result = app(ShippingQuoteService::class)->quote($cart, null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia'));

            return array_map(fn ($method) => $method->amountMinor, $result->methods);
        };

        $this->assertSame([400], $quote(), 'a classless variation still quotes, with the base amount (the domain stays permissive)');

        $this->assign(['--force' => true]);

        $this->assertSame([900], $quote(), 'after the command the class amount applies');
        $this->assertNotSame('', $standard);
    }

    // ---- the health line ----------------------------------------------------------------------------------------------

    public function test_the_overview_shows_how_many_variations_have_no_class_with_the_command_and_hides_the_line_at_zero(): void
    {
        $this->actingAsStaff('Administrator');

        $this->assertStringNotContainsString('shipping class.', html_entity_decode((string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent()), 'zero: no line');

        $this->variation(null);
        $this->variation('nonsense');
        $html = html_entity_decode((string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent());

        $this->assertStringContainsString('2 variations have no shipping class.', $html);
        $this->assertStringContainsString('php artisan shipping-classes:assign-missing', $html);
        $this->assertStringContainsString('/admin/help/shipping#action-class-migration', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);

        DB::table('catalog_variations')->limit(1)->update(['shipping_class' => 'x']);
        $this->variation('heavy-unknown');
        App::setLocale('bg');
        $bg = html_entity_decode((string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent());
        $this->assertStringContainsString('нямат клас за доставка', $bg);
    }

    public function test_the_health_line_costs_one_count_query_and_no_write_button_exists(): void
    {
        $this->actingAsStaff('Administrator');
        $this->variation(null);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'catalog_variations')) {
                $queries++;
            }
        });

        $html = Livewire::test(ShippingOverview::class)->html();

        $this->assertSame(1, $queries, 'one grouped count over the variations, not one per row');
        $this->assertStringNotContainsString('wire:click="assign', $html);
        fwrite(STDERR, sprintf("\n[query-count] shipping overview class health line: %d catalog_variations query\n", $queries));
    }
}
