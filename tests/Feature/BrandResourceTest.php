<?php

namespace Tests\Feature;

use App\Filament\Resources\BrandResource;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\Resources\BrandResource\Pages\ListBrands;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production BrandResource. Mirrors RoleResourceTest's
 * established conventions exactly — the same StaffPanelUser/
 * session()->forget('password_hash_staff') gotcha applies to every HTTP-
 * route test here too.
 */
class BrandResourceTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_creating_a_brand_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Nike', 'slug' => 'nike'])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = app(BrandRepository::class)->all()[0] ?? null;

        $this->assertNotNull($brand);
        $this->assertSame('Nike', $brand->name());
        $this->assertSame('nike', $brand->slug());
        $this->assertNull($brand->logoMediaAssetId());
    }

    public function test_editing_a_brand_updates_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        Livewire::test(EditBrand::class, ['record' => $brand->id()])
            ->fillForm(['name' => 'Nike Inc.', 'slug' => 'nike-inc'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(BrandRepository::class)->findById($brand->id());

        $this->assertSame('Nike Inc.', $reloaded->name());
        $this->assertSame('nike-inc', $reloaded->slug());
    }

    public function test_a_duplicate_slug_is_rejected_on_create(): void
    {
        $this->actingAsPanelAdministrator();

        app(BrandRepository::class)->save(new Brand(id: null, name: 'Nike', slug: 'colliding-slug'));

        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Not Nike', 'slug' => 'colliding-slug'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_duplicate_slug_is_rejected_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        app(BrandRepository::class)->save(new Brand(id: null, name: 'Nike', slug: 'nike'));
        $adidas = new Brand(id: null, name: 'Adidas', slug: 'adidas');
        app(BrandRepository::class)->save($adidas);

        Livewire::test(EditBrand::class, ['record' => $adidas->id()])
            ->fillForm(['slug' => 'nike'])
            ->call('save')
            ->assertHasFormErrors(['slug']);
    }

    /**
     * Confirms ->unique(..., ignoreRecord: true) actually prevents a
     * false positive when a Brand's own unchanged slug is resubmitted —
     * the specific behavior this task's ignoreRecord parameter exists
     * to guarantee.
     */
    public function test_editing_a_brand_with_its_own_unchanged_slug_does_not_false_positive(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        Livewire::test(EditBrand::class, ['record' => $brand->id()])
            ->fillForm(['name' => 'Nike', 'slug' => 'nike'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_uploading_a_logo_on_create_persists_a_real_media_asset(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Nike',
                'slug' => 'nike',
                'logo' => UploadedFile::fake()->image('logo.jpg'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = app(BrandRepository::class)->all()[0] ?? null;

        $this->assertNotNull($brand);
        $this->assertNotNull($brand->logoMediaAssetId());

        $mediaAsset = app(MediaAssetRepository::class)->findById($brand->logoMediaAssetId());

        $this->assertNotNull($mediaAsset);
        $this->assertSame($brand->logoMediaAssetId(), $mediaAsset->id());
    }

    public function test_clearing_the_logo_field_on_edit_calls_remove_logo(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateBrand::class)
            ->fillForm([
                'name' => 'Nike',
                'slug' => 'nike',
                'logo' => UploadedFile::fake()->image('logo.jpg'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $brand = app(BrandRepository::class)->all()[0] ?? null;
        $this->assertNotNull($brand->logoMediaAssetId());

        Livewire::test(EditBrand::class, ['record' => $brand->id()])
            ->fillForm(['logo' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(BrandRepository::class)->findById($brand->id());

        $this->assertNull($reloaded->logoMediaAssetId());
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(BrandResource::getUrl('index'))->assertOk();
        $this->get(BrandResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(BrandResource::getUrl('index'))->assertOk();
        $this->get(BrandResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(BrandResource::getUrl('index'))->assertOk();
        $this->get(BrandResource::getUrl('create'))->assertForbidden();
    }

    /**
     * CatalogSettings' "Show Brand field" toggle — off hides this whole
     * Resource: the nav item (canAccess() = canViewAny(), Filament's own
     * HasAuthorization) AND a direct URL hit to the list, because
     * ListBrands::authorizeAccess() already re-checks canViewAny() (a
     * real, pre-existing Filament gap — "hiding from navigation does
     * NOT prevent direct URL access" — already closed here the same way
     * ListRoles/ListProductGroups do it).
     */
    public function test_the_resource_is_fully_hidden_when_the_brand_field_setting_is_off(): void
    {
        $this->actingAsPanelAdministrator();

        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('catalog.brand_field_enabled', '0');

        $this->assertFalse(BrandResource::canViewAny());
        $this->get(BrandResource::getUrl('index'))->assertForbidden();

        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('catalog.brand_field_enabled', '1');

        $this->assertTrue(BrandResource::canViewAny());
        $this->get(BrandResource::getUrl('index'))->assertOk();
    }

    public function test_a_brands_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);
        $brandModel = \EasyCo\Catalog\Persistence\Eloquent\BrandModel::find($brand->id());

        $component = Livewire::test(\App\Filament\Resources\BrandResource\Pages\ListBrands::class);

        $component->assertTableActionVisible('edit', $brandModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($brandModel);
        $this->assertSame(BrandResource::getUrl('view', ['record' => $brandModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(BrandResource::getUrl('edit', ['record' => $brandModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(\App\Filament\Resources\BrandResource\Pages\ListBrands::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $brandModel);

        $this->get(BrandResource::getUrl('view', ['record' => $brandModel]))->assertOk();
        $this->get(BrandResource::getUrl('edit', ['record' => $brandModel]))->assertForbidden();
    }

    public function test_the_count_column_shows_the_real_number_of_products_using_this_brand(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $product->assignBrand($brand->id());
            app(ProductRepository::class)->save($product);
        }

        $component = Livewire::test(ListBrands::class);

        $component->assertTableColumnStateSet('products_count', 3, record: BrandModel::find($brand->id()));
    }

    public function test_delete_is_blocked_when_the_brand_is_still_in_use(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignBrand($brand->id());
        app(ProductRepository::class)->save($product);

        Livewire::test(ListBrands::class)
            ->callTableAction('delete', BrandModel::find($brand->id()))
            ->assertNotified();

        $this->assertNotNull(app(BrandRepository::class)->findById($brand->id()));
    }

    public function test_delete_succeeds_when_the_brand_is_genuinely_unused(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        Livewire::test(ListBrands::class)
            ->callTableAction('delete', BrandModel::find($brand->id()));

        $this->assertNull(app(BrandRepository::class)->findById($brand->id()));
    }

    /**
     * The drill-down page is retired — products_count's ->url() now
     * redirects into ProductResource's own real list, pre-filtered via
     * its existing 'brand_id' SelectFilter (Filament's real
     * #[Url(as: 'filters')] binding on ListRecords::$tableFilters). Only
     * this brand's own products may appear — not another brand's, not an
     * unbranded product.
     */
    public function test_the_products_count_link_redirects_into_products_filtered_to_that_brand_only(): void
    {
        $this->actingAsPanelAdministrator();

        $nike = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($nike);
        $adidas = new Brand(id: null, name: 'Adidas', slug: 'adidas');
        app(BrandRepository::class)->save($adidas);

        $nikeProduct = Product::createSimple('Air Zoom Runner', 'SKU-NIKE', 'air-zoom-runner');
        $nikeProduct->assignBrand($nike->id());
        app(ProductRepository::class)->save($nikeProduct);

        $adidasProduct = Product::createSimple('Ultraboost Trainer', 'SKU-ADIDAS', 'ultraboost-trainer');
        $adidasProduct->assignBrand($adidas->id());
        app(ProductRepository::class)->save($adidasProduct);

        $unbranded = Product::createSimple('Plain Unbranded Sock', 'SKU-NONE', 'plain-unbranded-sock');
        app(ProductRepository::class)->save($unbranded);

        // The real column ->url() callback, bound to its real record and
        // evaluated exactly as Filament does when rendering the row.
        $nikeModel = BrandModel::find($nike->id());
        $component = Livewire::test(ListBrands::class);
        $component->assertTableColumnStateSet('products_count', 1, record: $nikeModel);

        $column = $component->instance()->getTable()->getColumn('products_count')->record($nikeModel);
        $generatedUrl = $column->getUrl($column->getState());
        $this->assertNotNull($generatedUrl);
        $this->assertStringContainsString(ProductResource::getUrl('index'), $generatedUrl);

        Livewire::test(ListProducts::class)
            ->filterTable('brand_id', $nike->id())
            ->assertCanSeeTableRecords([ProductModel::find($nikeProduct->id())])
            ->assertCanNotSeeTableRecords([
                ProductModel::find($adidasProduct->id()),
                ProductModel::find($unbranded->id()),
            ]);

        // The real click-through: a genuine HTTP GET against that exact
        // generated URL, confirming Livewire's own #[Url] hydration
        // actually filters the rendered list.
        $this->get($generatedUrl)
            ->assertOk()
            ->assertSee('Air Zoom Runner')
            ->assertDontSee('Ultraboost Trainer')
            ->assertDontSee('Plain Unbranded Sock');
    }

    /**
     * The shared count tooltip (lang key related_products.count_tooltip.
     * filtered_list) warns that the linked, filtered list hides archived
     * and VARIABLE products the count includes — shown only while the
     * count is > 0 (the same condition as the column's own ->color()/
     * ->url()), never on a zero count, which has no link to explain.
     */
    public function test_the_products_count_tooltip_shows_only_when_the_count_is_above_zero(): void
    {
        $this->actingAsPanelAdministrator();

        $used = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($used);
        $unused = new Brand(id: null, name: 'Adidas', slug: 'adidas');
        app(BrandRepository::class)->save($unused);

        $product = Product::createSimple('Air Zoom Runner', 'SKU-NIKE', 'air-zoom-runner');
        $product->assignBrand($used->id());
        app(ProductRepository::class)->save($product);

        $expected = __('related_products.count_tooltip.filtered_list');

        $component = Livewire::test(ListBrands::class);
        $column = $component->instance()->getTable()->getColumn('products_count');

        $usedModel = BrandModel::find($used->id());
        $withCount = $column->record($usedModel);
        $this->assertSame($expected, $withCount->getTooltip($withCount->getState()));

        $unusedModel = BrandModel::find($unused->id());
        $withoutCount = $column->record($unusedModel);
        $this->assertNull($withoutCount->getTooltip($withoutCount->getState()));

        // The real rendered page: the tooltip text appears exactly once
        // (the one row with a count) and the row's link is still intact
        // alongside it.
        $html = $this->get(BrandResource::getUrl('index'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, $expected));
        $this->assertStringContainsString('filters%5Bbrand_id%5D%5Bvalue%5D='.$used->id(), $html);
    }

    /** The retired route genuinely no longer exists — not silently still reachable. */
    public function test_the_old_products_drill_down_route_no_longer_exists(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        app(BrandRepository::class)->save($brand);

        $this->get('/admin/brands/'.$brand->id().'/products')->assertNotFound();
    }
}
