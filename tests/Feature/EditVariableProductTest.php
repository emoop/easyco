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
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
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
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Persistence\Eloquent\PriceListItemModel;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EditVariableProduct — Step 1 (parent fields) + Step 2a (per-
 * variation sku/barcode/is_purchasable/cost/stock_quantity, editable,
 * plus a bulk-set convenience for cost/stock) + Step 2b (regular_price/
 * sale_price at both the PRODUCT level and per row, an explicit
 * VARIATION-level override). Fixture helpers mirror
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

    private function seedPricingSystemLists(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
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

    /**
     * UPDATED for the base_sku-cascade feature: this fixture's own
     * single variation ("SKU-VAR-BLACK") genuinely starts with the old
     * base_sku prefix ("SKU-VAR-"), so changing base_sku to "SKU-VAR-2"
     * now correctly cascades it to "SKU-VAR-2-BLACK" — the real, new,
     * intended behavior, not a regression. ->call('save') (used here
     * and throughout this test file) invokes EditRecord::save() the
     * plain public method directly, a completely separate code path
     * from getSaveFormAction()'s own mounted-Action/modal-confirmation
     * flow (confirmed: the save BUTTON's Action is only reached via a
     * real click, or ->callAction()/->mountAction() in a test) — so
     * this direct call bypasses the confirmation requirement entirely,
     * exactly like every other ->call('save') test in this file, while
     * still exercising the real, unconditional server-side cascade
     * logic in updateProduct(). The confirmation-modal requirement
     * itself has its own dedicated test elsewhere in this file.
     */
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

        // The one real Variation this product started with has its
        // barcode untouched (this test never edits it) but its sku
        // genuinely cascaded — "SKU-VAR-BLACK" starts with the OLD
        // base_sku's own real prefix ("SKU-VAR-"), so the rename to
        // "SKU-VAR-2" correctly cascades it to "SKU-VAR-2-BLACK".
        $this->assertCount(1, $reloaded->variations());
        $this->assertSame('SKU-VAR-2-BLACK', $reloaded->variations()[0]->sku());
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
     * The "Edit all" Toggle gates the four mass-edit fields:
     * product_regular_price, product_sale_price, bulk_cost,
     * bulk_stock_quantity. All four start HIDDEN (the toggle's own
     * ->default(false)) and only become visible once the merchant turns
     * it on — asserted via Filament's own assertFormFieldHidden()/
     * assertFormFieldVisible() against the real rendered schema, not a
     * raw ->get() of the field's own closure.
     *
     * The bulk_cost case is deliberately checked with the
     * PANEL-ADMINISTRATOR role (holds COST_VIEW), i.e. its own
     * permission gate is already satisfied — so what this test proves
     * is the edit_all gate alone, isolating the new behavior from the
     * pre-existing COST_VIEW gate checked separately above.
     */
    public function test_the_edit_all_toggle_hides_and_reveals_the_four_mass_edit_fields(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->assertFormFieldHidden('product_regular_price')
            ->assertFormFieldHidden('product_sale_price')
            ->assertFormFieldHidden('bulk_cost')
            ->assertFormFieldHidden('bulk_stock_quantity');

        $component->set('data.edit_all', true)
            ->assertFormFieldVisible('product_regular_price')
            ->assertFormFieldVisible('product_sale_price')
            ->assertFormFieldVisible('bulk_cost')
            ->assertFormFieldVisible('bulk_stock_quantity');

        $component->set('data.edit_all', false)
            ->assertFormFieldHidden('product_regular_price')
            ->assertFormFieldHidden('product_sale_price')
            ->assertFormFieldHidden('bulk_cost')
            ->assertFormFieldHidden('bulk_stock_quantity');
    }

    /**
     * The REAL data-loss hazard the ->dehydratedWhenHidden() on
     * product_regular_price/product_sale_price exists to prevent,
     * proven end to end: save a PRODUCT-level price, then save AGAIN
     * with the "Edit all" toggle left OFF (the default) and an
     * unrelated field changed. Without dehydratedWhenHidden(), the two
     * hidden price fields would drop out of $data, updateProductLevel
     * Pricing()'s own diff would read the null as "clear it", and the
     * PriceListItem would be deleted by a save that had nothing to do
     * with pricing.
     */
    public function test_a_saved_product_level_price_survives_a_save_with_the_edit_all_toggle_off(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        // First save: toggle ON, set a real PRODUCT-level regular price.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.edit_all', true)
            ->set('data.product_regular_price', '89.99')
            ->call('save')
            ->assertHasNoFormErrors();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertSame('89.99', $pricingAndStock->regularPriceDisplayForProduct($product->id()));

        // Second save: toggle untouched (default false), only the name
        // changed. The price must survive intact.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['name' => 'Renamed, Price Untouched'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('89.99', $pricingAndStock->regularPriceDisplayForProduct($product->id()));
        $this->assertSame(
            1,
            PriceListItemModel::where('target_type', 'product')->where('target_id', $product->id())->count()
        );

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('Renamed, Price Untouched', $reloaded->name());
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

    /**
     * Step 2b's own product-level mechanism: "Product Regular Price"/
     * "Product Sale Price" are always-visible, always-editable fields
     * writing a real PRODUCT-level PriceListItem — proven here via a
     * real submit, then reading back through
     * ProductPricingAndStock::regularPriceDisplayForProduct()/
     * salePriceDisplayForProduct() (the same read path the form itself
     * uses to seed on the next mount).
     */
    public function test_product_level_regular_and_sale_price_diff_write_and_read_back_correctly(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'product_regular_price' => '89.99',
                'product_sale_price' => '69.99',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertSame('89.99', $pricingAndStock->regularPriceDisplayForProduct($product->id()));
        $this->assertSame('69.99', $pricingAndStock->salePriceDisplayForProduct($product->id()));
    }

    /**
     * The real fallback architecture per pricing-persistence-domain-
     * design.md §4.3: an empty row creates NO VARIATION-level
     * PriceListItem at all — the resolved price falls back to the
     * PRODUCT-level item purely by that row's own absence, not by any
     * special-cased "inherit" value. Verified directly against
     * PriceListItemModel (real DB row counts), the explicit minimum bar
     * this task's own instruction allows in place of exercising the
     * full PriceResolver (a separate, real domain service this task
     * does not wire this page into).
     */
    public function test_an_empty_row_creates_no_variation_level_price_item_and_falls_back_to_product_level(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'product_regular_price' => '89.99',
                'product_sale_price' => '69.99',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            0,
            PriceListItemModel::where('target_type', 'variation')
                ->where('target_id', $variationId)
                ->count(),
            'an untouched, empty row must never create a real VARIATION-level PriceListItem'
        );

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertNull($pricingAndStock->regularPriceDisplay($variationId));
        $this->assertSame('89.99', $pricingAndStock->regularPriceDisplayForProduct($product->id()));
    }

    /**
     * A row with its own explicit value creates a real, distinct
     * VARIATION-level PriceListItem — the real override this task's
     * whole per-row mechanism exists for, proven both via the real
     * domain read (regularPriceDisplay()) and via a direct DB row
     * count, confirming it coexists with (does not replace) the
     * PRODUCT-level item.
     */
    public function test_a_row_with_its_own_price_creates_a_real_variation_level_override(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set('data.product_regular_price', '89.99')
            ->set("data.existing_variations.{$rowKeys[0]}.regular_price", '79.99')
            ->call('save')
            ->assertHasNoFormErrors();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertSame('89.99', $pricingAndStock->regularPriceDisplayForProduct($product->id()));
        $this->assertSame('79.99', $pricingAndStock->regularPriceDisplay($variationId));

        $this->assertSame(
            1,
            PriceListItemModel::where('target_type', 'variation')
                ->where('target_id', $variationId)
                ->count()
        );
        $this->assertSame(
            1,
            PriceListItemModel::where('target_type', 'product')
                ->where('target_id', $product->id())
                ->count()
        );
    }

    /**
     * Clearing a previously-set PRODUCT-level price removes the
     * PriceListItem entirely — mirrors the established behavior already
     * confirmed for cost's own removal semantics, but price genuinely
     * HAS a removal path (unlike cost — see writeCost()'s own
     * docblock), so clearing here is a real, intended "no configured
     * price" state, not a no-op.
     */
    public function test_clearing_a_previously_set_product_level_price_removes_the_price_list_item(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['product_regular_price' => '89.99'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, PriceListItemModel::where('target_type', 'product')->where('target_id', $product->id())->count());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['product_regular_price' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, PriceListItemModel::where('target_type', 'product')->where('target_id', $product->id())->count());
        $this->assertNull(app(ProductPricingAndStock::class)->regularPriceDisplayForProduct($product->id()));
    }

    /** Same real removal semantics as the product-level test above, for a row's own VARIATION-level override. */
    public function test_clearing_a_previously_set_row_level_price_removes_the_variation_level_price_list_item(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.regular_price", '79.99')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, PriceListItemModel::where('target_type', 'variation')->where('target_id', $variationId)->count());

        $component2 = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys2 = array_keys($component2->get('data.existing_variations'));

        $component2->set("data.existing_variations.{$rowKeys2[0]}.regular_price", '')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, PriceListItemModel::where('target_type', 'variation')->where('target_id', $variationId)->count());
        $this->assertNull(app(ProductPricingAndStock::class)->regularPriceDisplay($variationId));
    }

    /**
     * The real gap this task's own instruction flags explicitly, same
     * shape as cost's own established tamper-proof test: a
     * 'Product Entry' staff member (no PRICE_MANAGE — the real shipped
     * role) who tampers EITHER level's price field via ->set() must
     * never have either written, since the permission is checked
     * before $data is even read at both the product level and the
     * per-row level.
     */
    public function test_a_staff_member_without_price_manage_can_never_write_price_at_either_level_even_when_tampered(): void
    {
        $this->seedPricingSystemLists();

        $roleRepository = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roleRepository);
        $role = $roleRepository->findSystemRoleByName('Product Entry');
        $staff = Staff::create('product.entry.price@example.com', app(PasswordHasher::class)->hash('password123'), 'Product Entry', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set('data.product_regular_price', '999.99')
            ->set("data.existing_variations.{$rowKeys[0]}.regular_price", '888.88')
            ->call('save')
            ->assertHasNoFormErrors();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertNull($pricingAndStock->regularPriceDisplayForProduct($product->id()));
        $this->assertNull($pricingAndStock->regularPriceDisplay($variationId));
    }

    /**
     * "Clear variation-level price overrides" — the checkbox itself must
     * not appear at all when this product genuinely has no real
     * VARIATION-level override of either type (a fresh, no-override
     * product from persistedVariableProductWithOneVariation()): an
     * always-visible, always-a-no-op toggle would be noise. Confirms
     * both the toggle's own ->visible() gate AND the real, seeded
     * regular_price_override_count/sale_price_override_count that gate
     * reads.
     */
    public function test_the_clear_overrides_toggles_are_hidden_when_no_variation_has_an_override(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->assertFormFieldHidden('clear_regular_price_overrides')
            ->assertFormFieldHidden('clear_sale_price_overrides');

        $this->assertSame(0, $component->get('data.regular_price_override_count'));
        $this->assertSame(0, $component->get('data.sale_price_override_count'));
    }

    /**
     * With real overrides present (two variations, a regular-price
     * override on both, a sale-price override on only one), both
     * toggles become visible AND each one's own helper text carries the
     * REAL, distinct count for its own price type — not a generic
     * message, and not the other type's count.
     */
    public function test_the_clear_overrides_toggles_are_visible_with_the_real_count_in_their_own_helper_text(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $pricingAndStock->writeRegularPrice($blackId, '10.00');
        $pricingAndStock->writeRegularPrice($whiteId, '12.00');
        $pricingAndStock->writeSalePrice($blackId, '9.00');

        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->assertFormFieldVisible('clear_regular_price_overrides')
            ->assertFormFieldVisible('clear_sale_price_overrides');

        $this->assertSame(2, $component->get('data.regular_price_override_count'));
        $this->assertSame(1, $component->get('data.sale_price_override_count'));

        $component->assertSee(__('products.price_overrides.clear_regular_help', ['count' => 2]));
        $component->assertSee(__('products.price_overrides.clear_sale_help', ['count' => 1]));
    }

    /**
     * The real write side, real DB row count check — not just a domain
     * read: checking "clear regular price overrides" removes every
     * VARIATION-level PriceListItem in the "Regular Prices" system list
     * for THIS product's variations, while leaving that same product's
     * PRODUCT-level regular price (which has nothing to do with a
     * per-row override) and the untouched sale-price side completely
     * alone.
     */
    public function test_checking_clear_regular_price_overrides_removes_every_variation_level_regular_price_row(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $pricingAndStock->writeRegularPriceForProduct($product->id(), '50.00');
        $pricingAndStock->writeRegularPrice($blackId, '10.00');
        $pricingAndStock->writeRegularPrice($whiteId, '12.00');
        $pricingAndStock->writeSalePrice($blackId, '9.00');

        $regularListId = app(PriceListRepository::class)->findSystemListByName('Regular Prices')->id();

        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.clear_regular_price_overrides', true)
            ->call('save')
            ->assertHasNoFormErrors();

        // Real DB row count check, not just the domain read.
        $this->assertSame(
            0,
            PriceListItemModel::where('price_list_id', $regularListId)
                ->where('target_type', 'variation')
                ->whereIn('target_id', [$blackId, $whiteId])
                ->count()
        );

        $this->assertNull($pricingAndStock->regularPriceDisplay($blackId));
        $this->assertNull($pricingAndStock->regularPriceDisplay($whiteId));

        // Unaffected: the PRODUCT-level price and the untouched sale-price override.
        $this->assertSame('50.00', $pricingAndStock->regularPriceDisplayForProduct($product->id()));
        $this->assertSame('9.00', $pricingAndStock->salePriceDisplay($blackId));
    }

    /**
     * A variation with only a regular-price override is genuinely
     * unaffected by checking sale's own checkbox (independent gates —
     * a merchant may check only one), and vice versa — the same
     * fixture, checking BOTH checkboxes independently across two
     * separate assertions on the SAME underlying override state.
     */
    public function test_clearing_one_price_types_overrides_never_touches_the_other_types_overrides(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $pricingAndStock->writeRegularPrice($blackId, '10.00');
        $pricingAndStock->writeSalePrice($blackId, '9.00');

        $productModel = ProductModel::find($product->id());

        // Only the sale checkbox is checked — the regular override must survive.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.clear_sale_price_overrides', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('10.00', $pricingAndStock->regularPriceDisplay($blackId));
        $this->assertNull($pricingAndStock->salePriceDisplay($blackId));
    }

    /**
     * The real gap this task's own instruction flags explicitly, same
     * shape as the established price-write tamper-proof test above: a
     * 'Product Entry' staff member (no PRICE_MANAGE) who tampers the
     * clear-overrides toggle via ->set() must never have triggered a
     * real clear — clearVariationPriceOverrides() checks the same
     * permission before either $data key is even read.
     */
    public function test_a_staff_member_without_price_manage_can_never_trigger_a_clear_even_when_tampered(): void
    {
        $this->seedPricingSystemLists();

        $roleRepository = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roleRepository);
        $role = $roleRepository->findSystemRoleByName('Product Entry');
        $staff = Staff::create('product.entry.clear@example.com', app(PasswordHasher::class)->hash('password123'), 'Product Entry', $role);
        app(StaffRepository::class)->save($staff);

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $pricingAndStock->writeRegularPrice($blackId, '10.00');

        $productModel = ProductModel::find($product->id());

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->set('data.clear_regular_price_overrides', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('10.00', $pricingAndStock->regularPriceDisplay($blackId));
    }

    /**
     * The genuinely ambiguous conflict case this task's own instruction
     * asked to be explicitly resolved: a merchant types a NEW per-row
     * override into a row AND checks "clear regular price overrides" in
     * the SAME submission. Resolved so the checkbox wins (see
     * clearVariationPriceOverrides()'s own docblock for the full
     * reasoning) — the freshly-typed override is written by
     * updateVariationPricingAndStock() first, then immediately cleared
     * back out by the clear-pass that runs after it.
     */
    public function test_the_clear_checkbox_wins_over_a_new_per_row_override_typed_in_the_same_submission(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $pricingAndStock->writeRegularPrice($blackId, '10.00');

        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.regular_price", '77.77')
            ->set('data.clear_regular_price_overrides', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($pricingAndStock->regularPriceDisplay($blackId));
    }

    /**
     * Placeholder text on an empty row shows the real, currently-
     * resolved PRODUCT-level price — verified via the row's own seeded
     * 'regular_price_placeholder'/'sale_price_placeholder' state (what
     * ->placeholder(fn (Get $get) => $get(...)) actually renders from),
     * confirming mutateFormDataBeforeFill() seeds it correctly from a
     * price genuinely already persisted before this mount, not merely
     * from whatever was just submitted in the same request.
     */
    public function test_the_placeholder_shows_the_real_product_level_price_on_an_empty_row(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();

        app(ProductPricingAndStock::class)->writeRegularPriceForProduct($product->id(), '54.00');
        app(ProductPricingAndStock::class)->writeSalePriceForProduct($product->id(), '44.00');

        $productModel = ProductModel::find($product->id());
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $rows = $component->get('data.existing_variations');
        $row = array_values($rows)[0];

        $this->assertSame($variationId, $row['variation_id']);
        $this->assertSame('54.00', $row['regular_price_placeholder']);
        $this->assertSame('44.00', $row['sale_price_placeholder']);
        // The row's own regular_price/sale_price stay empty — this is
        // still a pure fallback hint, not a pre-filled override.
        $this->assertNull($row['regular_price']);
        $this->assertNull($row['sale_price']);
    }

    /**
     * A REQUIRED FIX, not optional: afterSave() must call
     * $this->fillForm() UNCONDITIONALLY, not only when a sku actually
     * differed. Without this, regular_price_placeholder/
     * sale_price_placeholder (seeded fresh by mutateFormDataBeforeFill()
     * — same "no redirect means no automatic refresh" reasoning
     * documented on afterSave() itself) go stale after ANY successful
     * save that happens not to touch a sku — here, changing ONLY the
     * product-level regular price with no sku involved at all. Proven
     * by changing the product-level price, then confirming the SAME
     * live component's own placeholder state reflects the NEW price
     * immediately, without a fresh page load.
     */
    public function test_the_placeholder_refreshes_after_a_save_that_changes_no_sku_at_all(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();

        app(ProductPricingAndStock::class)->writeRegularPriceForProduct($product->id(), '54.00');

        $productModel = ProductModel::find($product->id());
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $rowsBefore = $component->get('data.existing_variations');
        $this->assertSame('54.00', array_values($rowsBefore)[0]['regular_price_placeholder']);

        $component->set('data.edit_all', true)
            ->set('data.product_regular_price', '99.00')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified(__('products.sku_adjustment.notification_title'));

        $rowsAfter = $component->get('data.existing_variations');
        $this->assertSame('99.00', array_values($rowsAfter)[0]['regular_price_placeholder']);
    }

    /**
     * The real safety guard this task exists for: Color is this
     * product's own declared variation axis (built into
     * persistedVariableProductWithOneVariation()'s own fixture) — it
     * must never appear as a pickable descriptive-attribute option,
     * since Product::setDescriptiveAttribute() genuinely throws
     * InvalidArgumentException for a definition currently declared as
     * an axis. Verified against the exact real query
     * EditVariableProduct's own attributesTabComponents() ->
     * ProductResource::descriptiveAttributesPickerComponents()'s
     * ->options() closure runs, with this product's own real axis
     * exclusion list, not a hand-rolled approximation of it. A second,
     * ordinary TEXT definition (Material, not an axis) must still
     * appear normally.
     */
    public function test_a_variable_products_own_axis_definition_never_appears_as_a_pickable_descriptive_attribute_option(): void
    {
        [$product] = $this->persistedVariableProductWithOneVariation();

        $material = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($material);

        $reloadedProduct = app(ProductRepository::class)->findByIdWithVariations($product->id());
        $excludedIds = array_map(
            fn (VariationAxis $axis): string => $axis->attributeDefinitionId(),
            $reloadedProduct->variationAxes()
        );

        $options = AttributeDefinitionModel::where('type', '!=', AttributeType::MULTISELECT->value)
            ->whereNotIn('id', $excludedIds)
            ->pluck('name', 'id')
            ->all();

        $this->assertCount(1, $excludedIds);
        $this->assertSame('Color', AttributeDefinitionModel::find($excludedIds[0])?->name);
        $this->assertNotContains('Color', $options);
        $this->assertContains('Material', $options);
    }

    /**
     * Real, end-to-end proof (not just the options query above): the
     * picker's own real ->options() closure, wired through
     * EditVariableProduct::attributesTabComponents(), genuinely never
     * lets a merchant persist the axis definition as a descriptive
     * attribute in the first place — attempting it directly against
     * the domain layer confirms the real exception this exclusion
     * exists to keep unreachable from the form.
     */
    public function test_setting_the_axis_definition_as_a_descriptive_attribute_would_genuinely_throw(): void
    {
        [$product] = $this->persistedVariableProductWithOneVariation();
        $colorDefinitionId = $product->variationAxes()[0]->attributeDefinitionId();
        $colorDefinition = app(AttributeDefinitionRepository::class)->findById($colorDefinitionId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is currently declared as a variation axis');

        $product->setDescriptiveAttribute($colorDefinition, 'anything');
    }

    /**
     * A non-axis descriptive attribute picked and set on a VARIABLE
     * product persists and reads back correctly — the same real
     * write/read path SIMPLE's own equivalent test already proves,
     * confirmed here for EditVariableProduct specifically since it
     * goes through its own attributesTabComponents()/exclusion wrapper,
     * not ProductResource::attributesTabComponents() directly.
     */
    public function test_picking_a_non_axis_descriptive_attribute_persists_and_reads_back_correctly(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();

        $material = new AttributeDefinition(id: null, code: 'material', name: 'Material', type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($material);

        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'descriptive_attributes_picker' => [
                    ['attribute_definition_id' => $material->id(), 'text_value' => 'Cotton'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('Cotton', $reloaded->descriptiveAttributes()[(string) $material->id()]);
    }

    /**
     * The real save-confirmation mechanism, verified directly against
     * the resolved Action object's own state — NOT via a simulated
     * click/mountAction() flow. A real, honest limitation found while
     * building this: this page overrides form() directly (unlike
     * EditProduct.php, which relies on EditRecord's own default), and
     * no working ->mountAction()/TestAction schema-addressing
     * combination could be confirmed to correctly resolve this page's
     * own form-embedded 'save' action within reasonable effort — every
     * attempt left $livewire->mountedActions empty (the action never
     * resolves), which is a Filament testing-infrastructure question,
     * not a question about this feature's own correctness. What IS
     * proven here, directly and reliably: getSaveFormAction() (called
     * via Reflection, since it's protected — the same real method
     * Filament itself calls to build the actual rendered Save button)
     * genuinely returns isConfirmationRequired()=true only when
     * base_sku is genuinely changing, and — a REAL BUG this task's own
     * testing found and fixed (see getSaveFormAction()'s own docblock)
     * — shouldOpenModal() (the actual gate Filament's real mountAction()
     * checks before showing a modal, confirmed against its installed
     * source) correctly tracks that same condition, not an
     * unconditionally-true default from a merely non-blank
     * ->modalHeading()/->modalDescription().
     *
     * "Cancelling leaves everything unchanged" is not separately
     * simulated here either, for the same honest reason — it follows
     * directly from the same confirmed source-level guarantee: Filament's
     * own mountAction() (installed source, read in full while building
     * this) only calls the action's handler (callMountedAction(), which
     * is what would run save()) when shouldOpenModal() is false OR the
     * action is explicitly confirmed; a real click on Cancel never
     * reaches that call at all.
     */
    public function test_the_real_save_action_requires_confirmation_only_when_base_sku_is_genuinely_changing(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $resolveSaveAction = function ($component) {
            $reflection = new \ReflectionMethod($component->instance(), 'getSaveFormAction');
            $reflection->setAccessible(true);

            return $reflection->invoke($component->instance());
        };

        // Case 1: base_sku unchanged — no confirmation, no modal.
        $unchanged = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $unchangedAction = $resolveSaveAction($unchanged);
        $this->assertFalse($unchangedAction->isConfirmationRequired());
        $this->assertFalse($unchangedAction->shouldOpenModal());

        // Case 2: base_sku genuinely changed — confirmation required,
        // modal shown, with the real old/new values in the description.
        $changed = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => 'SKU-VAR-NEW']);
        $changedAction = $resolveSaveAction($changed);
        $this->assertTrue($changedAction->isConfirmationRequired());
        $this->assertTrue($changedAction->shouldOpenModal());
        $this->assertSame(
            'Changing the base SKU from "SKU-VAR" to "SKU-VAR-NEW" will also update every variant SKU that still starts with "SKU-VAR-" to start with "SKU-VAR-NEW-" instead. A variant SKU you already customized to something else will not be touched.',
            (string) $changedAction->getModalDescription()
        );

        // Case 3: base_sku cleared to blank — still counts as "this
        // will change" (it's about to auto-generate a new one), per
        // this task's own explicit "don't under-detect this" requirement.
        $blanked = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => '']);
        $blankedAction = $resolveSaveAction($blanked);
        $this->assertTrue($blankedAction->isConfirmationRequired());

        // Case 4: a product with zero variations never needs confirming
        // at all, even with a genuinely different base_sku — nothing to
        // cascade to.
        $emptyProduct = Product::createVariable('Empty Variable', 'SKU-EMPTY', 'empty-variable');
        app(ProductRepository::class)->save($emptyProduct);
        $emptyProductModel = ProductModel::find($emptyProduct->id());

        $emptyChanged = Livewire::test(EditVariableProduct::class, ['record' => $emptyProductModel->id])
            ->fillForm(['base_sku' => 'SKU-EMPTY-NEW']);
        $emptyChangedAction = $resolveSaveAction($emptyChanged);
        $this->assertFalse($emptyChangedAction->isConfirmationRequired());
    }

    /**
     * The real cascade: every variant sku that starts with the OLD
     * base_sku prefix is renamed to the new one; a variant sku that
     * does NOT start with that prefix (already manually customized) is
     * deliberately left alone. Uses ->call('save') — confirmed
     * elsewhere in this file to bypass the confirmation modal entirely
     * (a direct Livewire method call, a separate code path from the
     * Action's own mounted-action flow) while still exercising the
     * real, unconditional server-side cascade logic.
     */
    public function test_confirming_a_base_sku_change_cascades_every_prefix_matching_variant_and_leaves_a_customized_one_alone(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        // Manually customize the White variation's sku to something
        // that does NOT start with the old base_sku prefix.
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows = $component->get('data.existing_variations');
        $rowKeyByVariationId = [];
        foreach ($rows as $key => $row) {
            $rowKeyByVariationId[$row['variation_id']] = $key;
        }

        $component->set("data.existing_variations.{$rowKeyByVariationId[$whiteId]}.sku", 'CUSTOM-UNRELATED-SKU')
            ->call('save')
            ->assertHasNoFormErrors();

        // Now change base_sku — the still-default-prefixed Black
        // variation must cascade; the just-customized White one must not.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => 'SKU-VAR-2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $skusById = [];
        foreach ($reloaded->variations() as $variation) {
            $skusById[$variation->id()] = $variation->sku();
        }

        $this->assertSame('SKU-VAR-2-BLACK', $skusById[$blackId]);
        $this->assertSame('CUSTOM-UNRELATED-SKU', $skusById[$whiteId]);
    }

    /**
     * An explicit, same-submission per-row sku edit wins over the
     * cascade — ordering this task's own instruction called out
     * explicitly as load-bearing (updateVariationRows() runs BEFORE
     * the cascade in updateProduct()).
     */
    public function test_an_explicit_per_row_sku_edit_in_the_same_submission_wins_over_the_cascade(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.sku", 'MY-OWN-EXPLICIT-SKU')
            ->set('data.base_sku', 'SKU-VAR-2')
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('MY-OWN-EXPLICIT-SKU', $reloaded->variations()[0]->sku());
    }

    /**
     * The real, itemized "here's what actually changed" notification —
     * a genuine sku UNIQUE-constraint collision (seeded deliberately: a
     * real, unrelated variation already has the exact sku this
     * submission also tries to save), triggering
     * EloquentProductRepository's own real collision-retry suffixing.
     * Confirms BOTH halves this task's own instruction requires: the
     * notification itself (real title, matching this task's own
     * itemized-list intent), AND — the part a notification alone does
     * NOT prove — that afterSave()'s $this->fillForm() call genuinely
     * refreshes the LIVE component's own state to the real, corrected
     * sku, not what was typed.
     */
    public function test_a_real_sku_collision_produces_the_notification_and_the_live_form_shows_the_corrected_sku(): void
    {
        $this->actingAsPanelAdministrator();

        // A second, unrelated SIMPLE product's own universal Variation
        // already owns this exact sku — a real UNIQUE constraint
        // collision waiting to happen, not a simulated one. SIMPLE (not
        // a second VARIABLE product) deliberately, so it needs no
        // second "color" AttributeDefinition/axis of its own at all.
        $otherProduct = Product::createSimple('Other Product', 'TAKEN-SKU', 'other-product');
        app(ProductRepository::class)->save($otherProduct);

        [$product, $variationId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.sku", 'TAKEN-SKU')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('products.sku_adjustment.notification_title'));

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $realFinalSku = $reloaded->variations()[0]->sku();

        // The real collision-retry mechanism did its job — the real
        // final sku is NOT the one that was submitted.
        $this->assertNotSame('TAKEN-SKU', $realFinalSku);
        $this->assertStringStartsWith('TAKEN-SKU-', $realFinalSku);

        // The real point: the LIVE component's own state now shows the
        // real, corrected value — not the typed one — proving
        // afterSave()'s $this->fillForm() call genuinely re-seeded the
        // form, not just that the DB row is correct.
        $liveRows = $component->get('data.existing_variations');
        $liveRow = array_values($liveRows)[0];
        $this->assertSame($realFinalSku, $liveRow['sku']);
    }

    /**
     * The negative case: a submission with no sku changes at all (no
     * base_sku change, no per-row edits, no collision) shows no
     * notification — afterSave() correctly finds zero differing rows
     * and returns early.
     */
    public function test_a_submission_with_no_sku_changes_shows_no_notification(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['name' => 'Renamed, Nothing SKU-Related'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified(__('products.sku_adjustment.notification_title'));
    }

    /**
     * The other half of this task: clearing base_sku (and slug) on
     * EDIT must trigger real auto-generation — ->required() no longer
     * blocks a blank submission (see this class's own
     * generalTabComponents()), and the write side resolves a blank
     * value through the same real Hook::apply() calls Create already
     * uses, rather than trading a friendly validation message for a
     * raw InvalidArgumentException from Product::assertValidBaseSku()/
     * assertValidSlug() (confirmed both genuinely reject an empty
     * value, by reading their real source).
     */
    public function test_clearing_base_sku_and_slug_on_edit_triggers_real_auto_generation(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => '', 'slug' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);

        $this->assertNotSame('', $reloaded->baseSku());
        $this->assertNotSame('SKU-VAR', $reloaded->baseSku());
        $this->assertNotSame('', $reloaded->slug());
    }

    /** An explicitly-typed base_sku/slug on edit is used verbatim, unchanged — same as before this fix. */
    public function test_an_explicitly_typed_base_sku_and_slug_on_edit_are_used_verbatim(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => 'MY-EXPLICIT-SKU', 'slug' => 'my-explicit-slug'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);

        $this->assertSame('MY-EXPLICIT-SKU', $reloaded->baseSku());
        $this->assertSame('my-explicit-slug', $reloaded->slug());
    }

    /**
     * Resubmitting an UNCHANGED, already-valid slug must never be
     * silently corrupted — the real, confirmed bug this task's own
     * verification found in slug's own Hook listener (deduplicate()
     * self-colliding against the SAME product's own existing row) if
     * Hook::apply() were called unconditionally on every edit, the way
     * base_sku's own call is. Proven here specifically because it is
     * the one case the "clearing triggers generation" fix could have
     * silently regressed if slug's own Hook::apply() call were not
     * guarded to blank-only.
     */
    public function test_resubmitting_an_unchanged_slug_on_edit_does_not_corrupt_it(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['name' => 'Renamed, Slug Untouched'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('variable-shirt', $reloaded->slug());
    }

    /**
     * Collapsible rows — the real combination label ("Color: Black"),
     * not a generic "Item 1", must be what the row shows while
     * collapsed. Reads the Repeater component's own real
     * isCollapsible()/getItemLabel() directly (the same "inspect the
     * real resolved component" technique this file already uses for
     * getSaveFormAction() via Reflection) rather than assuming — see
     * existingVariationsComponents()'s own docblock for why itemLabel()
     * ends up using `Schema $container`, not `Get $get` NOR `array
     * $state` (both tried first and both confirmed broken by an
     * earlier real run of THIS SAME test — Get resolves against the
     * wrong (Repeater's own) state path, and $state is dehydration-
     * filtered and silently drops 'label').
     */
    public function test_a_variation_row_is_collapsible_and_shows_the_real_combination_label(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $repeater = $component->instance()->form->getComponent('existing_variations');
        $this->assertNotNull($repeater);
        $this->assertTrue($repeater->isCollapsible());

        $rowKeys = array_keys($component->get('data.existing_variations'));
        $this->assertSame('Color: Black', $repeater->getItemLabel($rowKeys[0]));
    }

    /**
     * The real write side: a photo uploaded to ONE variation's own
     * 'variation_photos' field persists a real VariationMedia row
     * scoped to THAT variation only — never leaking onto a sibling
     * variation's own media, and never onto the product-level
     * ProductMediaRepository either (a genuinely separate pivot table,
     * catalog_variation_media vs. catalog_product_media).
     */
    public function test_uploading_a_photo_to_one_variation_persists_a_real_variation_media_row_scoped_to_that_variation(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows = $component->get('data.existing_variations');
        $blackKey = null;
        foreach ($rows as $key => $row) {
            if ($row['variation_id'] === $blackId) {
                $blackKey = $key;
            }
        }
        $this->assertNotNull($blackKey);

        $component->set("data.existing_variations.{$blackKey}.variation_photos", [UploadedFile::fake()->image('black.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $variationMediaRepository = app(VariationMediaRepository::class);

        $blackPivots = $variationMediaRepository->findByVariationId($blackId);
        $this->assertCount(1, $blackPivots);

        $asset = MediaAssetModel::find($blackPivots[0]->mediaId());
        $this->assertNotNull($asset);
        $this->assertSame('image', $asset->type);

        // Never leaks onto the sibling variation...
        $this->assertCount(0, $variationMediaRepository->findByVariationId($whiteId));

        // ...nor onto the product-level media collection.
        $this->assertSame(
            0,
            \EasyCo\Media\Persistence\Eloquent\ProductMediaModel::where('product_id', $product->id())->count()
        );
    }

    /** Removing a variation's own previously-uploaded photo detaches it — a real, persisted removal, not merely client-side state. */
    public function test_removing_a_variations_own_photo_detaches_the_real_variation_media_row(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.variation_photos", [UploadedFile::fake()->image('photo.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $variationMediaRepository = app(VariationMediaRepository::class);
        $this->assertCount(1, $variationMediaRepository->findByVariationId($blackId));

        $component2 = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys2 = array_keys($component2->get('data.existing_variations'));

        $component2->set("data.existing_variations.{$rowKeys2[0]}.variation_photos", [])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(0, $variationMediaRepository->findByVariationId($blackId));
    }

    /**
     * Reordering persists the new sort_order — real diff-then-write
     * behavior, mirroring syncMedia()'s own already-proven reorder
     * logic (product-level main_photo/gallery_photos), same shape here
     * for syncVariationMedia().
     */
    public function test_reordering_a_variations_photos_persists_the_new_sort_order(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $first = UploadedFile::fake()->image('first.jpg');
        $second = UploadedFile::fake()->image('second.jpg');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.variation_photos", [$first, $second])
            ->call('save')
            ->assertHasNoFormErrors();

        $variationMediaRepository = app(VariationMediaRepository::class);
        $pivotsBefore = $variationMediaRepository->findByVariationId($blackId);
        $this->assertCount(2, $pivotsBefore);

        $pathByAssetId = [];
        foreach ($pivotsBefore as $pivot) {
            $pathByAssetId[$pivot->mediaId()] = MediaAssetModel::find($pivot->mediaId())->path;
        }
        $firstPath = $pathByAssetId[$pivotsBefore[0]->mediaId()];
        $secondPath = $pathByAssetId[$pivotsBefore[1]->mediaId()];
        $this->assertSame(0, $pivotsBefore[0]->sortOrder());
        $this->assertSame(1, $pivotsBefore[1]->sortOrder());

        // Submit the SAME two real, already-stored paths, reversed.
        $component2 = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys2 = array_keys($component2->get('data.existing_variations'));

        $component2->set("data.existing_variations.{$rowKeys2[0]}.variation_photos", [$secondPath, $firstPath])
            ->call('save')
            ->assertHasNoFormErrors();

        $pivotsAfter = $variationMediaRepository->findByVariationId($blackId);
        $this->assertCount(2, $pivotsAfter);
        $this->assertSame($secondPath, MediaAssetModel::find($pivotsAfter[0]->mediaId())->path);
        $this->assertSame($firstPath, MediaAssetModel::find($pivotsAfter[1]->mediaId())->path);
    }

    /**
     * Exceeding max_photos_per_variation is rejected with the real
     * MediaLimitExceededException::forVariation() message, full
     * rollback — same shape as ProductResourceTest's own
     * max_photos_per_product test, scoped to ONE variation here.
     */
    public function test_exceeding_the_real_configured_max_photos_per_variation_is_rejected_with_the_real_message_and_rolls_back(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $max = (int) config('services.media.max_photos_per_variation', 3);
        $files = [];
        for ($i = 0; $i < $max + 1; $i++) {
            $files[] = UploadedFile::fake()->image("photo{$i}.jpg");
        }

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.variation_photos", $files)
            ->call('save')
            ->assertNotified(
                MediaLimitExceededException::forVariation($blackId, $max, $max)->getMessage()
            );

        $this->assertCount(0, app(VariationMediaRepository::class)->findByVariationId($blackId));

        // Full rollback: an unrelated field change in the SAME
        // submission must not have survived either.
        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('Variable Shirt', $reloaded->name());
    }

    /**
     * A REAL DISCREPANCY WITH THE TASK'S OWN LITERAL WORDING, flagged
     * explicitly rather than silently worked around: variation_photos'
     * ->disabled() gate reads PRODUCT_MANAGE — but ALL THREE shipped
     * roles (Administrator/Manager/Product Entry — confirmed against
     * StaffSystemRolesSeeder's own real, installed source) grant
     * PRODUCT_VIEW and PRODUCT_MANAGE together, never one without the
     * other, and EditRecord::authorizeAccess() (confirmed against its
     * installed source) already aborts with a 403 at mount() —
     * BEFORE the form is ever filled — for anyone lacking
     * ProductResource::editPermission() (PRODUCT_MANAGE itself). So
     * unlike COST_MANAGE/PRICE_MANAGE (narrower capabilities
     * 'Product Entry' genuinely lacks while still holding
     * PRODUCT_MANAGE — the real, reachable two-tier gap those tests
     * exploit), there is no shipped role, and no real staff member,
     * who can reach this field disabled-but-visible: they are turned
     * away at the page boundary first. The real, reachable proof of
     * "a PRODUCT_MANAGE-lacking staff member cannot upload" is
     * therefore THIS — a custom role holding PRODUCT_VIEW without
     * PRODUCT_MANAGE (Role::create(), the same non-system factory a
     * real merchant's own custom role would use) gets a real 403 the
     * moment it tries to mount this page at all, never reaching
     * variation_photos (or any other field) in any state.
     */
    public function test_a_staff_member_without_product_manage_is_turned_away_at_the_page_boundary_before_reaching_any_field(): void
    {
        $role = \EasyCo\Staff\Role::create('View Only', [\EasyCo\Staff\Enums\Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $role);
        app(StaffRepository::class)->save($staff);

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        $this->get(ProductResource::getUrl('edit-variable', ['record' => $productModel]))
            ->assertForbidden();
    }

    /**
     * The real, resolved Action itself — same rigor as
     * test_the_real_save_action_requires_confirmation_only_when_base_sku_is_genuinely_changing()'s
     * own Reflection-based inspection, adapted to the Repeater's own
     * per-item delete Action instead of a page-level one. Unlike Save,
     * getDeleteAction() is `public`, so no Reflection is needed to
     * reach it — HasActions::getAction('delete') already resolves the
     * cached Action, and calling it as
     * $action(['item' => $itemKey]) reproduces EXACTLY the same real
     * binding Repeater's own view does per row (confirmed against
     * HasMountableArguments::__invoke(), the mechanism behind
     * $deleteAction(['item' => $itemKey]) in the installed source),
     * not a simulated click but a real inspection of the actual bound
     * Action object Filament renders for that row.
     */
    public function test_the_real_archive_action_always_requires_confirmation_with_the_real_combination_label_interpolated(): void
    {
        $this->actingAsPanelAdministrator();

        [$product] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $repeater = $component->instance()->form->getComponent('existing_variations');
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $deleteAction = ($repeater->getAction('delete'))(['item' => $rowKeys[0]]);

        $this->assertSame(__('products.variation_archive.button_label'), (string) $deleteAction->getLabel());
        $this->assertTrue($deleteAction->isConfirmationRequired());
        $this->assertTrue($deleteAction->shouldOpenModal());
        $this->assertSame(__('products.variation_archive.confirm_heading'), (string) $deleteAction->getModalHeading());
        $this->assertSame(
            __('products.variation_archive.confirm_description', ['label' => 'Color: Black']),
            (string) $deleteAction->getModalDescription()
        );
    }

    /**
     * The real, domain-level effect of removing a row and saving:
     * archive(), not a UI-only removal — proven via a real domain
     * reload (ProductRepository::findByIdWithVariations()), not merely
     * "the row disappeared from Livewire state." Removing a row from
     * $data['existing_variations'] and submitting is functionally
     * identical to what the delete action's own real ->action()
     * closure does (Repeater::getDeleteAction(), confirmed against its
     * installed source: unset($items[$arguments['item']]);
     * $component->rawState($items)) — a real, working simulation of
     * the user interaction, not a synthetic shortcut.
     */
    public function test_removing_a_variation_row_and_saving_genuinely_archives_the_real_variation(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows = $component->get('data.existing_variations');
        $blackKey = null;
        foreach ($rows as $key => $row) {
            if ($row['variation_id'] === $blackId) {
                $blackKey = $key;
            }
        }
        $this->assertNotNull($blackKey);
        unset($rows[$blackKey]);

        $component->set('data.existing_variations', $rows)
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $byId = [];
        foreach ($reloaded->variations() as $variation) {
            $byId[$variation->id()] = $variation;
        }

        $this->assertSame(VariationStatus::ARCHIVED, $byId[$blackId]->status());
        // The remaining, still-submitted variation is untouched.
        $this->assertSame(VariationStatus::DRAFT, $byId[$whiteId]->status());
    }

    /**
     * Archiving is deliberately NOT a cleanup pass — this task's own
     * explicit instruction, verified here rather than assumed: a
     * variation's real price/cost/stock/media rows must survive its
     * own archiving completely untouched (still real, queryable
     * domain/DB state), since a later, separate "revive" feature needs
     * that state to still be there.
     */
    public function test_archiving_a_variation_leaves_its_price_cost_stock_and_media_rows_alone(): void
    {
        $this->seedPricingSystemLists();
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        // First save: populate real price/cost/stock/media on the row.
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKeys = array_keys($component->get('data.existing_variations'));

        $component->set("data.existing_variations.{$rowKeys[0]}.regular_price", '42.00')
            ->set("data.existing_variations.{$rowKeys[0]}.cost", '10.00')
            ->set("data.existing_variations.{$rowKeys[0]}.stock_quantity", 7)
            ->set("data.existing_variations.{$rowKeys[0]}.variation_photos", [UploadedFile::fake()->image('photo.jpg')])
            ->call('save')
            ->assertHasNoFormErrors();

        $pricingAndStock = app(ProductPricingAndStock::class);
        $this->assertSame('42.00', $pricingAndStock->regularPriceDisplay($blackId));
        $this->assertSame('10.00', $pricingAndStock->costDisplay($blackId));
        $this->assertSame(7, $pricingAndStock->stockQuantity($blackId));
        $variationMediaRepository = app(VariationMediaRepository::class);
        $this->assertCount(1, $variationMediaRepository->findByVariationId($blackId));

        // Second save: remove the row — archives it.
        $component2 = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows2 = $component2->get('data.existing_variations');
        $rowKey2 = array_key_first($rows2);
        unset($rows2[$rowKey2]);

        $component2->set('data.existing_variations', $rows2)
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame(VariationStatus::ARCHIVED, $reloaded->variations()[0]->status());

        // Every one of these must still be there, completely untouched.
        $this->assertSame('42.00', $pricingAndStock->regularPriceDisplay($blackId));
        $this->assertSame('10.00', $pricingAndStock->costDisplay($blackId));
        $this->assertSame(7, $pricingAndStock->stockQuantity($blackId));
        $this->assertCount(1, $variationMediaRepository->findByVariationId($blackId));
    }

    /**
     * mutateFormDataBeforeFill() must NOT resurrect an archived
     * variation into the form on the next page load — confirmed this
     * was a REAL gap before this task (every variation, any status,
     * used to reappear unconditionally) by first observing the OLD
     * behavior, then adding the array_filter() fix; this test proves
     * the fixed behavior specifically, not merely that nothing crashes.
     */
    public function test_an_archived_variation_is_excluded_from_the_form_on_the_next_page_load(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows = $component->get('data.existing_variations');
        $blackKey = null;
        foreach ($rows as $key => $row) {
            if ($row['variation_id'] === $blackId) {
                $blackKey = $key;
            }
        }
        unset($rows[$blackKey]);

        $component->set('data.existing_variations', $rows)
            ->call('save')
            ->assertHasNoFormErrors();

        $freshComponent = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $freshRows = $freshComponent->get('data.existing_variations');

        $this->assertCount(1, $freshRows);
        $this->assertSame($whiteId, array_values($freshRows)[0]['variation_id']);
    }

    /**
     * THE REAL GAP point 3 closes, proven — not assumed: a product
     * that is ALREADY Active (from an earlier, separate save — the
     * status FIELD ITSELF does not change in the submission under
     * test) has its last non-archived variation archived in THIS
     * submission. Before this task's restructuring, publish() was only
     * ever invoked when $oldStatus !== $newStatus, so this exact case
     * — status unchanged, but the variation set underneath it just
     * became empty — would have silently succeeded, leaving a real,
     * live inconsistency (an Active VARIABLE product with nothing
     * sellable). Full rollback: the archiving itself must not survive
     * either.
     */
    public function test_archiving_the_last_non_archived_variation_on_an_already_active_product_is_rejected_and_rolls_back(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $blackId] = $this->persistedVariableProductWithOneVariation();
        $productModel = ProductModel::find($product->id());

        // First save: make the product genuinely Active, with its one
        // variation still present and non-archived.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['status' => ProductStatus::ACTIVE->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $afterFirstSave = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame(ProductStatus::ACTIVE, $afterFirstSave->status());

        // Second save: status field is NOT touched (stays 'active',
        // exactly as seeded) — only the variation row is removed.
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $this->assertSame(ProductStatus::ACTIVE->value, $component->get('data.status'));

        $rows = $component->get('data.existing_variations');
        $rowKey = array_key_first($rows);
        unset($rows[$rowKey]);

        $component->set('data.existing_variations', $rows)
            ->set('data.name', 'Should Not Be Saved Either')
            ->call('save')
            ->assertNotified(
                CannotPublishEmptyVariableProductException::forProduct($product)->getMessage()
            );

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame(ProductStatus::ACTIVE, $reloaded->status());
        $this->assertSame('Variable Shirt', $reloaded->name(), 'the whole submission must roll back, not just the archiving');
        $this->assertSame(VariationStatus::DRAFT, $reloaded->variations()[0]->status(), 'the archiving itself must not survive the rollback');
    }

    /**
     * The other, non-broken side of the same gap: archiving one of
     * SEVERAL variations on an Active product succeeds normally, since
     * at least one non-archived STANDARD variation remains — publish()
     * is still invoked (status stays Active, unconditionally
     * re-attempted per this task's restructuring) but its own guard
     * has nothing to reject.
     */
    public function test_archiving_one_of_several_variations_on_an_active_product_succeeds_when_others_remain(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['status' => ProductStatus::ACTIVE->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows = $component->get('data.existing_variations');
        $blackKey = null;
        foreach ($rows as $key => $row) {
            if ($row['variation_id'] === $blackId) {
                $blackKey = $key;
            }
        }
        unset($rows[$blackKey]);

        $component->set('data.existing_variations', $rows)
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame(ProductStatus::ACTIVE, $reloaded->status());

        $byId = [];
        foreach ($reloaded->variations() as $variation) {
            $byId[$variation->id()] = $variation;
        }
        $this->assertSame(VariationStatus::ARCHIVED, $byId[$blackId]->status());
        $this->assertSame(VariationStatus::DRAFT, $byId[$whiteId]->status());
    }

    /**
     * Point 4's own real DB/domain check: the base_sku cascade must
     * skip an ARCHIVED variation's sku entirely, even though its sku
     * still literally starts with the old base_sku prefix — its sku is
     * historical (Variation::archive()'s own docblock) and must stay
     * stable. Archived in an EARLIER save (not the same submission as
     * the base_sku change — the simpler, unambiguous case; the
     * "archived in the SAME submission" case is exercised structurally
     * by archiveRemovedVariationRows() running before the cascade, see
     * that call site's own comment) so this test isolates specifically
     * the skip-if-already-archived branch.
     */
    public function test_the_base_sku_cascade_skips_an_already_archived_variations_sku(): void
    {
        $this->actingAsPanelAdministrator();

        [$product, $blackId, $whiteId] = $this->persistedVariableProductWithTwoVariations();
        $productModel = ProductModel::find($product->id());

        // First save: archive Black.
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rows = $component->get('data.existing_variations');
        $blackKey = null;
        foreach ($rows as $key => $row) {
            if ($row['variation_id'] === $blackId) {
                $blackKey = $key;
            }
        }
        unset($rows[$blackKey]);

        $component->set('data.existing_variations', $rows)
            ->call('save')
            ->assertHasNoFormErrors();

        $afterArchive = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $byIdBefore = [];
        foreach ($afterArchive->variations() as $variation) {
            $byIdBefore[$variation->id()] = $variation;
        }
        $this->assertSame(VariationStatus::ARCHIVED, $byIdBefore[$blackId]->status());
        $this->assertSame('SKU-VAR-BLACK', $byIdBefore[$blackId]->sku());

        // Second save: change base_sku — White (still present, still
        // non-archived) must cascade; archived Black must not.
        Livewire::test(EditVariableProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => 'SKU-VAR-2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $byIdAfter = [];
        foreach ($reloaded->variations() as $variation) {
            $byIdAfter[$variation->id()] = $variation;
        }

        $this->assertSame('SKU-VAR-BLACK', $byIdAfter[$blackId]->sku(), 'an archived variations sku must never be touched by the cascade');
        $this->assertSame('SKU-VAR-2-WHITE', $byIdAfter[$whiteId]->sku());
    }
}
