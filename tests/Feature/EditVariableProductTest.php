<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductGroup;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Season;
use EasyCo\Catalog\Tag;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EditVariableProduct — Step 1 of "real VARIABLE editing" (parent
 * fields + a read-only Variations list). Fixture helpers mirror
 * ProductResourceTest's own established shapes deliberately (same
 * staff/role/brand/season/group/category/tag/attribute construction),
 * duplicated here rather than shared — same reasoning
 * CreateVariableProductTest already established for this codebase: a
 * small, self-contained test file over a cross-file test helper
 * dependency.
 */
class EditVariableProductTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
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

    private function persistedSeason(string $name = 'Spring/Summer 2026'): Season
    {
        $season = new Season(id: null, name: $name, slug: 'spring-summer-2026');
        app(SeasonRepository::class)->save($season);

        return $season;
    }

    private function persistedProductGroup(string $code = 'shoes', string $name = 'Обувки'): ProductGroup
    {
        $group = new ProductGroup(id: null, code: $code, name: $name);
        app(ProductGroupRepository::class)->save($group);

        return $group;
    }

    private function persistedCategory(string $name): Category
    {
        $category = new Category(id: null, parentId: null, name: $name, slug: strtolower(str_replace(' ', '-', $name)));
        app(CategoryRepository::class)->save($category);

        return $category;
    }

    private function persistedTag(string $name): Tag
    {
        $tag = new Tag(id: null, name: $name, slug: strtolower(str_replace(' ', '-', $name)));
        app(TagRepository::class)->save($tag);

        return $tag;
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue} */
    private function persistedColorDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        return [$definition, $black, $white];
    }

    /** @return array{0: Product, 1: string} the persisted VARIABLE product and its own real Variation id (Black) */
    private function persistedVariableProductWithOneVariation(): array
    {
        [$definition, $black] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $variation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $variation->setBarcode('1112223334445');
        app(ProductRepository::class)->save($product);

        return [$product, (string) $variation->id()];
    }

    public function test_get_on_edit_variable_renders_for_a_real_variable_product_with_real_variations(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $this->get(ProductResource::getUrl('edit-variable', ['record' => $productModel]))
            ->assertOk()
            ->assertSee('Variable Shirt');

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->assertSuccessful();
    }

    public function test_editing_updates_only_the_parent_fields_that_actually_changed(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $brand = $this->persistedBrand('Adidas');
        $season = $this->persistedSeason('Fall/Winter 2026');
        $group = $this->persistedProductGroup();

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'name' => 'Variable Shirt Classic',
                'slug' => 'variable-shirt-classic',
                'base_sku' => 'SKU-VAR-2',
                'description' => '<p>Now with a <strong>real</strong> description.</p>',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::VISIBLE->value,
                'brand_id' => $brand->id(),
                'season_id' => $season->id(),
                'product_group_id' => $group->id(),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);

        $this->assertSame('Variable Shirt Classic', $reloaded->name());
        $this->assertSame('variable-shirt-classic', $reloaded->slug());
        $this->assertSame('SKU-VAR-2', $reloaded->baseSku());
        $this->assertSame('<p>Now with a <strong>real</strong> description.</p>', $reloaded->description());
        $this->assertSame(ProductStatus::DRAFT, $reloaded->status());
        $this->assertSame(CatalogVisibility::VISIBLE, $reloaded->catalogVisibility());
        $this->assertSame($brand->id(), $reloaded->brandId());
        $this->assertSame($season->id(), $reloaded->seasonId());
        $this->assertSame($group->id(), $reloaded->productGroupId());

        // The one real Variation this product started with is
        // completely untouched — this step's form has no field that
        // could have written to it at all.
        $this->assertCount(1, $reloaded->variations());
        $this->assertSame('SKU-VAR-BLACK', $reloaded->variations()[0]->sku());
        $this->assertSame('1112223334445', $reloaded->variations()[0]->barcode());
    }

    public function test_editing_syncs_categories_and_tags_the_same_way_editproduct_does(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $keep = $this->persistedCategory('Keep Category');
        $remove = $this->persistedCategory('Remove Category');
        app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $keep->id()));
        app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $remove->id()));

        $add = $this->persistedCategory('Add Category');

        $keepTag = $this->persistedTag('Keep Tag');
        $removeTag = $this->persistedTag('Remove Tag');
        app(ProductTagRepository::class)->save(new ProductTag(id: null, productId: $product->id(), tagId: $keepTag->id()));
        app(ProductTagRepository::class)->save(new ProductTag(id: null, productId: $product->id(), tagId: $removeTag->id()));

        $addTag = $this->persistedTag('Add Tag');

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'categories' => [$keep->id(), $add->id()],
                'tags' => [$keepTag->id(), $addTag->id()],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $finalCategoryIds = array_map(
            fn ($c) => $c->categoryId(),
            app(ProductCategoryRepository::class)->findByProductId($product->id())
        );
        $this->assertEqualsCanonicalizing([$keep->id(), $add->id()], $finalCategoryIds);

        $finalTagIds = array_map(
            fn ($t) => $t->tagId(),
            app(ProductTagRepository::class)->findByProductId($product->id())
        );
        $this->assertEqualsCanonicalizing([$keepTag->id(), $addTag->id()], $finalTagIds);
    }

    public function test_the_existing_variations_list_shows_the_real_combination_label_sku_and_barcode(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $rows = $component->get('data.existing_variations');

        $this->assertCount(1, $rows);
        $row = array_values($rows)[0];

        $this->assertSame($variationId, $row['variation_id']);
        $this->assertSame('Color: Black', $row['label']);
        $this->assertSame('SKU-VAR-BLACK', $row['sku']);
        $this->assertSame('1112223334445', $row['barcode']);
    }

    /**
     * The read-only Repeater accepts no edits — proven here by directly
     * setting the live component's own state (bypassing the disabled
     * inputs a real browser would refuse to let a user type into) and
     * confirming the submit still leaves the real Variation completely
     * unchanged, because ->dehydrated(false) keeps
     * 'existing_variations' out of $data entirely and updateProduct()
     * never reads it.
     */
    public function test_directly_mutating_existing_variations_state_has_no_effect_on_submit(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.sku", 'TAMPERED-SKU')
            ->set("data.existing_variations.{$rowKeys[0]}.barcode", '0000000000000')
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $variation = $reloaded->variations()[0];

        $this->assertSame($variationId, $variation->id());
        $this->assertSame('SKU-VAR-BLACK', $variation->sku());
        $this->assertSame('1112223334445', $variation->barcode());
    }

    /**
     * The real gap this task's own CONTEXT flags: Product::publish()'s
     * guard (CannotPublishEmptyVariableProductException) is less likely
     * to fire on an edit (real variations already exist from creation)
     * but still real and reachable — every variation archived, then
     * status changed to Active here. Same Notification+Halt pattern as
     * CreateVariableProduct's own equivalent, with a full rollback (the
     * name change in the same submission must not survive either).
     */
    public function test_selecting_active_status_with_only_archived_variations_is_rejected_with_the_real_message_and_rolls_back(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $product->variations()[0]->archive();
        app(ProductRepository::class)->save($product);

        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'name' => 'Should Not Be Saved',
                'status' => ProductStatus::ACTIVE->value,
            ])
            ->call('save')
            ->assertNotified("Product \"{$product->id()}\" cannot be published — it is a VARIABLE product with no active variations.");

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('Variable Shirt', $reloaded->name());
        $this->assertSame(ProductStatus::DRAFT, $reloaded->status());
    }
}
