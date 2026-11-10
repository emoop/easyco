<?php

namespace Tests\Feature;

use App\Filament\NavigationGroup;
use App\Filament\Pages\ActivityLogJournal;
use App\Filament\Resources\PriceListResource;
use App\Filament\Resources\PriceListResource\Pages\CreatePriceList;
use App\Filament\Resources\PriceListResource\Pages\EditPriceList;
use App\Filament\Resources\PriceListResource\Pages\ListPriceLists;
use App\Filament\StaffPanelUser;
use App\Services\PriceListAdminReader;
use App\Services\PriceListWriter;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Pricing\Persistence\Eloquent\PriceListModel;
use EasyCo\Pricing\Persistence\Eloquent\PriceListScopeModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6b: the admin «Ценови листове» screens under «Маркетинг»: who may open them, the menu order, the list (status, period, scope, the replace mark, bounded queries), the system rows read-only, the form (the empty-scope
 * warning and confirmation, the field errors, duplicate) and the «Засяга N продукта» line. Every write goes through PriceListWriter.
 */
class PriceListResourceTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeTag(1, 'Summer', 'summer');
        $this->makeProduct(1, ['slug' => 'one', 'price' => '100.00', 'stock' => 1, 'brand' => 1, 'tags' => [1]]);
        $this->makeProduct(2, ['slug' => 'two', 'price' => '100.00', 'stock' => 1, 'brand' => 1]);
        $this->makeProduct(3, ['slug' => 'three', 'price' => '100.00', 'stock' => 1]);
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): void
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    /** @return list<string> */
    private function navigationItemLabels(): array
    {
        return collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()))->values()->all();
    }

    /** @param array<string, mixed> $over */
    private function make(array $over = []): PriceListModel
    {
        $list = app(PriceListWriter::class)->create($over + ['name' => 'Campaign '.(++self::$n), 'percentage' => '30', 'is_active' => true, 'replaces_manual_sale' => false, 'confirm_all_products' => false, 'scopes' => [['type' => 'brand', 'reference' => '1']]]);

        return PriceListModel::query()->findOrFail($list->id());
    }

    private function systemList(string $name): PriceListModel
    {
        return PriceListModel::query()->where('name', $name)->firstOrFail();
    }

    // ---- who may open it, the menu -------------------------------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(PriceListResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    /** ★ with price_manage every page opens; without it every page is a 403 and the item is not in the navigation */
    public function test_the_resource_needs_the_pricing_permission(): void
    {
        $list = $this->make();
        $this->actingAsCustomRole([Permission::PRICE_MANAGE]);

        $this->get(PriceListResource::getUrl('index'))->assertOk();
        $this->get(PriceListResource::getUrl('create'))->assertOk();
        $this->get(PriceListResource::getUrl('edit', ['record' => $list]))->assertOk();
        $this->assertContains('Price lists', $this->navigationItemLabels());

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]);

        $this->get(PriceListResource::getUrl('index'))->assertForbidden();
        $this->get(PriceListResource::getUrl('create'))->assertForbidden();
        $this->get(PriceListResource::getUrl('edit', ['record' => $list]))->assertForbidden();
    }

    public function test_the_item_is_not_in_the_navigation_without_the_permission(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]);

        $this->assertNotContains('Price lists', $this->navigationItemLabels());
    }

    /** ★ the item sits in «Маркетинг», right after «Промокодове» */
    public function test_the_item_is_in_marketing_after_the_promo_codes(): void
    {
        $this->actingAsAdministrator();

        $this->assertSame(NavigationGroup::MARKETING, PriceListResource::getNavigationGroup());
        $this->assertGreaterThan(\App\Filament\Resources\PromotionResource::getNavigationSort(), PriceListResource::getNavigationSort());

        $marketing = collect(Filament::getNavigation())->first(fn ($group) => $group->getLabel() === 'Marketing');
        $this->assertSame(['Promo codes', 'Price lists'], collect($marketing->getItems())->map(fn ($item) => $item->getLabel())->values()->all());
    }

    /** ★ a price list is never deleted */
    public function test_a_price_list_can_never_be_deleted(): void
    {
        $list = $this->make();
        $this->actingAsAdministrator();

        $this->assertFalse(PriceListResource::canDelete($list));
        Livewire::test(ListPriceLists::class)->assertTableActionDoesNotExist('delete_price_list');
    }

    /** ★ a system list has no edit page (403 by URL), no actions, and the form can never write to it */
    public function test_system_lists_are_read_only(): void
    {
        $this->actingAsAdministrator();
        $manual = $this->systemList('Manual Sale');
        $regular = $this->systemList('Regular Prices');

        $this->get(PriceListResource::getUrl('edit', ['record' => $manual]))->assertForbidden();
        $this->get(PriceListResource::getUrl('edit', ['record' => $regular]))->assertForbidden();
        $this->assertFalse(PriceListResource::canEdit($manual));
        $this->assertSame('system', PriceListResource::statusOf($manual));

        app()->setLocale('bg');
        Livewire::test(ListPriceLists::class)
            ->assertSee('Системен')
            ->assertTableActionHidden('edit_price_list', $manual)
            ->assertTableActionHidden('toggle_price_list', $manual)
            ->assertTableActionHidden('duplicate_price_list', $manual);
    }

    // ---- the list ------------------------------------------------------------------------------------------------

    /** ★ the columns: discount, period, the scope summary, the replace mark and the computed status badge */
    public function test_the_list_shows_discount_period_scope_mark_and_status(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $this->make(['name' => 'Active one', 'percentage' => '12.5', 'replaces_manual_sale' => true, 'scopes' => [['type' => 'brand', 'reference' => '1'], ['type' => 'tag', 'reference' => '1']]]);
        $this->make(['name' => 'Planned one', 'percentage' => '10', 'valid_from' => '2037-01-01 10:00', 'scopes' => [['type' => 'tag', 'reference' => '1']]]);
        $this->make(['name' => 'Expired one', 'percentage' => '10', 'valid_from' => '2020-01-01 10:00', 'valid_until' => '2020-06-01 10:00', 'scopes' => [['type' => 'brand', 'reference' => '1']]]);
        $this->make(['name' => 'Off one', 'percentage' => '10', 'is_active' => false, 'scopes' => [], 'confirm_all_products' => true]);

        Livewire::test(ListPriceLists::class)
            ->assertSee('12,5%')
            ->assertSee('Марка: Acme · Таг: Summer')
            ->assertSee('Всички продукти')
            ->assertSee('Замества ръчна промо')
            ->assertSee('без срок')
            ->assertSee('от 01.01.2037')
            ->assertSee('Активна')
            ->assertSee('Планирана')
            ->assertSee('Изтекла')
            ->assertSee('Изключена');

        $statuses = PriceListModel::query()->where('is_system', false)->orderBy('id')->get()->map(fn ($l) => PriceListResource::statusOf($l))->all();
        $this->assertSame(['active', 'planned', 'expired', 'inactive'], $statuses);
    }

    public function test_the_list_filters_by_status(): void
    {
        $this->actingAsAdministrator();
        $active = $this->make(['name' => 'A1']);
        $planned = $this->make(['name' => 'P1', 'valid_from' => '2037-01-01 10:00', 'scopes' => [['type' => 'tag', 'reference' => '1']]]);

        Livewire::test(ListPriceLists::class)
            ->filterTable('state', 'planned')->assertCanSeeTableRecords([$planned])->assertCanNotSeeTableRecords([$active])
            ->resetTableFilters()
            ->filterTable('state', 'active')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$planned]);
    }

    /** the active lists come first, then the newest */
    public function test_the_order_is_active_first_then_the_newest(): void
    {
        $this->actingAsAdministrator();
        $old = $this->make(['name' => 'Old active']);
        $off = $this->make(['name' => 'Off newer', 'is_active' => false, 'scopes' => [['type' => 'tag', 'reference' => '1']]]);
        $new = $this->make(['name' => 'New active', 'scopes' => [['type' => 'brand', 'reference' => '1'], ['type' => 'tag', 'reference' => '1']]]);

        $ids = PriceListResource::getEloquentQuery()->where('is_system', false)->pluck('id')->all();

        $this->assertSame([$new->id, $old->id, $off->id], $ids);
    }

    /** ★ the scope summaries cost the same number of queries for 1 and for 30 lists */
    public function test_the_scope_summaries_cost_a_fixed_number_of_queries(): void
    {
        $this->actingAsAdministrator();
        $this->make(['scopes' => [['type' => 'brand', 'reference' => '1']]]);
        $reader = app(PriceListAdminReader::class);

        $count = function () use ($reader): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $reader->scopeNames(PriceListModel::query()->pluck('id')->all());

            return $n;
        };

        $count();
        $one = $count();

        for ($i = 0; $i < 29; $i++) {
            // different scope sets so that the lists can coexist: a brand AND a tag AND a product each (distinct product ids)
            $this->makeProduct(100 + $i, ['slug' => 'many-'.$i, 'price' => '10.00', 'stock' => 1]);
            $this->make(['scopes' => [['type' => 'product', 'reference' => (string) (100 + $i)], ['type' => 'brand', 'reference' => '1'], ['type' => 'tag', 'reference' => '1']]]);
        }

        $thirty = $count();
        fwrite(STDERR, "\n[query-count] price list scope summaries: 1 list {$one}, 31 lists {$thirty}\n");

        $this->assertSame($one + 2, $thirty, 'a fixed number: the extra kinds (product, tag) add one name read each, never one per list');
    }

    /** with no campaign yet the list still shows the two system lists (the system rows are rows), read-only, and the «Създай» button */
    public function test_with_no_campaign_the_system_lists_are_the_rows(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();

        Livewire::test(ListPriceLists::class)->assertSee('Regular Prices')->assertSee('Manual Sale')->assertSee('Системен')->assertSee('Създай');
    }

    // ---- the form ------------------------------------------------------------------------------------------------

    private function createPage()
    {
        $this->actingAsAdministrator();

        return Livewire::test(CreatePriceList::class);
    }

    /** ★ a campaign with no scope shows the amber warning and needs the confirmation */
    public function test_an_empty_scope_needs_the_confirmation(): void
    {
        $page = $this->createPage();

        $this->assertStringContainsString('data-price-list-all-products-warning', $page->html(), 'the warning is shown while no scope is chosen');

        $page->fillForm(['name' => 'Everything', 'percentage' => '10', 'confirm_all_products' => false])->call('create')->assertHasFormErrors(['confirm_all_products']);
        $this->assertSame(0, PriceListModel::query()->where('is_system', false)->count());

        $page->fillForm(['confirm_all_products' => true])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, PriceListModel::query()->where('is_system', false)->count());
        $this->assertSame(0, PriceListScopeModel::query()->count());
    }

    /** once a scope is chosen the warning and the confirmation go away */
    public function test_a_scope_removes_the_warning_and_the_confirmation(): void
    {
        $html = $this->createPage()->fillForm(['name' => 'Scoped', 'percentage' => '10', 'scope_brand' => ['1']])->html();

        $this->assertStringNotContainsString('data-price-list-all-products-warning', $html);
    }

    public function test_a_scoped_campaign_is_created_from_the_pickers(): void
    {
        $this->createPage()->fillForm(['name' => 'Acme summer', 'percentage' => '30', 'scope_brand' => ['1'], 'scope_tag' => ['1'], 'replaces_manual_sale' => true, 'valid_from' => '2030-03-01 08:00'])->call('create')->assertHasNoFormErrors();

        $list = PriceListModel::query()->where('name', 'Acme summer')->firstOrFail();
        $this->assertSame(3000, (int) $list->percentage_basis_points);
        $this->assertTrue((bool) $list->replaces_manual_sale);
        $this->assertSame(500, (int) $list->priority);
        $this->assertEqualsCanonicalizing(['brand:1', 'tag:1'], PriceListScopeModel::query()->where('price_list_id', $list->id)->get()->map(fn ($s) => $s->scope_type.':'.$s->scope_reference_id)->all());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInput(): array
    {
        return [
            'percent 0' => [['percentage' => '0'], 'percentage'],
            'percent 100' => [['percentage' => '100'], 'percentage'],
            'percent negative' => [['percentage' => '-3'], 'percentage'],
            'no name' => [['name' => ''], 'name'],
            'end before start' => [['valid_from' => '2030-02-01 10:00:00', 'valid_until' => '2030-01-01 10:00:00'], 'valid_until'],
        ];
    }

    /**
     * ★ the form refuses these with a field error and writes nothing
     *
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('badInput')]
    public function test_bad_input_is_a_field_error(array $override, string $field): void
    {
        $this->createPage()->fillForm($override + ['name' => 'Bad', 'percentage' => '10', 'scope_brand' => ['1']])->call('create')->assertHasFormErrors([$field]);

        $this->assertSame(0, PriceListModel::query()->where('is_system', false)->count());
    }

    /** the edit page shows the stored values and the «Засяга N продукта» line; saving changes only what was changed */
    public function test_the_edit_page_shows_the_stored_values_and_how_many_products_it_affects(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $list = $this->make(['name' => 'Stored', 'percentage' => '12.5', 'scopes' => [['type' => 'brand', 'reference' => '1']]]);

        $page = Livewire::test(EditPriceList::class, ['record' => $list->id]);
        $page->assertFormSet(['name' => 'Stored', 'percentage' => '12.5', 'is_active' => true, 'scope_brand' => ['1']]);
        $this->assertStringContainsString('Засяга 2 продукта', $page->html(), 'brand Acme = products 1 and 2');

        $page->fillForm(['percentage' => '20'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(2000, (int) PriceListModel::query()->findOrFail($list->id)->percentage_basis_points);
    }

    public function test_an_unscoped_stored_campaign_says_it_affects_all_products(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $list = $this->make(['name' => 'Everything', 'scopes' => [], 'confirm_all_products' => true]);

        $this->assertStringContainsString('Засяга всички продукти', Livewire::test(EditPriceList::class, ['record' => $list->id])->html());
    }

    /** ★ duplicate prefills a new INACTIVE form (the scope kept, a copy name) */
    public function test_duplicate_prefills_an_inactive_form(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $list = $this->make(['name' => 'Original', 'percentage' => '15', 'scopes' => [['type' => 'brand', 'reference' => '1']]]);

        Livewire::withQueryParams(['duplicate' => $list->id])->test(CreatePriceList::class)
            ->assertFormSet(['name' => 'Копие на Original', 'percentage' => '15', 'is_active' => false, 'scope_brand' => ['1']]);
    }

    public function test_the_status_action_switches_a_campaign_off_and_on(): void
    {
        $this->actingAsAdministrator();
        $list = $this->make();

        Livewire::test(ListPriceLists::class)->callTableAction('toggle_price_list', $list);
        $this->assertSame('inactive', PriceListModel::query()->findOrFail($list->id)->status);

        Livewire::test(ListPriceLists::class)->callTableAction('toggle_price_list', $list);
        $this->assertSame('active', PriceListModel::query()->findOrFail($list->id)->status);
    }

    /** ★ the journal shows a price-list entry under its readable entity name, in both languages */
    public function test_the_journal_shows_the_readable_entity_name(): void
    {
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        $this->actingAsAdministrator();
        $this->make();

        app()->setLocale('bg');
        Livewire::test(ActivityLogJournal::class)->assertSee('Ценови лист');

        app()->setLocale('en');
        Livewire::test(ActivityLogJournal::class)->assertSee('Price list');

        $this->assertSame('Ценови лист', trans('journal.entities.price_list', [], 'bg'));
        $this->assertSame('Price list', trans('journal.entities.price_list', [], 'en'));
    }

    /** both languages carry the same keys */
    public function test_the_texts_exist_in_both_languages(): void
    {
        $this->assertSame(array_keys(trans('price_lists', [], 'bg')), array_keys(trans('price_lists', [], 'en')));
        $this->assertSame('Ценови листове', __('navigation.groups.marketing', [], 'bg') === 'Маркетинг' ? trans('price_lists.navigation_label', [], 'bg') : '');
    }
}
