<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use App\Jobs\ProductMassEditChunk;
use App\Jobs\RefreshProductSortPrices;
use App\Services\MassEdit\MassEditRefused;
use App\Services\MassEdit\MassEditSelection;
use App\Services\MassEdit\ProductMassEditor;
use App\Services\PriceListWriter;
use App\Services\PriceSort\ProductSortPriceRefresher;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6d: «Масови действия» on the product list. The service (ProductMassEditor) does the work and enforces the rules; the table actions hand over what was chosen. Fixture: products 1…6, categories 1 / 2, tags 1 / 2,
 * seasons 1 / 2, product groups 1 / 2; products 1, 2, 3 carry tag 1.
 */
class ProductMassEditTest extends TestCase
{
    use BuildsStorefrontCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');

        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Shoes', 'shoes');
        $this->makeTag(1, 'Sale-26', 'sale-26');
        $this->makeTag(2, 'Other', 'other');
        DB::table('catalog_seasons')->insert([['id' => 1, 'name' => 'Spring', 'slug' => 'spring', 'created_at' => now(), 'updated_at' => now()], ['id' => 2, 'name' => 'Autumn', 'slug' => 'autumn', 'created_at' => now(), 'updated_at' => now()]]);
        DB::table('catalog_product_groups')->insert([['id' => 1, 'code' => '1', 'name' => '1-Shoes', 'created_at' => now(), 'updated_at' => now()], ['id' => 2, 'code' => '2', 'name' => '2-Blouses', 'created_at' => now(), 'updated_at' => now()]]);

