<?php

namespace Tests\Feature;

use App\Filament\Resources\PromotionResource;
use App\Filament\Resources\PromotionResource\Pages\CreatePromotion;
use App\Filament\Resources\PromotionResource\Pages\EditPromotion;
use App\Filament\Resources\PromotionResource\Pages\ListPromotions;
use App\Services\Exceptions\PromotionInvalidException;
use App\Services\PromotionAdminReader;
use App\Services\PromotionWriter;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
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
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P5b: the promotion FORM: create, edit (what is editable and what is not), the field errors, the money / percent round trip, the scopes, «Дублирай», the status switch, the public switch with its amber warning,
 * and the end-to-end link to the cart's public list (P5). Every write goes through PromotionWriter.
 */
class PromotionResourceFormTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function state(array $over = []): array
    {
        return $over + ['code' => 'FRM'.(++self::$n), 'discount_type' => 'percentage', 'percentage' => '10', 'is_active' => true, 'scopes' => []];
    }

    private function make(array $over = []): PromotionModel
    {
        $promotion = app(PromotionWriter::class)->create($this->state($over));

        return PromotionModel::query()->findOrFail($promotion->id());
    }

    private function brand(string $name = 'Nike'): string
    {
        $brand = new Brand(id: null, name: $name, slug: strtolower($name).(++self::$n));
        app(BrandRepository::class)->save($brand);

        return (string) $brand->id();
    }

    private function createPage()
    {
        $this->actingAsAdministrator();

        return Livewire::test(CreatePromotion::class);
    }

    // ---- create ------------------------------------------------------------------------------------------------

    /** ★ the typed percent and money are stored exactly: 12.5 % => 1250 basis points, 79.99 => 7999 minor units */
    public function test_percent_and_money_round_trip_exactly(): void
    {
        $this->createPage()
            ->fillForm(['code' => 'Round1', 'discount_type' => 'percentage', 'percentage' => '12,5', 'minimum_spend' => '79.99', 'maximum_spend' => '500', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = PromotionModel::query()->where('code', 'round1')->firstOrFail();
        $this->assertSame(1250, (int) $row->discount_percentage_basis_points);
        $this->assertSame(7999, (int) $row->minimum_spend_amount_minor);
        $this->assertSame(50000, (int) $row->maximum_spend_amount_minor);

        $this->assertSame(1250, PromotionWriter::parsePercent('12.50'));
        $this->assertSame(1, PromotionWriter::parsePercent('0.01'));
        $this->assertSame(10000, PromotionWriter::parsePercent('100'));
        foreach (['0', '100.01', '12.555', 'abc', '', '1e2', '-5'] as $bad) {
            $this->assertNull(PromotionWriter::parsePercent($bad), $bad);
        }
    }

    /** ★ the form stores what the API would store for the same input */
    public function test_the_stored_values_equal_what_the_api_stores_for_the_same_input(): void
    {
        $this->actingAsAdministrator();
        $this->postJson('/api/promotions', ['code' => 'viaapi', 'discount_type' => 'percentage', 'percentage_basis_points' => 1250, 'minimum_spend' => '79.99', 'maximum_spend' => '500.00', 'usage_limit_total' => 7, 'valid_until' => '2031-01-01 00:00:00', 'new_customers_only' => true])->assertStatus(201);

        Livewire::test(CreatePromotion::class)
            ->fillForm(['code' => 'viaform', 'discount_type' => 'percentage', 'percentage' => '12.5', 'minimum_spend' => '79.99', 'maximum_spend' => '500.00', 'usage_limit_total' => '7', 'valid_until' => '2031-01-01 00:00:00', 'new_customers_only' => true])
            ->call('create')->assertHasNoFormErrors();

        $columns = ['discount_type', 'discount_percentage_basis_points', 'minimum_spend_amount_minor', 'minimum_spend_amount_currency', 'maximum_spend_amount_minor', 'usage_limit_total', 'valid_until', 'new_customers_only', 'status', 'is_public'];
        $api = PromotionModel::query()->where('code', 'viaapi')->first($columns)->toArray();
        $form = PromotionModel::query()->where('code', 'viaform')->first($columns)->toArray();

        $this->assertSame($api, $form);
    }

    public function test_a_fixed_amount_code_is_created_in_minor_units(): void
    {
        $this->createPage()->fillForm(['code' => 'five', 'discount_type' => 'fixed_amount', 'discount_amount' => '5,50'])->call('create')->assertHasNoFormErrors();

        $row = PromotionModel::query()->where('code', 'five')->firstOrFail();
        $this->assertSame([550, 'EUR', null], [(int) $row->discount_amount_minor, $row->discount_amount_currency, $row->discount_percentage_basis_points]);
    }

    /** ★ the domain's rules are field errors under the right field */
    public function test_invalid_combinations_are_field_errors(): void
    {
        $page = $this->createPage();

        $page->fillForm(['code' => 'bad1', 'discount_type' => 'percentage', 'percentage' => '10', 'minimum_spend' => '100', 'maximum_spend' => '50'])->call('create')->assertHasFormErrors(['maximum_spend']);
        $page->fillForm(['maximum_spend' => '', 'valid_from' => '2030-02-01 00:00:00', 'valid_until' => '2030-01-01 00:00:00'])->call('create')->assertHasFormErrors(['valid_until']);
        $page->fillForm(['valid_from' => null, 'valid_until' => null, 'percentage' => '0'])->call('create')->assertHasFormErrors(['percentage']);
        $page->fillForm(['percentage' => '10', 'minimum_spend' => '10.999'])->call('create')->assertHasFormErrors(['minimum_spend']);
        $page->fillForm(['minimum_spend' => '', 'usage_limit_total' => '0'])->call('create')->assertHasFormErrors(['usage_limit_total']);
        $page->fillForm(['usage_limit_total' => '', 'code' => ''])->call('create')->assertHasFormErrors(['code']);

        $this->assertSame(0, PromotionModel::query()->count(), 'nothing was saved');
    }

    public function test_a_duplicate_code_is_a_field_error_not_a_500(): void
    {
        $this->make(['code' => 'taken']);

        $this->createPage()->fillForm(['code' => 'TAKEN', 'discount_type' => 'percentage', 'percentage' => '5'])->call('create')->assertHasFormErrors(['code']);
        $this->assertSame(1, PromotionModel::query()->count());
    }

    /** ★ a public promotion without a description cannot be saved; the 120-character limit; a non-public one keeps none */
    public function test_the_public_rules_on_the_form(): void
    {
        $page = $this->createPage();
        $base = ['code' => 'pub1', 'discount_type' => 'percentage', 'percentage' => '5', 'is_public' => true];

        $page->fillForm($base + ['public_description' => ''])->call('create')->assertHasFormErrors(['public_description']);
        $page->fillForm(['public_description' => str_repeat('я', 121)])->call('create')->assertHasFormErrors(['public_description']);
        $this->assertSame(0, PromotionModel::query()->count());

        $page->fillForm(['public_description' => str_repeat('я', 120)])->call('create')->assertHasNoFormErrors();
        $this->assertTrue((bool) PromotionModel::query()->where('code', 'pub1')->value('is_public'));

        $this->createPage()->fillForm(['code' => 'quiet1', 'discount_type' => 'percentage', 'percentage' => '5', 'is_public' => false, 'public_description' => 'should vanish'])->call('create')->assertHasNoFormErrors();
        $quiet = PromotionModel::query()->where('code', 'quiet1')->firstOrFail();
        $this->assertFalse((bool) $quiet->is_public);
        $this->assertNull($quiet->public_description);
    }

    /** ★ a <script> description is stored as text and escaped everywhere it is shown (the admin list and edit page; the cart list is covered by PublicPromotionsTest) */
    public function test_a_script_description_is_stored_as_text_and_escaped(): void
    {
        $this->createPage()->fillForm(['code' => 'xss', 'discount_type' => 'percentage', 'percentage' => '5', 'is_public' => true, 'public_description' => '<script>alert(1)</script>', 'minimum_spend' => '1'])->call('create')->assertHasNoFormErrors();

        $row = PromotionModel::query()->where('code', 'xss')->firstOrFail();
        $this->assertSame('<script>alert(1)</script>', $row->public_description);

        $this->assertStringNotContainsString('<script>alert(1)</script>', Livewire::test(EditPromotion::class, ['record' => $row->id])->html());
        $this->assertStringNotContainsString('<script>alert(1)</script>', Livewire::test(ListPromotions::class)->html());
    }

    // ---- the amber warning ------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>}> one condition each */
    public static function conditions(): array
    {
        return [
            'minimum spend' => [['minimum_spend' => '10']],
            'new customers only' => [['new_customers_only' => true]],
            'valid until' => [['valid_until' => '2031-01-01 00:00:00']],
            'total limit' => [['usage_limit_total' => '5']],
            'per customer limit' => [['usage_limit_per_customer' => '1']],
            'items limit' => [['usage_limit_items' => '3']],
            'a scope' => [['scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => 'BRAND']]]],
        ];
    }

    private function warningShown(array $state, bool $public = true): bool
    {
        $this->actingAsAdministrator();
        $state = json_decode(str_replace('"BRAND"', json_encode($this->brand()), json_encode($state)), true);

        return str_contains(Livewire::test(CreatePromotion::class)
            ->fillForm(['code' => 'w', 'discount_type' => 'percentage', 'percentage' => '5', 'is_public' => $public, 'public_description' => 'x'] + $state)
            ->html(), 'data-promotion-public-warning');
    }

    /** ★ the warning shows ONLY for a public promotion with no condition at all */
    public function test_the_warning_shows_only_for_a_public_code_without_any_condition(): void
    {
        $this->assertTrue($this->warningShown([]));
        $this->assertFalse($this->warningShown([], public: false), 'a non-public code never shows it');
    }

    /** ★ each kind of condition removes the warning */
    #[DataProvider('conditions')]
    public function test_any_one_condition_removes_the_warning(array $condition): void
    {
        $this->assertFalse($this->warningShown($condition));
    }

    public function test_the_warning_helper_agrees_with_the_definition_of_a_condition(): void
    {
        $this->assertFalse(PromotionResource::hasAnyCondition('', [], false, null, [null, '', null]));
        $this->assertTrue(PromotionResource::hasAnyCondition('1', [], false, null, []));
        $this->assertTrue(PromotionResource::hasAnyCondition(null, [['type' => 'brand']], false, null, []));
        $this->assertTrue(PromotionResource::hasAnyCondition(null, [], true, null, []));
        $this->assertTrue(PromotionResource::hasAnyCondition(null, [], false, '2031-01-01', []));
        $this->assertTrue(PromotionResource::hasAnyCondition(null, [], false, null, [null, '5']));
    }

    /** the create page opens with the form's own defaults (a percentage code, switched on): an empty fill would skip them */
    public function test_the_create_page_opens_with_the_defaults(): void
    {
        $this->actingAsAdministrator();

        Livewire::test(CreatePromotion::class)->assertFormSet(['discount_type' => 'percentage', 'is_active' => true]);
    }

    // ---- edit ---------------------------------------------------------------------------------------------------

    public function test_the_edit_page_shows_the_stored_values(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make(['code' => 'stored', 'percentage' => '12.5', 'minimum_spend' => '79.99', 'usage_limit_total' => '3', 'is_public' => true, 'public_description' => 'Hello']);

        Livewire::test(EditPromotion::class, ['record' => $row->id])
            ->assertFormSet(['code' => 'stored', 'discount_type' => 'percentage', 'percentage' => '12.5', 'minimum_spend' => '79.99', 'usage_limit_total' => 3, 'is_public' => true, 'public_description' => 'Hello', 'is_active' => true]);
    }

    /** ★ the editable fields save; the code, the type and the value cannot be changed (a tampered payload is ignored) */
    public function test_edit_changes_the_editable_fields_and_never_the_code_type_or_value(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make(['code' => 'fixedcode', 'percentage' => '10']);

        Livewire::test(EditPromotion::class, ['record' => $row->id])
            ->fillForm([
                'code' => 'hacked', 'discount_type' => 'fixed_amount', 'percentage' => '99', 'discount_amount' => '50',
                'minimum_spend' => '20', 'exclude_sale_items' => true, 'individual_use_only' => true, 'usage_limit_per_customer' => '2', 'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = PromotionModel::query()->findOrFail($row->id);
        $this->assertSame('fixedcode', $fresh->code);
        $this->assertSame('percentage', $fresh->discount_type);
        $this->assertSame(1000, (int) $fresh->discount_percentage_basis_points);
        $this->assertNull($fresh->discount_amount_minor);
        $this->assertSame([2000, true, true, 2, 'inactive'], [(int) $fresh->minimum_spend_amount_minor, (bool) $fresh->exclude_sale_items, (bool) $fresh->individual_use_only, (int) $fresh->usage_limit_per_customer, $fresh->status]);

        // and straight at the writer: the same keys are ignored
        app(PromotionWriter::class)->update((string) $row->id, ['code' => 'again', 'discount_type' => 'fixed_amount', 'discount_amount' => '9', 'percentage' => '50', 'is_active' => true, 'scopes' => []]);
        $after = PromotionModel::query()->findOrFail($row->id);
        $this->assertSame(['fixedcode', 'percentage', 1000], [$after->code, $after->discount_type, (int) $after->discount_percentage_basis_points]);
    }

    public function test_the_edit_page_shows_the_immutable_fields_read_only(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make(['code' => 'lockme']);

        Livewire::test(EditPromotion::class, ['record' => $row->id])
            ->assertFormFieldIsDisabled('code')
            ->assertFormFieldIsDisabled('discount_type')
            ->assertFormFieldIsDisabled('percentage');

        Livewire::test(CreatePromotion::class)
            ->assertFormFieldIsEnabled('code')
            ->assertFormFieldIsEnabled('discount_type');
    }

    public function test_edit_field_errors(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make(['code' => 'editerr']);

        Livewire::test(EditPromotion::class, ['record' => $row->id])->fillForm(['minimum_spend' => '100', 'maximum_spend' => '100'])->call('save')->assertHasFormErrors(['maximum_spend']);
        Livewire::test(EditPromotion::class, ['record' => $row->id])->fillForm(['is_public' => true, 'public_description' => ''])->call('save')->assertHasFormErrors(['public_description']);
        $this->assertNull(PromotionModel::query()->find($row->id)->minimum_spend_amount_minor, 'a refused save changes nothing');
    }

    // ---- scopes -----------------------------------------------------------------------------------------------

    /** @return list<string> */
    private function scopeKeys(PromotionModel $row): array
    {
        return PromotionScopeModel::query()->where('promotion_id', $row->id)->orderBy('id')->get()
            ->map(fn (PromotionScopeModel $s) => $s->scope_type.'|'.$s->mode.'|'.$s->scope_reference_id)->all();
    }

    /** ★ scopes are saved as typed (include / exclude per type) and an ACCOUNT scope survives a save */
    public function test_scopes_are_saved_as_typed_and_an_account_scope_survives(): void
    {
        $this->actingAsAdministrator();
        $brand = $this->brand('Adidas');
        $other = $this->brand('Puma');
        $row = $this->make(['code' => 'scoped', 'scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $brand]]]);
        app(PromotionScopeRepository::class)->attach(new PromotionScope(null, (string) $row->id, PromotionScopeType::ACCOUNT, 'acct-1', PromotionScopeMode::INCLUDE));

        $this->assertSame(['brand|include|'.$brand, 'account|include|acct-1'], $this->scopeKeys($row));

        Livewire::test(EditPromotion::class, ['record' => $row->id])
            ->fillForm(['scopes' => [
                ['type' => 'brand', 'mode' => 'include', 'reference' => $brand],
                ['type' => 'brand', 'mode' => 'exclude', 'reference' => $other],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(['brand|include|'.$brand, 'brand|exclude|'.$other, 'account|include|acct-1'], $this->scopeKeys($row));

        Livewire::test(EditPromotion::class, ['record' => $row->id])->fillForm(['scopes' => []])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['account|include|acct-1'], $this->scopeKeys($row), 'removing every offered scope leaves the ACCOUNT one');
    }

    public function test_every_offered_scope_type_can_be_saved_and_a_made_up_one_cannot(): void
    {
        $this->actingAsAdministrator();
        $writer = app(PromotionWriter::class);
        $product = DB::table('catalog_products')->insertGetId(['type' => 'simple', 'name' => 'Scoped product', 'slug' => 'scoped-product', 'base_sku' => 'SCP-1', 'status' => 'active', 'catalog_visibility' => 'visible', 'timeline_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $definition = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'scp-colour', 'name' => 'Colour', 'type' => 'select', 'is_filterable' => 0, 'filter_display' => 'list', 'created_at' => now(), 'updated_at' => now()]);
        $ids = [
            'brand' => $this->brand(),
            'category' => (string) DB::table('catalog_categories')->insertGetId(['parent_id' => null, 'name' => 'Cat', 'slug' => 'cat-x', 'created_at' => now(), 'updated_at' => now()]),
            'tag' => (string) DB::table('catalog_tags')->insertGetId(['name' => 'Tag', 'slug' => 'tag-x', 'created_at' => now(), 'updated_at' => now()]),
            'product' => (string) $product,
            'attribute_value' => (string) DB::table('catalog_attribute_values')->insertGetId(['attribute_definition_id' => $definition, 'value' => 'Red', 'sort_order' => 0, 'colour' => null, 'created_at' => now(), 'updated_at' => now()]),
        ];
        $scopes = [];

        foreach ($ids as $type => $id) {
            $scopes[] = ['type' => $type, 'mode' => 'exclude', 'reference' => $id];
        }

        $promotion = $writer->create($this->state(['scopes' => $scopes]));
        $this->assertCount(5, PromotionScopeModel::query()->where('promotion_id', $promotion->id())->get());

        foreach ([
            [['type' => 'account', 'mode' => 'include', 'reference' => 'x']],
            [['type' => 'season', 'mode' => 'include', 'reference' => '1']],
            [['type' => 'brand', 'mode' => 'include', 'reference' => '99999']],
            [['type' => 'brand', 'mode' => 'maybe', 'reference' => $ids['brand']]],
        ] as $bad) {
            try {
                $writer->create($this->state(['scopes' => $bad]));
                $this->fail('expected a refusal');
            } catch (PromotionInvalidException $exception) {
                $this->assertArrayHasKey('scopes', $exception->errors);
            }
        }
    }

    public function test_the_scope_search_returns_at_most_twenty_results(): void
    {
        for ($i = 0; $i < 30; $i++) {
            DB::table('catalog_products')->insert(['type' => 'simple', 'name' => 'Searchable '.$i, 'slug' => 'searchable-'.$i, 'base_sku' => 'SRCH-'.$i, 'status' => 'active', 'catalog_visibility' => 'visible', 'timeline_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        $reader = app(PromotionAdminReader::class);

        $this->assertCount(20, $reader->searchOptions(PromotionScopeType::PRODUCT, 'Searchable'));
        $this->assertCount(1, $reader->searchOptions(PromotionScopeType::PRODUCT, 'SRCH-7'));
        $this->assertSame([], $reader->searchOptions(PromotionScopeType::ACCOUNT, 'x'));
    }

    // ---- duplicate, status --------------------------------------------------------------------------------------

    public function test_duplicate_opens_the_create_form_prefilled_with_the_code_left_empty(): void
    {
        $this->actingAsAdministrator();
        $brand = $this->brand();
        $row = $this->make(['code' => 'original', 'percentage' => '15', 'minimum_spend' => '30', 'is_active' => false, 'scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $brand]]]);

        Livewire::withQueryParams(['duplicate' => $row->id])->test(CreatePromotion::class)
            ->assertFormSet(['code' => '', 'discount_type' => 'percentage', 'percentage' => '15', 'minimum_spend' => '30.00', 'is_active' => true])
            ->fillForm(['code' => 'the-copy'])
            ->call('create')
            ->assertHasNoFormErrors();

        $copy = PromotionModel::query()->where('code', 'the-copy')->firstOrFail();
        $this->assertSame(1500, (int) $copy->discount_percentage_basis_points);
        $this->assertSame(['brand|include|'.$brand], $this->scopeKeys($copy));
        $this->assertSame('original', PromotionModel::query()->find($row->id)->code, 'the source is untouched');
    }

    public function test_the_status_action_switches_a_promotion_off_and_on(): void
    {
        $this->actingAsAdministrator();
        $row = $this->make(['code' => 'flip']);

        Livewire::test(ListPromotions::class)->callTableAction('toggle_promotion', $row);
        $this->assertSame('inactive', PromotionModel::query()->find($row->id)->status);

        Livewire::test(ListPromotions::class)->callTableAction('toggle_promotion', $row);
        $this->assertSame('active', PromotionModel::query()->find($row->id)->status);
    }

    // ---- end to end with the cart's public list (P5) ---------------------------------------------------------------

    /** ★ a promotion made in the admin with «Публичен» on appears in the cart's list with the right state; made non-public it disappears */
    public function test_a_public_promotion_made_in_the_admin_shows_in_the_cart_list_and_leaves_it_when_made_non_public(): void
    {
        $this->actingAsAdministrator();
        $this->withHeader('Referer', 'http://localhost/');

        $product = Product::createSimple('E2E Product', 'E2E-1', 'e2e-product');
        app(ProductRepository::class)->save($product);
        $id = $product->variations()[0]->id();
        $list = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
        app(PriceListRepository::class)->save($list);
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $list->id(), PriceListItemTargetType::VARIATION, $id, Price::exclusiveOfTax(Money::fromDecimal('50.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($id, 20));
        $this->postJson('/api/cart/lines', ['variation_id' => $id, 'quantity' => 1])->assertStatus(201);

        Livewire::test(CreatePromotion::class)
            ->fillForm(['code' => 'e2e-open', 'discount_type' => 'percentage', 'percentage' => '10', 'minimum_spend' => '80', 'is_public' => true, 'public_description' => 'Ten off over 80'])
            ->call('create')->assertHasNoFormErrors();

        $items = $this->getJson('/api/cart?public_promotions=1')->json('public_promotions.items');
        $this->assertSame([['code' => 'e2e-open', 'description' => 'Ten off over 80', 'state' => 'below_minimum', 'remaining' => ['minor' => 3000, 'currency' => 'EUR']]], $items);

        $row = PromotionModel::query()->where('code', 'e2e-open')->firstOrFail();
        Livewire::test(EditPromotion::class, ['record' => $row->id])->fillForm(['is_public' => false])->call('save')->assertHasNoFormErrors();

        $this->assertArrayNotHasKey('public_promotions', $this->getJson('/api/cart?public_promotions=1')->json());
    }
}
