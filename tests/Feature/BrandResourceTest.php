<?php

namespace Tests\Feature;

use App\Filament\Resources\BrandResource;
use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\BrandResource\Pages\EditBrand;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
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
}