        for ($id = 1; $id <= 6; $id++) {
            $this->makeProduct($id, ['slug' => "p{$id}", 'price' => '100.00', 'sale' => $id <= 4 ? '85.00' : null, 'stock' => 3, 'tags' => $id <= 3 ? [1] : []]);
        }
    }

    private function editor(): ProductMassEditor
    {
        return app(ProductMassEditor::class);
    }

    private function pick(array $ids, bool $handPicked = true, bool $filtered = false): MassEditSelection
    {
        return new MassEditSelection($ids, $handPicked, $filtered);
    }

    /** @return array<int, list<int>> product id => its category ids, sorted */
    private function categoriesOf(): array
    {
        $out = [];

        foreach (DB::table('catalog_product_categories')->orderBy('product_id')->get() as $row) {
            $out[(int) $row->product_id][] = (int) $row->category_id;
        }

        return $out;
    }

    /** @return array<int, list<int>> */
    private function tagsOf(): array
    {
        $out = [];

        foreach (DB::table('catalog_product_tags')->orderBy('product_id')->get() as $row) {
            $out[(int) $row->product_id][] = (int) $row->tag_id;
        }

        return $out;
    }

    /** @return array<int, ?int> */
    private function column(string $column): array
    {
        return DB::table('catalog_products')->orderBy('id')->pluck($column, 'id')->map(fn ($v) => $v === null ? null : (int) $v)->all();
    }

    private function entries(string $field): array
    {
        return DB::table('activity_log')->where('entity_type', 'product')->where('field', $field)->orderBy('id')->get(['entity_id', 'old_value', 'new_value'])->map(fn ($r) => (array) $r)->all();
    }

    private function snapshot(array $productIds): string
    {
        return md5(json_encode([DB::table('catalog_products')->whereIn('id', $productIds)->orderBy('id')->get(), DB::table('catalog_product_categories')->whereIn('product_id', $productIds)->orderBy('id')->get(), DB::table('catalog_product_tags')->whereIn('product_id', $productIds)->orderBy('id')->get()]));
    }

    // ---- categories, tags, season, group ----------------------------------------------------------------------------------

    /** ★ add / remove a category: only the selected products change, the others are byte-identical */
    public function test_add_and_remove_a_category_touch_only_the_selected_products(): void
    {
        $others = $this->snapshot([3, 4, 5, 6]);

        $added = $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick(['1', '2']));
        $this->assertSame([2, 0], [$added->changed, $added->unchanged]);
        $this->assertSame([1 => [1], 2 => [1]], $this->categoriesOf());
        $this->assertSame($others, $this->snapshot([3, 4, 5, 6]), 'the products that were not selected are untouched');
        $this->assertCount(2, $this->entries('categories'), 'one audit entry per changed product, like a manual save');

        $removed = $this->editor()->start(ProductMassEditor::REMOVE_CATEGORY, '1', $this->pick(['1', '2', '3']));
        $this->assertSame([2, 1], [$removed->changed, $removed->unchanged], 'product 3 never had it: unchanged');
        $this->assertSame([], $this->categoriesOf());
    }

    /** ★ add / remove a tag */
    public function test_add_and_remove_a_tag(): void
    {
        $this->editor()->start(ProductMassEditor::ADD_TAG, '2', $this->pick(['3', '4']));
        $this->assertSame([1 => [1], 2 => [1], 3 => [1, 2], 4 => [2]], $this->tagsOf());

        $result = $this->editor()->start(ProductMassEditor::REMOVE_TAG, '1', $this->pick(['1', '2', '3', '4']));
        $this->assertSame([3, 1], [$result->changed, $result->unchanged]);
        $this->assertSame([3 => [2], 4 => [2]], $this->tagsOf());
        $this->assertSame('Sale-26', $this->entries('tags')[count($this->entries('tags')) - 1]['old_value']);
    }

    /** ★ set / clear the season and change / clear the article group (the aggregate and the repository, with the audit entry of a manual save) */
    public function test_season_and_group_set_and_clear(): void
    {
        $this->editor()->start(ProductMassEditor::SET_SEASON, '1', $this->pick(['1', '2']));
        $this->assertSame([1 => 1, 2 => 1, 3 => null, 4 => null, 5 => null, 6 => null], $this->column('season_id'));

        $this->editor()->start(ProductMassEditor::SET_SEASON, null, $this->pick(['2']));
        $this->assertSame([1 => 1, 2 => null], array_slice($this->column('season_id'), 0, 2, true));

        $this->editor()->start(ProductMassEditor::SET_GROUP, '2', $this->pick(['4', '5']));
        $this->assertSame([4 => 2, 5 => 2], array_filter($this->column('product_group_id'), fn ($v) => $v !== null));
        $this->editor()->start(ProductMassEditor::SET_GROUP, null, $this->pick(['5']));
        $this->assertSame([4 => 2], array_filter($this->column('product_group_id'), fn ($v) => $v !== null));

        $this->assertSame(['1' => '1'], [$this->entries('season_id')[0]['entity_id'] => $this->entries('season_id')[0]['new_value']]);
        $this->assertCount(3, $this->entries('product_group_id'));
    }

    /** ★ the same action twice changes nothing the second time, and writes nothing */
    public function test_the_second_run_changes_nothing(): void
    {
        $ids = ['1', '2', '3'];

        foreach ([[ProductMassEditor::ADD_CATEGORY, '2'], [ProductMassEditor::ADD_TAG, '2'], [ProductMassEditor::SET_SEASON, '1'], [ProductMassEditor::SET_GROUP, '1']] as [$operation, $value]) {
            $first = $this->editor()->start($operation, $value, $this->pick($ids));
            $this->assertSame(3, $first->changed, $operation);

            $before = $this->snapshot($ids);
            $entries = DB::table('activity_log')->count();
            $second = $this->editor()->start($operation, $value, $this->pick($ids));

            $this->assertSame([0, 3], [$second->changed, $second->unchanged], $operation);
            $this->assertSame($before, $this->snapshot($ids), $operation.': nothing was written');
            $this->assertSame($entries, DB::table('activity_log')->count(), $operation.': nothing was audited');
        }
    }

    /** a product that no longer exists is skipped */
    public function test_a_missing_product_is_skipped(): void
    {
        $result = $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick(['1', '999']));

        $this->assertSame([1, 0, 1], [$result->changed, $result->unchanged, $result->skipped]);
    }

    /** ★ derived data: a category or a tag that a price-list campaign is scoped to changes the stored sort price after the mass edit */
    public function test_a_category_change_refreshes_the_stored_sort_price(): void
    {
        $this->makeCategory(3, 'Campaign', 'campaign');
        app(ProductSortPriceRefresher::class)->refresh([1, 2, 3, 4, 5, 6]);
        app(PriceListWriter::class)->create(['name' => 'C', 'percentage' => '50', 'is_active' => true, 'replaces_manual_sale' => true, 'confirm_all_products' => false, 'scopes' => [['type' => 'category', 'reference' => '3']]]);
        $stored = fn (int $id) => (int) DB::table('catalog_products')->where('id', $id)->value('price_from_minor');
        $this->assertSame([8500, 10000], [$stored(1), $stored(5)]);

        $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '3', $this->pick(['1', '5']));
        $this->assertSame([5000, 5000], [$stored(1), $stored(5)], 'the campaign now applies to both');

        $this->editor()->start(ProductMassEditor::REMOVE_CATEGORY, '3', $this->pick(['1']));
        $this->assertSame([8500, 5000], [$stored(1), $stored(5)]);
    }

    // ---- the rules --------------------------------------------------------------------------------------------------------

    /** ★ an unfiltered «select all» is refused by the service itself; a filtered one passes; above 5000 is refused */
    public function test_the_filtered_or_selected_rule_and_the_limit(): void
    {
        $before = $this->snapshot([1, 2, 3, 4, 5, 6]);

        try {
            $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick(['1', '2', '3'], handPicked: false, filtered: false));
            $this->fail('expected a refusal');
        } catch (MassEditRefused $refused) {
            $this->assertSame('unfiltered', $refused->reason);
        }

        $this->assertSame($before, $this->snapshot([1, 2, 3, 4, 5, 6]), 'nothing was written');
        $this->assertSame(3, $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick(['1', '2', '3'], handPicked: false, filtered: true))->changed);

        try {
            $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '2', $this->pick(array_map('strval', range(1, ProductMassEditor::MAX_PRODUCTS + 1))));
            $this->fail('expected a refusal');
        } catch (MassEditRefused $refused) {
            $this->assertSame('too_many', $refused->reason);
        }

        $this->expectException(MassEditRefused::class);
        $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '2', $this->pick([]));
    }

    /** the values are checked: unknown ids, a switched-off season / tags field, and the clearing of a required group */
    public function test_values_and_switches_are_checked(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $reason = function (string $operation, ?string $value): string {
            try {
                $this->editor()->start($operation, $value, $this->pick(['1']));
            } catch (MassEditRefused $refused) {
                return $refused->reason;
            }

            return 'ok';
        };

        $this->assertSame('unknown_value', $reason(ProductMassEditor::ADD_CATEGORY, '99'));
        $this->assertSame('unknown_value', $reason(ProductMassEditor::SET_GROUP, '99'));

        $settings->set('catalog.season_field_enabled', '0');
        $settings->set('catalog.tags_field_enabled', '0');
        $this->assertSame('field_off', $reason(ProductMassEditor::SET_SEASON, '1'));
        $this->assertSame('field_off', $reason(ProductMassEditor::ADD_TAG, '1'));

        $settings->set('catalog.product_group_required', '1');
        $this->assertSame('group_required', $reason(ProductMassEditor::SET_GROUP, null));
        $this->assertSame('ok', $reason(ProductMassEditor::SET_GROUP, '1'));
    }

    // ---- the manual-price reset -------------------------------------------------------------------------------------------------

    /** ★ the reset deletes only the «Manual Sale» items of the selected products; the regular prices and every campaign item stay; a campaign then applies to every variation */
    public function test_the_reset_deletes_only_the_manual_prices_of_the_selection(): void
    {
        $manualId = (int) DB::table('pricing_price_lists')->where('name', 'Manual Sale')->value('id');
        $regularId = (int) DB::table('pricing_price_lists')->where('name', 'Regular Prices')->value('id');
        app(PriceListWriter::class)->create(['name' => 'C', 'percentage' => '30', 'is_active' => true, 'replaces_manual_sale' => false, 'confirm_all_products' => true, 'scopes' => []]);
        $campaignItems = DB::table('pricing_price_list_items')->whereNotIn('price_list_id', [$manualId, $regularId])->count();
        $regular = DB::table('pricing_price_list_items')->where('price_list_id', $regularId)->count();
        $manualBefore = DB::table('pricing_price_list_items')->where('price_list_id', $manualId)->count();
        $stored = fn (int $id) => (int) DB::table('catalog_products')->where('id', $id)->value('price_from_minor');
        $this->assertSame([8500, 8500, 8500], [$stored(1), $stored(2), $stored(3)], 'the Manual Sale price wins while the campaign does not replace it');

        $result = $this->editor()->start(ProductMassEditor::RESET_MANUAL_PRICES, null, $this->pick(['1', '2', '5']));

        $this->assertSame([2, 2, 1], [$result->removedPrices, $result->changed, $result->unchanged], 'products 1 and 2 had one manual price each; 5 had none');
        $this->assertSame($manualBefore - 2, DB::table('pricing_price_list_items')->where('price_list_id', $manualId)->count(), 'products 3 and 4 keep their manual prices');
        $this->assertSame($regular, DB::table('pricing_price_list_items')->where('price_list_id', $regularId)->count());
        $this->assertSame($campaignItems, DB::table('pricing_price_list_items')->whereNotIn('price_list_id', [$manualId, $regularId])->count());
        $this->assertSame([7000, 7000, 8500, 8500], [$stored(1), $stored(2), $stored(3), $stored(4)], 'the campaign (−30%) applies to the reset products; the others keep their manual price');
    }

    /** ★ the reset refreshes the stored price ONCE per chunk */
    public function test_the_reset_queues_one_refresh_per_chunk(): void
    {
        Queue::fake();

        $this->editor()->start(ProductMassEditor::RESET_MANUAL_PRICES, null, $this->pick(['1', '2', '3', '4']));

        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class));
        $this->assertSame(['1', '2', '3', '4'], Queue::pushed(RefreshProductSortPrices::class)->first()->productIds);
        $this->assertSame(0, DB::table('activity_log')->where('field', 'sale_price')->count(), 'no audit entry for the reset (the owner\'s decision)');
    }

    // ---- the queue ---------------------------------------------------------------------------------------------------------------

    /** ★ above 200 products jobs of 100 are queued and together process every product exactly once */
    public function test_a_large_set_is_queued_in_chunks_of_100(): void
    {
        for ($id = 7; $id <= 250; $id++) {
            DB::table('catalog_products')->insert(['id' => $id, 'type' => 'simple', 'name' => "Bulk {$id}", 'slug' => "bulk-{$id}", 'base_sku' => "B-{$id}", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => now(), 'updated_at' => now(), 'timeline_at' => now()]);
        }

        Queue::fake();
        $ids = array_map('strval', range(1, 250));
        $result = $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick($ids));

        $this->assertSame(250, $result->queued);
        $this->assertSame([], $this->categoriesOf(), 'nothing ran in the request');
        $jobs = Queue::pushed(ProductMassEditChunk::class);
        $this->assertSame([100, 100, 50], $jobs->map(fn ($job) => count($job->productIds))->all());

        $processed = new \App\Services\MassEdit\MassEditResult();
        foreach ($jobs as $job) {
            $processed->merge($this->editor()->applyChunk($job->operation, $job->value, $job->productIds));
        }

        $this->assertSame([250, 0], [$processed->changed, $processed->unchanged]);
        $this->assertCount(250, $this->categoriesOf());

        // exactly once: running the chunks again changes nothing
        $again = $this->editor()->applyChunk(ProductMassEditor::ADD_CATEGORY, '1', array_slice($ids, 0, 100));
        $this->assertSame([0, 100], [$again->changed, $again->unchanged]);
    }

    /** ★ the queued job puts the staff member who started the edit into the audit entries */
    public function test_the_job_audits_as_the_staff_member_who_started_it(): void
    {
        $staff = $this->staff('Administrator');
        \Filament\Facades\Filament::auth()->logout();

        (new ProductMassEditChunk(ProductMassEditor::ADD_CATEGORY, '1', ['1'], (string) $staff->id))->handle($this->editor());

        $this->assertSame((int) $staff->id, (int) DB::table('activity_log')->where('field', 'categories')->value('staff_id'));
    }

    // ---- query counts ------------------------------------------------------------------------------------------------------------

    /** ★ queries per chunk: constant for the pivot actions and the reset (a grouped read, one write per changed row); per product for the aggregate actions — reported, not hidden */
    public function test_query_counts(): void
    {
        for ($id = 7; $id <= 200; $id++) {
            DB::table('catalog_products')->insert(['id' => $id, 'type' => 'simple', 'name' => "Bulk {$id}", 'slug' => "bulk-{$id}", 'base_sku' => "B-{$id}", 'status' => 'active', 'catalog_visibility' => 'visible', 'is_featured' => 0, 'created_at' => now(), 'updated_at' => now(), 'timeline_at' => now()]);
        }

        $count = function (callable $work): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $work();

            return $n;
        };

        $report = [];

        foreach (['add_category' => [ProductMassEditor::ADD_CATEGORY, '1'], 'set_season' => [ProductMassEditor::SET_SEASON, '1']] as $name => [$operation, $value]) {
            foreach ([1, 100, 200] as $n) {
                $ids = array_map('strval', range(1, $n));
                $this->editor()->applyChunk(ProductMassEditor::REMOVE_CATEGORY, '1', $ids);
                $this->editor()->start(ProductMassEditor::SET_SEASON, null, $this->pick($ids));
                $report[$name][$n] = $count(fn () => $this->editor()->start($operation, $value, $this->pick($ids)));
            }
        }

        $all = array_map('strval', range(1, 200));
        $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick($all));
        $notChanged = $count(fn () => $this->editor()->start(ProductMassEditor::ADD_CATEGORY, '1', $this->pick($all)));
        fwrite(STDERR, "\n[query-count] mass edit: ".json_encode($report).", a second (no-op) run of 200 products: {$notChanged} queries\n");

        // the no-op run costs a constant number of queries (grouped reads only, nothing written)
        $this->assertLessThan(20, $notChanged);
    }

    // ---- the table actions (permissions, hand-picked and filtered selection) ---------------------------------------------

    private function staff(string $roleName, ?array $permissions = null): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roles);
        $role = $permissions === null ? $roles->findSystemRoleByName($roleName) : Role::create($roleName.' '.uniqid(), $permissions);

        if ($permissions !== null) {
            $roles->save($role);
        }

        $staff = Staff::create(strtolower(str_replace(' ', '.', $roleName)).uniqid().'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** ★ the real action, rows picked by hand: runs, notifies with the counts, and writes through the service */
    public function test_the_table_action_with_hand_picked_rows(): void
    {
        $this->staff('Administrator');

        Livewire::test(ListProducts::class)
            ->callTableBulkAction('mass_'.ProductMassEditor::ADD_CATEGORY, [1, 2], ['value' => '1'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame([1 => [1], 2 => [1]], $this->categoriesOf());
    }

    /** ★ «select all» with NO filter or search is refused on the server (the UI is not the gate); with a search it processes exactly the matching products */
    public function test_select_all_needs_a_filter_or_a_search(): void
    {
        $this->staff('Administrator');
        $component = Livewire::test(ListProducts::class);

        $component->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [])->set('selectedTableRecords', [])
            ->callMountedTableBulkAction()  // nothing mounted: harmless
            ;
        $this->assertSame([], $this->categoriesOf());

        $unfiltered = Livewire::test(ListProducts::class)
            ->mountTableBulkAction('mass_'.ProductMassEditor::ADD_CATEGORY, [1, 2, 3])
            ->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [])
            ->setTableBulkActionData(['value' => '1'])
            ->callMountedTableBulkAction();
        $unfiltered->assertNotified();
        $this->assertSame([], $this->categoriesOf(), 'an unfiltered select-all changed nothing');

        $filtered = Livewire::test(ListProducts::class)
            ->searchTable('Product 2')
            ->mountTableBulkAction('mass_'.ProductMassEditor::ADD_CATEGORY, [1])
            ->set('isTrackingDeselectedTableRecords', true)->set('deselectedTableRecords', [])
            ->setTableBulkActionData(['value' => '1'])
            ->callMountedTableBulkAction();
        $filtered->assertNotified();
        $this->assertSame([2 => [1]], $this->categoriesOf(), 'a select-all with a search touched exactly the matching product');
    }

    /** ★ permissions: no product permission → no action runs; no `price_manage` → no reset; a switched-off season / tags field hides its action */
    public function test_permissions_and_switches_on_the_actions(): void
    {
        $this->staff('Viewer', [Permission::PRODUCT_VIEW]);
        Livewire::test(ListProducts::class)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::ADD_CATEGORY)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::RESET_MANUAL_PRICES);

        $editor = $this->staff('Editor', [Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE]);
        $component = Livewire::test(ListProducts::class)
            ->assertTableBulkActionVisible('mass_'.ProductMassEditor::ADD_CATEGORY)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::RESET_MANUAL_PRICES);

        // called without the UI affordance (the action is hidden, so the server gate is called directly): refused, nothing deleted
        $before = DB::table('pricing_price_list_items')->count();
        $livewire = $component->instance();
        $livewire->selectedTableRecords = ['1', '2'];
        \App\Filament\Resources\ProductResource::runMassEdit($livewire, ProductMassEditor::RESET_MANUAL_PRICES, null, [Permission::PRODUCT_MANAGE, Permission::PRICE_MANAGE]);
        $this->assertSame($before, DB::table('pricing_price_list_items')->count());
        unset($editor);

        $this->staff('Pricer', [Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE, Permission::PRICE_MANAGE]);
        Livewire::test(ListProducts::class)->assertTableBulkActionVisible('mass_'.ProductMassEditor::RESET_MANUAL_PRICES);

        app(SiteSettingsRepository::class)->set('catalog.season_field_enabled', '0');
        app(SiteSettingsRepository::class)->set('catalog.tags_field_enabled', '0');
        Livewire::test(ListProducts::class)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::SET_SEASON)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::ADD_TAG)
            ->assertTableBulkActionVisible('mass_'.ProductMassEditor::ADD_CATEGORY);
    }
}
