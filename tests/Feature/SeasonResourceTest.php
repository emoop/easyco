<?php

namespace Tests\Feature;

use App\Filament\Resources\SeasonResource;
use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Resources\SeasonResource\Pages\ListSeasons;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Season;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production SeasonResource. Mirrors
 * BrandResourceTest's structure exactly, minus every logo-related
 * test — Season has no logo field at all.
 */
class SeasonResourceTest extends TestCase
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

    public function test_creating_a_season_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateSeason::class)
            ->fillForm(['name' => 'Spring/Summer 2026', 'slug' => 'spring-summer-2026'])
            ->call('create')
            ->assertHasNoFormErrors();

        $season = app(SeasonRepository::class)->all()[0] ?? null;

        $this->assertNotNull($season);
        $this->assertSame('Spring/Summer 2026', $season->name());
        $this->assertSame('spring-summer-2026', $season->slug());
    }

    public function test_editing_a_season_updates_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        Livewire::test(EditSeason::class, ['record' => $season->id()])
            ->fillForm(['name' => 'Spring/Summer 2027', 'slug' => 'spring-summer-2027'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(SeasonRepository::class)->findById($season->id());

        $this->assertSame('Spring/Summer 2027', $reloaded->name());
        $this->assertSame('spring-summer-2027', $reloaded->slug());
    }

    public function test_a_duplicate_slug_is_rejected_on_create(): void
    {
        $this->actingAsPanelAdministrator();

        app(SeasonRepository::class)->save(new Season(id: null, name: 'Spring/Summer 2026', slug: 'colliding-slug'));

        Livewire::test(CreateSeason::class)
            ->fillForm(['name' => 'Fall/Winter 2026', 'slug' => 'colliding-slug'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_duplicate_slug_is_rejected_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        app(SeasonRepository::class)->save(new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026'));
        $fallWinter = new Season(id: null, name: 'Fall/Winter 2026', slug: 'fall-winter-2026');
        app(SeasonRepository::class)->save($fallWinter);

        Livewire::test(EditSeason::class, ['record' => $fallWinter->id()])
            ->fillForm(['slug' => 'spring-summer-2026'])
            ->call('save')
            ->assertHasFormErrors(['slug']);
    }

    public function test_editing_a_season_with_its_own_unchanged_slug_does_not_false_positive(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        Livewire::test(EditSeason::class, ['record' => $season->id()])
            ->fillForm(['name' => 'Spring/Summer 2026', 'slug' => 'spring-summer-2026'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(SeasonResource::getUrl('index'))->assertOk();
        $this->get(SeasonResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(SeasonResource::getUrl('index'))->assertOk();
        $this->get(SeasonResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(SeasonResource::getUrl('index'))->assertOk();
        $this->get(SeasonResource::getUrl('create'))->assertForbidden();
    }

    public function test_a_seasons_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);
        $seasonModel = SeasonModel::find($season->id());

        $component = Livewire::test(ListSeasons::class);

        $component->assertTableActionVisible('edit', $seasonModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($seasonModel);
        $this->assertSame(SeasonResource::getUrl('view', ['record' => $seasonModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(SeasonResource::getUrl('edit', ['record' => $seasonModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(ListSeasons::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $seasonModel);

        $this->get(SeasonResource::getUrl('view', ['record' => $seasonModel]))->assertOk();
        $this->get(SeasonResource::getUrl('edit', ['record' => $seasonModel]))->assertForbidden();
    }

    public function test_the_count_column_shows_the_real_number_of_products_using_this_season(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            $product->assignSeason($season->id());
            app(ProductRepository::class)->save($product);
        }

        $component = Livewire::test(ListSeasons::class);

        $component->assertTableColumnStateSet('products_count', 3, record: SeasonModel::find($season->id()));
    }

    public function test_delete_is_blocked_when_the_season_is_still_in_use(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $product->assignSeason($season->id());
        app(ProductRepository::class)->save($product);

        Livewire::test(ListSeasons::class)
            ->callTableAction('delete', SeasonModel::find($season->id()))
            ->assertNotified();

        $this->assertNotNull(app(SeasonRepository::class)->findById($season->id()));
    }

    public function test_delete_succeeds_when_the_season_is_genuinely_unused(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        Livewire::test(ListSeasons::class)
            ->callTableAction('delete', SeasonModel::find($season->id()));

        $this->assertNull(app(SeasonRepository::class)->findById($season->id()));
    }

    /**
     * The drill-down page is retired — products_count's ->url() now
     * redirects into ProductResource's own real list, pre-filtered via
     * its existing 'season_id' SelectFilter (Filament's real
     * #[Url(as: 'filters')] binding on ListRecords::$tableFilters). Only
     * this season's own products may appear.
     */
    public function test_the_products_count_link_redirects_into_products_filtered_to_that_season_only(): void
    {
        $this->actingAsPanelAdministrator();

        $summer = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($summer);
        $winter = new Season(id: null, name: 'Autumn/Winter 2026', slug: 'autumn-winter-2026');
        app(SeasonRepository::class)->save($winter);

        $summerProduct = Product::createSimple('Linen Beach Shirt', 'SKU-SUMMER', 'linen-beach-shirt');
        $summerProduct->assignSeason($summer->id());
        app(ProductRepository::class)->save($summerProduct);

        $winterProduct = Product::createSimple('Wool Winter Coat', 'SKU-WINTER', 'wool-winter-coat');
        $winterProduct->assignSeason($winter->id());
        app(ProductRepository::class)->save($winterProduct);

        $seasonless = Product::createSimple('Plain Seasonless Belt', 'SKU-NONE', 'plain-seasonless-belt');
        app(ProductRepository::class)->save($seasonless);

        $summerModel = SeasonModel::find($summer->id());
        $component = Livewire::test(ListSeasons::class);
        $component->assertTableColumnStateSet('products_count', 1, record: $summerModel);

        $column = $component->instance()->getTable()->getColumn('products_count')->record($summerModel);
        $generatedUrl = $column->getUrl($column->getState());
        $this->assertNotNull($generatedUrl);
        $this->assertStringContainsString(ProductResource::getUrl('index'), $generatedUrl);

        Livewire::test(ListProducts::class)
            ->filterTable('season_id', $summer->id())
            ->assertCanSeeTableRecords([ProductModel::find($summerProduct->id())])
            ->assertCanNotSeeTableRecords([
                ProductModel::find($winterProduct->id()),
                ProductModel::find($seasonless->id()),
            ]);

        $this->get($generatedUrl)
            ->assertOk()
            ->assertSee('Linen Beach Shirt')
            ->assertDontSee('Wool Winter Coat')
            ->assertDontSee('Plain Seasonless Belt');
    }

    /** The retired route genuinely no longer exists — not silently still reachable. */
    public function test_the_old_products_drill_down_route_no_longer_exists(): void
    {
        $this->actingAsPanelAdministrator();

        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        $this->get('/admin/seasons/'.$season->id().'/products')->assertNotFound();
    }
}
