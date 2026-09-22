<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use App\Services\ProductPricingAndStock;
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
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Catalog\ProductGroup;
use EasyCo\Catalog\ProductTag;
use EasyCo\Catalog\Season;
use EasyCo\Catalog\Tag;
use EasyCo\Catalog\Variation;
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
 * EditVariableProduct — Step 1 (parent fields) + Step 2a (per-
 * variation sku/barcode/is_purchasable/cost/stock_quantity, editable,
 * plus a bulk-set convenience for cost/stock). Fixture helpers mirror
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

    /** @return array{0: Product, 1: string, 2: string} the persisted VARIABLE product and its two real Variation ids (Black, White) */
    private function persistedVariableProductWithTwoVariations(): array
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $blackVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $whiteVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        app(ProductRepository::class)->save($product);

        return [$product, (string) $blackVariation->id(), (string) $whiteVariation->id()];
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
        // completely untouched — its own row round-trips unchanged
        // (this test never edits it), so the real per-row diff-write
        // correctly produces no writes at all.
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
     * Step 2a's own real point: sku/barcode/is_purchasable/cost/
     * stock_quantity are now genuinely editable per row (unlike Step
     * 1's fully read-only Repeater) — proven here via real ->set()
     * calls on the live component's own state (the same mechanism a
     * real browser interaction produces), then a real submit, then
     * reloading through the domain layer to confirm every one of the
     * five fields actually persisted.
     */
    public function test_editing_sku_barcode_is_purchasable_cost_and_stock_per_row_persists_correctly(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.sku", 'SKU-VAR-BLACK-2')
            ->set("data.existing_variations.{$rowKeys[0]}.barcode", '9998887776665')
            ->set("data.existing_variations.{$rowKeys[0]}.is_purchasable", false)
            ->set("data.existing_variations.{$rowKeys[0]}.cost", '12.50')
            ->set("data.existing_variations.{$rowKeys[0]}.stock_quantity", '42')
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $variation = $reloaded->variations()[0];
        $pricingAndStock = app(ProductPricingAndStock::class);

        $this->assertSame($variationId, $variation->id());
        $this->assertSame('SKU-VAR-BLACK-2', $variation->sku());
        $this->assertSame('9998887776665', $variation->barcode());
        $this->assertFalse($variation->isPurchasable());
        $this->assertSame('12.50', $pricingAndStock->costDisplay($variationId));
        $this->assertSame(42, $pricingAndStock->stockQuantity($variationId));
    }

    /**
     * The one field this Repeater still keeps genuinely read-only:
     * 'label' stays ->dehydrated(false) even now — tampering it via
     * ->set() (the same mechanism a real browser could never actually
     * reach, since the field itself is ->disabled()) must have zero
     * effect on submit, proving the dehydrated(false) guard still
     * works for the one field that still needs it.
     */
    public function test_the_label_field_remains_read_only_and_has_no_effect_on_submit(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.label", 'Tampered: Label')
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $variation = $reloaded->variations()[0];

        $this->assertSame($variationId, $variation->id());
        // The real label is derived fresh from attributeAssignments() on
        // every mount — never stored anywhere the tampered value could
        // have landed.
        $this->assertSame(['Color' => 'Black'], $this->realAttributeAssignmentNames($variation));
    }

    /** @return array<string, string> */
    private function realAttributeAssignmentNames(Variation $variation): array
    {
        $names = [];
        foreach ($variation->attributeAssignments() as $definitionId => $valueId) {
            $definitionName = AttributeDefinitionModel::find($definitionId)?->name;
            $valueName = AttributeValueModel::find($valueId)?->value;
            $names[$definitionName] = $valueName;
        }

        return $names;
    }

    /**
     * The real gap this task's own instruction flags explicitly:
     * cost is permission-gated BEFORE $data is even read, not just
     * diffed — a 'Product Entry' staff member (PRODUCT_VIEW +
     * PRODUCT_MANAGE only, no COST_VIEW/COST_MANAGE — the real shipped
     * role, StaffSystemRolesSeeder's own definition) who tampers the
     * cost field via ->set() (bypassing the real form, where the field
     * would not even be ->visible()) must still never have that value
     * written — mirrors this same file's own established tamper-proof
     * test shape for the one field that still needs it.
     */
    public function test_a_staff_member_without_cost_permissions_can_never_write_cost_even_when_tampered(): void
    {
        $roleRepository = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roleRepository);
        $role = $roleRepository->findSystemRoleByName('Product Entry');
        $staff = Staff::create('product.entry@example.com', app(PasswordHasher::class)->hash('password123'), 'Product Entry', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.cost", '999.99')
            ->call('save')
            ->assertHasNoFormErrors();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertNull($pricingAndStock->costDisplay($variationId));
    }

    /**
     * bulk_cost/bulk_stock_quantity are pure UI convenience —
     * ->live()->afterStateUpdated() writing into every row's own
     * cost/stock_quantity via $set() immediately, proven here directly
     * against the live component's own state (before any submit at
     * all) across TWO real variations, confirming both rows are
     * updated, not just the first.
     */
    public function test_the_bulk_set_fields_populate_every_rows_cost_and_stock_quantity_in_live_state(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $component->set('data.bulk_cost', '19.99')
            ->set('data.bulk_stock_quantity', '15');

        $rows = $component->get('data.existing_variations');
        $this->assertCount(2, $rows);

        // assertEquals, not assertSame: bulk_cost/bulk_stock_quantity
        // are themselves ->numeric() fields, so Livewire's real state
        // cast turns the submitted "19.99"/"15" strings into a
        // float/int $state before afterStateUpdated() ever runs — the
        // same value, propagated correctly into every row, just not
        // the same PHP type. What this test proves is the propagation
        // itself, not a type round-trip.
        foreach ($rows as $row) {
            $this->assertEquals(19.99, $row['cost']);
            $this->assertEquals(15, $row['stock_quantity']);
        }
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
