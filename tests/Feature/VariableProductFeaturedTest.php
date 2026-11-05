<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductGroup;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * P1.1: «Препоръчан» on the VARIABLE product forms. The flag belongs to the
 * PARENT product only: it is saved and loaded by the variable wizard and the
 * variable edit page, and no variation row (existing, new or archived) has it.
 * Fixture helpers mirror EditVariableProductTest's own shapes deliberately.
 */
class VariableProductFeaturedTest extends TestCase
{
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveProductsAShippingClass();

        // The activity log is off by default; the log assertion needs it on (as in ProductActivityLogTest).
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('featured-admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function persistedBrand(string $name = 'Nike'): Brand
    {
        $brand = new Brand(id: null, name: $name, slug: strtolower($name));
        app(BrandRepository::class)->save($brand);

        return $brand;
    }

    private function persistedProductGroup(string $code = 'shoes', string $name = 'Обувки'): ProductGroup
    {
        $group = new ProductGroup(id: null, code: $code, name: $name);
        app(ProductGroupRepository::class)->save($group);

        return $group;
    }

    /** @return array{0: Product, 1: string} the persisted VARIABLE product with one variation, and that variation's id */
    private function persistedVariableProductWithOneVariation(bool $featured = false): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $product = Product::createVariable('Featured Shirt', 'SKU-FEAT', 'featured-shirt');
        $product->setFeatured($featured);
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $variation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-FEAT-BLACK');
        app(ProductRepository::class)->save($product);

        return [$product, (string) $variation->id()];
    }

    public function test_the_featured_toggle_on_the_variable_edit_page_loads_saves_and_is_logged(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation(featured: true);
        $productModel = ProductModel::find($product->id());
        $this->assertTrue((bool) $productModel->is_featured);

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->assertFormSet(['is_featured' => true])
            ->fillForm(['is_featured' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse((bool) ProductModel::find($productModel->id)->is_featured);

        $entry = ActivityLogModel::where('entity_type', 'product')
            ->where('entity_id', $productModel->id)
            ->where('field', 'is_featured')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame('1', $entry->old_value);
        $this->assertSame('0', $entry->new_value);
    }

    public function test_the_featured_toggle_on_the_variable_wizard_saves_the_flag_on_the_parent_product(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand();
        $group = $this->persistedProductGroup();

        Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Featured Wizard Shirt',
                'slug' => 'featured-wizard-shirt',
                'base_sku' => 'FEAT-WIZ-1',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::VISIBLE->value,
                'is_featured' => true,
                'brand_id' => $brand->id(),
                'product_group_id' => $group->id(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'featured-wizard-shirt')->firstOrFail();
        $this->assertTrue((bool) $productModel->is_featured);
    }

    /**
     * ★ The variation rows are not a place for the flag: no field named
     * is_featured exists under the variation repeaters of the edit page,
     * and none under the wizard's own form. The repeater keys are checked
     * to be present, so the assertion cannot pass by looking at nothing.
     */
    public function test_no_variation_row_on_the_variable_edit_page_has_an_is_featured_field(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $keys = array_keys($component->instance()->getSchema('form')->getFlatFields(withHidden: true, withAbsoluteKeys: true));

        $this->assertNotEmpty(preg_grep('/existing_variations/', $keys), 'the variation rows must be part of the schema, or this test proves nothing');
        $this->assertSame([], array_values(array_filter($keys, fn (string $key): bool => str_contains($key, 'variations') && str_ends_with($key, 'is_featured'))));
        $this->assertCount(1, array_filter($keys, fn (string $key): bool => str_ends_with($key, 'is_featured')), 'exactly one is_featured field: the parent product\'s own');
    }

    public function test_the_wizard_has_one_featured_field_and_it_sits_on_the_parent_form(): void
    {
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(CreateVariableProduct::class);
        $keys = array_keys($component->instance()->getSchema('form')->getFlatFields(withHidden: true, withAbsoluteKeys: true));

        $this->assertCount(1, array_filter($keys, fn (string $key): bool => str_ends_with($key, 'is_featured')));
        $this->assertSame([], array_values(array_filter($keys, fn (string $key): bool => str_contains($key, 'variations') && str_ends_with($key, 'is_featured'))));
    }
}
