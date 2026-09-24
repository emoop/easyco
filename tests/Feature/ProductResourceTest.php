<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
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
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\ActionGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the real, production ProductResource — SIMPLE products only
 * (admin-panel-design.md §10 Part 5). Mirrors BrandResourceTest's own
 * established conventions exactly.
 */
class ProductResourceTest extends TestCase
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
        $category = new Category(id: null, parentId: null, name: $name, slug: strtolower($name));
        app(CategoryRepository::class)->save($category);

        return $category;
    }

    private function persistedTag(string $name): Tag
    {
        $tag = new Tag(id: null, name: $name, slug: strtolower($name));
        app(TagRepository::class)->save($tag);

        return $tag;
    }

    private function persistedTextDefinition(string $code = 'material'): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::TEXT);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue} */
    private function persistedSelectDefinitionWithValues(string $code = 'color'): array
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        return [$definition, $black, $white];
    }

    private function persistedNumberDefinition(string $code = 'weight'): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::NUMBER);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    private function persistedBooleanDefinition(string $code = 'is_waterproof'): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: ucfirst($code), type: AttributeType::BOOLEAN);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    public function test_creating_a_simple_product_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand();
        $season = $this->persistedSeason();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Air Max',
                'slug' => 'air-max',
                'base_sku' => 'SKU-1',
                'barcode' => '1234567890123',
                'description' => '<p>A <strong>classic</strong> silhouette.</p>',
                'status' => ProductStatus::ACTIVE->value,
                'catalog_visibility' => CatalogVisibility::VISIBLE->value,
                'is_purchasable' => false,
                'brand_id' => $brand->id(),
                'season_id' => $season->id(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'air-max')->firstOrFail();
        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);

        $this->assertSame('Air Max', $product->name());
        $this->assertSame('air-max', $product->slug());
        $this->assertSame('SKU-1', $product->baseSku());
        // RichEditor's real output — confirmed against
        // RichEditorStateCast::get() (a plain HTML string via
        // getHtml(), not JSON, since ProductModel doesn't implement
        // HasRichContent), round-tripped through the domain layer
        // exactly as submitted.
        $this->assertSame('<p>A <strong>classic</strong> silhouette.</p>', $product->description());
        $this->assertSame(ProductStatus::ACTIVE, $product->status());
        $this->assertSame(CatalogVisibility::VISIBLE, $product->catalogVisibility());
        $this->assertSame($brand->id(), $product->brandId());
        $this->assertSame($season->id(), $product->seasonId());

        $universal = $product->universalVariation();
        $this->assertSame('1234567890123', $universal->barcode());
        $this->assertFalse($universal->isPurchasable());
    }

    public function test_leaving_slug_and_base_sku_blank_on_create_triggers_the_real_hook_based_generation(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Air Force 1',
                'slug' => '',
                'base_sku' => '',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('name', 'Air Force 1')->firstOrFail();

        // The real 'catalog.product.slug' listener generates from the
        // name; the real 'catalog.product.base_sku' listener generates
        // from the persistent sequence — identical to what
        // ProductController::store() already produces via the API, per
        // admin-panel-design.md §9's own "must not diverge" principle.
        $this->assertNotSame('', $productModel->slug);
        $this->assertNotSame('', $productModel->base_sku);
        $this->assertNotNull($productModel->slug);
        $this->assertNotNull($productModel->base_sku);
    }

    public function test_editing_updates_only_the_fields_that_actually_changed(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand('Adidas');
        $season = $this->persistedSeason('Fall/Winter 2026');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Stan Smith',
                'slug' => 'stan-smith',
                'base_sku' => 'SKU-STAN',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'stan-smith')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'name' => 'Stan Smith Classic',
                'slug' => 'stan-smith-classic',
                'base_sku' => 'SKU-STAN-2',
                'description' => '<p>Now with a <strong>real</strong> description.</p>',
                'status' => ProductStatus::ACTIVE->value,
                'catalog_visibility' => CatalogVisibility::VISIBLE->value,
                'brand_id' => $brand->id(),
                'season_id' => $season->id(),
                'barcode' => '9998887776665',
                'is_purchasable' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);

        $this->assertSame('Stan Smith Classic', $reloaded->name());
        $this->assertSame('stan-smith-classic', $reloaded->slug());
        $this->assertSame('SKU-STAN-2', $reloaded->baseSku());
        $this->assertSame('<p>Now with a <strong>real</strong> description.</p>', $reloaded->description());
        $this->assertSame(ProductStatus::ACTIVE, $reloaded->status());
        $this->assertSame(CatalogVisibility::VISIBLE, $reloaded->catalogVisibility());
        $this->assertSame($brand->id(), $reloaded->brandId());
        $this->assertSame($season->id(), $reloaded->seasonId());
        $this->assertSame('9998887776665', $reloaded->universalVariation()->barcode());
        $this->assertFalse($reloaded->universalVariation()->isPurchasable());
    }

    /**
     * Clearing base_sku/slug on EDIT must trigger real auto-generation,
     * exactly like Create already does — not a validation block.
     * ->required() no longer blocks a blank submission here (see
     * ProductResource::generalTabComponents()'s own field definitions);
     * the write side resolves a blank value through the same real
     * Hook::apply() calls CreateProduct already uses, rather than
     * trading a friendly validation message for a raw
     * InvalidArgumentException from Product::assertValidBaseSku()/
     * assertValidSlug() (confirmed both genuinely reject an empty
     * value, by reading their real source).
     */
    public function test_clearing_base_sku_and_slug_on_edit_triggers_real_auto_generation(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Clear Sku Test',
                'slug' => 'clear-sku-test',
                'base_sku' => 'SKU-CLEAR',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'clear-sku-test')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->fillForm(['base_sku' => '', 'slug' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);

        $this->assertNotSame('', $reloaded->baseSku());
        $this->assertNotSame('SKU-CLEAR', $reloaded->baseSku());
        $this->assertNotSame('', $reloaded->slug());
    }

    /** An explicitly-typed base_sku/slug on edit is used verbatim, unchanged — same as before this fix. */
    public function test_an_explicitly_typed_base_sku_and_slug_on_edit_are_used_verbatim(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Verbatim Sku Test',
                'slug' => 'verbatim-sku-test',
                'base_sku' => 'SKU-VERBATIM',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'verbatim-sku-test')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $productModel->id])
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
     * base_sku's own call is. This is why slug's own resolution is
     * guarded to blank-only in EditProduct::updateProduct(), unlike
     * base_sku's unconditional call.
     */
    public function test_resubmitting_an_unchanged_slug_on_edit_does_not_corrupt_it(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Slug Stability Test',
                'slug' => 'slug-stability-test',
                'base_sku' => 'SKU-STABLE',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'slug-stability-test')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->fillForm(['name' => 'Renamed, Slug Untouched'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertSame('slug-stability-test', $reloaded->slug());
    }

    public function test_assigning_categories_and_tags_on_create_then_changing_the_selection_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        $sneakers = $this->persistedCategory('Sneakers');
        $boots = $this->persistedCategory('Boots');
        $summer = $this->persistedTag('Summer');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Chelsea Boot',
                'slug' => 'chelsea-boot',
                'base_sku' => 'SKU-BOOT',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'categories' => [$sneakers->id()],
                'tags' => [$summer->id()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'chelsea-boot')->firstOrFail();
        $productId = (string) $productModel->id;

        $categoryIds = array_map(fn ($c) => $c->categoryId(), app(ProductCategoryRepository::class)->findByProductId($productId));
        $this->assertSame([$sneakers->id()], $categoryIds);

        $tagIds = array_map(fn ($t) => $t->tagId(), app(ProductTagRepository::class)->findByProductId($productId));
        $this->assertSame([$summer->id()], $tagIds);

        // Add boots, remove sneakers; remove the tag entirely.
        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'categories' => [$boots->id()],
                'tags' => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $categoryIdsAfter = array_map(fn ($c) => $c->categoryId(), app(ProductCategoryRepository::class)->findByProductId($productId));
        $this->assertSame([$boots->id()], $categoryIdsAfter);

        $tagIdsAfter = array_map(fn ($t) => $t->tagId(), app(ProductTagRepository::class)->findByProductId($productId));
        $this->assertSame([], $tagIdsAfter);
    }

    /**
     * UPDATED for the Repeater-based descriptive-attributes picker
     * redesign: 'descriptive_attributes' (one always-rendered field per
     * definition) is replaced by 'descriptive_attributes_picker' (a
     * Repeater of {attribute_definition_id, text_value, boolean_value,
     * select_value} rows — the merchant now explicitly picks which
     * attributes apply, rather than every definition always having its
     * own field). Rewritten here in place, not left stale/false —
     * extended to cover all four real value types (TEXT/NUMBER/
     * BOOLEAN/SELECT) in one flow, since the picker now genuinely
     * treats all four identically (one row schema, ->visible() scoped
     * per row to the picked definition's type), unlike the old
     * per-definition-field design.
     */
    public function test_picking_descriptive_attributes_of_every_type_on_create_then_changing_and_removing_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        $material = $this->persistedTextDefinition('material');
        $weight = $this->persistedNumberDefinition('weight');
        $waterproof = $this->persistedBooleanDefinition('is_waterproof');
        [$color, $black, $white] = $this->persistedSelectDefinitionWithValues('color');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Plain Tee',
                'slug' => 'plain-tee',
                'base_sku' => 'SKU-TEE',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'descriptive_attributes_picker' => [
                    ['attribute_definition_id' => $material->id(), 'text_value' => 'Cotton'],
                    ['attribute_definition_id' => $weight->id(), 'text_value' => '250'],
                    ['attribute_definition_id' => $waterproof->id(), 'boolean_value' => true],
                    ['attribute_definition_id' => $color->id(), 'select_value' => $black->id()],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'plain-tee')->firstOrFail();

        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $attributes = $product->descriptiveAttributes();
        $this->assertSame('Cotton', $attributes[(string) $material->id()]);
        $this->assertSame('250', $attributes[(string) $weight->id()]);
        $this->assertSame('1', $attributes[(string) $waterproof->id()]);
        $this->assertSame($black->id(), $attributes[(string) $color->id()]->id());

        // The picker seeds back correctly too — real read-side proof,
        // not just the write side.
        $seededRows = Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->get('data.descriptive_attributes_picker');
        $this->assertCount(4, $seededRows);

        // Change material and weight, remove the boolean row entirely
        // (deleting a Repeater row is the new "clear this attribute"),
        // keep color unchanged by simply re-submitting its own row.
        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'descriptive_attributes_picker' => [
                    ['attribute_definition_id' => $material->id(), 'text_value' => 'Polyester'],
                    ['attribute_definition_id' => $weight->id(), 'text_value' => '300'],
                    ['attribute_definition_id' => $color->id(), 'select_value' => $white->id()],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $reloadedAttributes = $reloaded->descriptiveAttributes();
        $this->assertSame('Polyester', $reloadedAttributes[(string) $material->id()]);
        $this->assertSame('300', $reloadedAttributes[(string) $weight->id()]);
        $this->assertSame($white->id(), $reloadedAttributes[(string) $color->id()]->id());
        // The removed row's attribute is genuinely gone, not just
        // "untouched" — the real point of the "deleted row = no longer
        // applies" requirement.
        $this->assertArrayNotHasKey((string) $waterproof->id(), $reloadedAttributes);
    }

    /**
     * main_photo and gallery_photos are two separate Filament fields
     * (this task's own WooCommerce-style split), but still merge into
     * ONE ordered MediaType::IMAGE collection underneath — main_photo
     * always sort_order 0, per ProductMedia's own "no is_primary field,
     * sortOrder = 0 is implicitly primary" decision.
     */
    public function test_uploading_a_main_photo_and_gallery_photos_on_create_results_in_real_media_asset_and_product_media_rows(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        $mainPhoto = UploadedFile::fake()->image('main.jpg');
        $galleryPhoto = UploadedFile::fake()->image('gallery.jpg');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Photographed Product',
                'slug' => 'photographed-product',
                'base_sku' => 'SKU-PHOTO',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'main_photo' => $mainPhoto,
                'gallery_photos' => [$galleryPhoto],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'photographed-product')->firstOrFail();
        $pivots = app(ProductMediaRepository::class)->findByProductId((string) $productModel->id);

        $this->assertCount(2, $pivots);
        $this->assertSame(0, $pivots[0]->sortOrder());
        $this->assertSame(1, $pivots[1]->sortOrder());
    }

    /**
     * A real regression test for a real bug caught in a live browser
     * check: the empty-state placeholder text ("Drag & Drop... max N
     * MB") showed "1 MB" for BOTH a 10 MB image limit and a 100 MB
     * video limit — rtrim(..., '0') on a bare (string) cast of a whole
     * float (10.0 casts to "10", no decimal point at all) strips a
     * trailing zero from the INTEGER part itself, not a fractional one.
     * Must show the real configured MB values, not "1" for both.
     */
    public function test_the_media_upload_placeholders_show_the_real_configured_max_sizes_in_mb(): void
    {
        $this->actingAsPanelAdministrator();

        config(['services.media.max_image_size_kb' => 10240, 'services.media.max_video_size_kb' => 102400]);

        $form = Livewire::test(CreateProduct::class)->instance()->form;

        $this->assertStringContainsString('10 MB', (string) $form->getComponent('main_photo')->getPlaceholder());
        $this->assertStringContainsString('10 MB', (string) $form->getComponent('gallery_photos')->getPlaceholder());
        $this->assertStringContainsString('100 MB', (string) $form->getComponent('video')->getPlaceholder());
    }

    /**
     * A real, confirmed gap: overriding Livewire's own global 12MB
     * temporary-upload rule (config/livewire.php — the actual, real
     * cause of a "must not be greater than 12288 kilobytes" error a
     * real video upload hit in practice, well below this field's own
     * intended 100MB) up to the larger of the two real media limits
     * ALSO removed the incidental ceiling that used to (accidentally)
     * cap oversized photos too. ->maxSize() on each field is the real,
     * intentional replacement — must actually reject a file over its
     * own configured limit, not just show informational text about it.
     */
    public function test_a_main_photo_larger_than_the_real_configured_image_limit_is_rejected(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        $maxImageKb = (int) config('services.media.max_image_size_kb', 10240);
        $maxVideoKb = (int) config('services.media.max_video_size_kb', 102400);

        // Deliberately between the two limits, not just $maxImageKb + 1:
        // config/livewire.php's own global temporary-upload rule is set
        // to the LARGER of the two real media limits (max_video_size_kb
        // here), so a file only slightly over the image limit could
        // still be swallowed by that earlier, coarser layer before ever
        // reaching this field's own ->maxSize() — asserting a genuinely
        // different, real "form has errors" outcome than that layer's
        // own (untestable via this harness — see this test's sibling
        // video-upload-limit note). Using the midpoint isolates THIS
        // field's own maxSize() specifically.
        $oversizeKb = intdiv($maxImageKb + $maxVideoKb, 2);
        $this->assertGreaterThan($maxImageKb, $oversizeKb);
        $this->assertLessThan($maxVideoKb, $oversizeKb);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Oversized Photo Product',
                'slug' => 'oversized-photo-product',
                'base_sku' => 'SKU-BIGPHOTO',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'main_photo' => UploadedFile::fake()->create('too-big.jpg', $oversizeKb, 'image/jpeg'),
            ])
            ->call('create')
            // Not ->assertHasFormErrors(['main_photo' => 'max']): the
            // FileUpload field validates file size via its own nested
            // Validator::make() call internally (BaseFileUpload's real
            // source), surfacing only that nested validator's rendered
            // message string on the outer schema, not a bare 'max' rule
            // name Livewire's own failedRules() could match — confirmed
            // by running this exact assertion and reading the real
            // failure output. Presence of any error on the field is
            // still a real, meaningful assertion that ->maxSize() did
            // reject the file, not a false positive from a coincidence.
            ->assertHasFormErrors(['main_photo']);

        $this->assertNull(ProductModel::where('slug', 'oversized-photo-product')->first());
    }

    /**
     * Single video per product (this task's own explicit scope) — the
     * autoplay toggle's value must land on the video's own
     * ProductMedia pivot row (media-domain-design.md §2.1: the
     * per-attachment record, not MediaAsset itself).
     */
    public function test_uploading_a_video_with_autoplay_persists_a_real_media_asset_and_pivot_row(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Video Product',
                'slug' => 'video-product',
                'base_sku' => 'SKU-VIDEO',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'video' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
                'video_autoplay' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'video-product')->firstOrFail();
        $pivots = app(ProductMediaRepository::class)->findByProductId((string) $productModel->id);

        $this->assertCount(1, $pivots);
        $this->assertTrue($pivots[0]->autoplay());

        $asset = MediaAssetModel::find($pivots[0]->mediaId());
        $this->assertSame('video', $asset->type);
        // §3.6: video has no processing pipeline — never dispatched
        // into PENDING/PROCESSING, straight to ready.
        $this->assertSame('ready', $asset->processing_status);
    }

    /**
     * Real edit round-trip: the form must be seeded with the real saved
     * video path and its real autoplay value (not silently defaulted —
     * the exact class of bug EditProduct's barcode/is_purchasable fix
     * addressed earlier this session), and toggling autoplay off must
     * actually persist.
     */
    public function test_editing_can_change_the_video_and_toggle_autoplay(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Video Edit Product',
                'slug' => 'video-edit-product',
                'base_sku' => 'SKU-VIDEDIT',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'video' => UploadedFile::fake()->create('original.mp4', 500, 'video/mp4'),
                'video_autoplay' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'video-edit-product')->firstOrFail();

        $editComponent = Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->assertFormSet(['video_autoplay' => true]);

        $editComponent
            ->fillForm(['video' => UploadedFile::fake()->create('replacement.mp4', 500, 'video/mp4'), 'video_autoplay' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $pivots = app(ProductMediaRepository::class)->findByProductId((string) $productModel->id);
        $this->assertCount(1, $pivots, 'the old video must be detached when replaced, not left attached alongside the new one');
        $this->assertFalse($pivots[0]->autoplay());
    }

    public function test_exceeding_the_real_configured_max_photo_count_is_rejected_with_the_real_message(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        $max = (int) config('services.media.max_photos_per_product', 10);
        $files = [];
        for ($i = 0; $i < $max + 1; $i++) {
            $files[] = UploadedFile::fake()->image("photo{$i}.jpg");
        }

        // AUTO_INCREMENT is not transactional in MySQL/InnoDB — never
        // rolled back, including by RefreshDatabase's own per-test
        // transaction rollback for every EARLIER test in this same
        // run. MAX(id) on the currently-visible (post-rollback, so
        // empty-of-prior-tests') rows is therefore NOT a reliable
        // predictor of the next id — it undercounts by however many
        // rows every previous test already inserted-then-rolled-back
        // this run. The real next id is read directly from MySQL's own
        // AUTO_INCREMENT counter instead, which is what this
        // about-to-be-rolled-back Product will actually briefly hold
        // inside the transaction — letting the real, exact
        // MediaLimitExceededException message be predicted and
        // asserted, not just its rollback effect.
        $expectedProductId = (string) DB::table('information_schema.tables')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'catalog_products')
            ->value('AUTO_INCREMENT');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Too Many Photos',
                'slug' => 'too-many-photos',
                'base_sku' => 'SKU-MANY',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'gallery_photos' => $files,
            ])
            ->call('create')
            ->assertNotified(
                MediaLimitExceededException::forProduct($expectedProductId, $max, $max)->getMessage()
            );

        $this->assertNull(ProductModel::where('slug', 'too-many-photos')->first(), 'the whole creation must roll back on a media-limit rejection');
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(ProductResource::getUrl('index'))->assertOk();
        $this->get(ProductResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductResource::getUrl('index'))->assertOk();
        $this->get(ProductResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductResource::getUrl('index'))->assertOk();
        $this->get(ProductResource::getUrl('create'))->assertOk();
    }

    public function test_editing_is_allowed_for_all_three_shipped_roles_too(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Matrix Product', 'slug' => 'matrix-product', 'base_sku' => 'SKU-MATRIX', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value])
            ->call('create')
            ->assertHasNoFormErrors();
        $productModel = ProductModel::where('slug', 'matrix-product')->firstOrFail();

        $this->get(ProductResource::getUrl('edit', ['record' => $productModel->id]))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductResource::getUrl('edit', ['record' => $productModel->id]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(ProductResource::getUrl('edit', ['record' => $productModel->id]))->assertOk();
    }

    /**
     * Edit is the most-used action on this list, so a row click routes
     * there by default for anyone who can edit — the three-dot
     * ActionGroup (View/Edit/Duplicate) stays available regardless.
     */
    public function test_a_products_row_navigates_to_edit_by_default_for_a_staff_member_who_can_edit(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Navigable Product', 'slug' => 'navigable-product', 'base_sku' => 'SKU-NAV', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value])
            ->call('create')
            ->assertHasNoFormErrors();
        $productModel = ProductModel::where('slug', 'navigable-product')->firstOrFail();

        $component = Livewire::test(ListProducts::class);
        $component->assertTableActionVisible('edit', $productModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($productModel);
        $this->assertSame(ProductResource::getUrl('edit', ['record' => $productModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(ProductResource::getUrl('view', ['record' => $productModel]))->assertOk();
    }

    /**
     * A real regression guard for the recordUrl() ternary itself: every
     * shipped system role (Administrator/Manager/Product Entry) happens
     * to hold PRODUCT_MANAGE, so this proves the View-only fallback
     * branch with a custom Role that deliberately does NOT — otherwise
     * a swapped ternary (or one hardcoded to always resolve 'edit')
     * would pass every other test in this file undetected.
     */
    public function test_a_products_row_falls_back_to_view_for_a_staff_member_who_cannot_edit(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'View Only Product', 'slug' => 'view-only-product', 'base_sku' => 'SKU-VIEWONLY', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value])
            ->call('create')
            ->assertHasNoFormErrors();
        $productModel = ProductModel::where('slug', 'view-only-product')->firstOrFail();

        $viewOnlyRole = Role::create('View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($viewOnlyRole);

        $staff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $viewOnlyRole);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        $component = Livewire::test(ListProducts::class);
        $component->assertTableActionHidden('edit', $productModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($productModel);
        $this->assertSame(ProductResource::getUrl('view', ['record' => $productModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(ProductResource::getUrl('edit', ['record' => $productModel]))->assertForbidden();
    }

    public function test_no_delete_action_exists_anywhere_on_this_resource(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'No Delete Product', 'slug' => 'no-delete-product', 'base_sku' => 'SKU-NODEL', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value])
            ->call('create')
            ->assertHasNoFormErrors();
        $productModel = ProductModel::where('slug', 'no-delete-product')->firstOrFail();

        $component = Livewire::test(ListProducts::class);
        $table = $component->instance()->getTable();

        // Record actions are grouped into a single ActionGroup
        // (View/Edit/Duplicate) — getFlatActions() is the real
        // ActionGroup method for flattening back to a plain action
        // list, confirmed against the installed source.
        foreach ($table->getRecords() as $record) {
            $actionNames = [];
            foreach ($table->getRecordActions($record) as $action) {
                $actionNames = [
                    ...$actionNames,
                    ...($action instanceof ActionGroup
                        ? array_map(fn ($a) => $a->getName(), $action->getFlatActions())
                        : [$action->getName()]),
                ];
            }
            $this->assertNotContains('delete', $actionNames);
        }

        $this->assertArrayNotHasKey('delete', ProductResource::getPages());
    }

    /**
     * CatalogSettings' four field-visibility toggles — every field
     * shown by default (an installation that never visits the settings
     * page keeps today's exact behavior), and each hides independently
     * once its own toggle is turned off. Checked on CreateProduct/
     * EditVariableProduct (the two places brand_id/product_group_id are
     * authored fresh, per ProductResource's own brandFieldEnabled()
     * docblock) — the sidebar Sections (Season/Tags) are shared code
     * (ProductResource::sidebarComponents()), so one check there covers
     * every page that reuses it.
     */
    public function test_all_four_catalog_fields_are_visible_by_default(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->assertFormFieldVisible('brand_id')
            ->assertFormFieldVisible('product_group_id')
            ->assertFormFieldVisible('season_id')
            ->assertFormFieldVisible('tags');
    }

    public function test_each_catalog_field_hides_independently_once_its_own_toggle_is_off(): void
    {
        $this->actingAsPanelAdministrator();
        $settings = app(SiteSettingsRepository::class);

        $settings->set('catalog.brand_field_enabled', '0');
        Livewire::test(CreateProduct::class)
            ->assertFormFieldHidden('brand_id')
            ->assertFormFieldVisible('product_group_id')
            ->assertFormFieldVisible('season_id')
            ->assertFormFieldVisible('tags');
        $settings->set('catalog.brand_field_enabled', '1');

        $settings->set('catalog.season_field_enabled', '0');
        Livewire::test(CreateProduct::class)->assertFormFieldHidden('season_id');
        $settings->set('catalog.season_field_enabled', '1');

        $settings->set('catalog.tags_field_enabled', '0');
        Livewire::test(CreateProduct::class)->assertFormFieldHidden('tags');
        $settings->set('catalog.tags_field_enabled', '1');

        $settings->set('catalog.product_group_field_enabled', '0');
        Livewire::test(CreateProduct::class)->assertFormFieldHidden('product_group_id');
    }

    /**
     * EditVariableProduct authors brand_id/product_group_id fresh
     * (ProductResource::form() is not reused there) — a separate,
     * explicit check that the same setting reaches this second call
     * site too, not just SIMPLE's own CreateProduct/EditProduct.
     */
    public function test_brand_and_group_fields_also_hide_on_the_variable_product_edit_page(): void
    {
        $this->actingAsPanelAdministrator();
        app(SiteSettingsRepository::class)->set('catalog.brand_field_enabled', '0');
        app(SiteSettingsRepository::class)->set('catalog.product_group_field_enabled', '0');

        $variableProduct = Product::createVariable('Hidden Fields Product', 'SKU-HIDDEN-FIELDS', 'hidden-fields-product');
        app(ProductRepository::class)->save($variableProduct);

        Livewire::test(EditVariableProduct::class, ['record' => $variableProduct->id()])
            ->assertFormFieldHidden('brand_id')
            ->assertFormFieldHidden('product_group_id');
    }

    public function test_creating_without_a_group_succeeds_when_the_setting_is_off_by_default(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'No Group Required',
                'slug' => 'no-group-required',
                'base_sku' => 'SKU-NOGROUP',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNotNull(ProductModel::where('slug', 'no-group-required')->first());
    }

    public function test_creating_without_a_group_fails_form_validation_when_the_setting_is_on(): void
    {
        $this->actingAsPanelAdministrator();

        app(SiteSettingsRepository::class)->set('catalog.product_group_required', '1');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Group Required',
                'slug' => 'group-required',
                'base_sku' => 'SKU-GROUPREQ',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
            ])
            ->call('create')
            ->assertHasErrors(['data.product_group_id' => 'required']);

        $this->assertNull(ProductModel::where('slug', 'group-required')->first());
    }

    public function test_the_thumbnail_column_shows_the_main_photos_real_path(): void
    {
        $this->actingAsPanelAdministrator();

        Storage::fake(config('services.media.default_disk', 'public'));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Thumbnail Product',
                'slug' => 'thumbnail-product',
                'base_sku' => 'SKU-THUMB',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'main_photo' => UploadedFile::fake()->image('first.jpg'),
                'gallery_photos' => [UploadedFile::fake()->image('second.jpg')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'thumbnail-product')->firstOrFail();
        $pivots = app(ProductMediaRepository::class)->findByProductId((string) $productModel->id);
        $firstPivot = $pivots[0];
        $expectedPath = MediaAssetModel::find($firstPivot->mediaId())->path;

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('thumbnail_path', $expectedPath, record: $productModel);
    }

    public function test_the_thumbnail_column_renders_without_error_for_a_product_with_no_photos(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'No Photo Product', 'slug' => 'no-photo-product', 'base_sku' => 'SKU-NOPHOTO', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'no-photo-product')->firstOrFail();

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('thumbnail_path', null, record: $productModel);
    }

    public function test_duplicating_a_simple_product_persists_a_real_new_product_with_every_rule_applied(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand();
        $season = $this->persistedSeason();
        $group = $this->persistedProductGroup();
        $sneakers = $this->persistedCategory('Sneakers');
        $summer = $this->persistedTag('Summer');
        $material = $this->persistedTextDefinition('material');
        [$color, $black] = $this->persistedSelectDefinitionWithValues('color');

        $source = Product::createSimple('Air Max', 'SKU-SOURCE', 'air-max');
        $source->publish();
        $source->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $source->assignBrand($brand->id());
        $source->assignSeason($season->id());
        $source->assignProductGroup($group->id());
        $source->changeDescription('A classic silhouette.');
        $source->setDescriptiveAttribute($material, 'Leather');
        $source->setDescriptiveAttribute($color, $black);
        $source->universalVariation()->setBarcode('1112223334445');
        $source->universalVariation()->setPurchasable(false);
        app(ProductRepository::class)->save($source);

        app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $source->id(), categoryId: $sneakers->id()));
        app(ProductTagRepository::class)->save(new ProductTag(id: null, productId: $source->id(), tagId: $summer->id()));

        Livewire::test(ListProducts::class)
            ->callTableAction('duplicate', ProductModel::find($source->id()));

        $duplicateModel = ProductModel::where('slug', '!=', 'air-max')->where('name', 'like', 'Air Max%')->firstOrFail();
        $duplicate = app(ProductRepository::class)->findByIdWithVariations((string) $duplicateModel->id);

        $this->assertSame("Air Max ({$this->translatedDuplicateSuffix()})", $duplicate->name());
        $this->assertNotSame('air-max', $duplicate->slug());
        $this->assertNotSame('SKU-SOURCE', $duplicate->baseSku());
        $this->assertNotSame('', $duplicate->baseSku());
        $this->assertSame(ProductStatus::DRAFT, $duplicate->status());
        $this->assertSame(CatalogVisibility::VISIBLE, $duplicate->catalogVisibility());
        $this->assertSame($brand->id(), $duplicate->brandId());
        $this->assertSame($season->id(), $duplicate->seasonId());
        $this->assertSame($group->id(), $duplicate->productGroupId());
        $this->assertSame('A classic silhouette.', $duplicate->description());

        $attributes = $duplicate->descriptiveAttributes();
        $this->assertSame('Leather', $attributes[(string) $material->id()]);
        $this->assertSame($black->id(), $attributes[(string) $color->id()]->id());

        $categoryIds = array_map(fn ($c) => $c->categoryId(), app(ProductCategoryRepository::class)->findByProductId($duplicate->id()));
        $this->assertSame([$sneakers->id()], $categoryIds);

        $tagIds = array_map(fn ($t) => $t->tagId(), app(ProductTagRepository::class)->findByProductId($duplicate->id()));
        $this->assertSame([$summer->id()], $tagIds);

        // Barcode/is_purchasable NOT copied — flagged assumption, not
        // explicitly confirmed by §13.2's own text (see DuplicateProduct's docblock).
        $this->assertNull($duplicate->universalVariation()->barcode());
        $this->assertTrue($duplicate->universalVariation()->isPurchasable());

        // Photos explicitly NOT copied.
        $this->assertCount(0, app(ProductMediaRepository::class)->findByProductId($duplicate->id()));
    }

    public function test_duplicating_a_simple_product_redirects_to_the_new_products_real_edit_url(): void
    {
        $this->actingAsPanelAdministrator();

        $source = Product::createSimple('Air Force 1', 'SKU-AF1', 'air-force-1');
        app(ProductRepository::class)->save($source);

        $component = Livewire::test(ListProducts::class)
            ->callTableAction('duplicate', ProductModel::find($source->id()));

        $duplicateModel = ProductModel::where('name', 'like', 'Air Force 1%')
            ->where('id', '!=', $source->id())
            ->firstOrFail();

        $component->assertRedirect(ProductResource::getUrl('edit', ['record' => $duplicateModel->id]));
    }

    /**
     * VARIABLE duplication — product-duplication-and-templates-note.md's
     * own domain-owner decision: the "tedious" parent fields (brand,
     * season, product group, categories, tags, description, descriptive
     * attributes) are copied exactly like SIMPLE, but declared axes and
     * variations are NEVER copied — the duplicate is a genuine
     * zero-axes VARIABLE product, not a partial copy with a fallback.
     */
    public function test_duplicating_a_variable_product_copies_the_tedious_fields_but_never_axes_or_variations(): void
    {
        $this->actingAsPanelAdministrator();

        $brand = $this->persistedBrand();
        $season = $this->persistedSeason();
        $group = $this->persistedProductGroup();
        $sneakers = $this->persistedCategory('Sneakers');
        $summer = $this->persistedTag('Summer');
        $material = $this->persistedTextDefinition('material');
        [$color, $black] = $this->persistedSelectDefinitionWithValues('color');
        [$size, $medium] = $this->persistedSelectDefinitionWithValues('size');

        $source = Product::createVariable('Variable Air Max', 'SKU-VAR-SOURCE', 'variable-air-max');
        $source->setCatalogVisibility(CatalogVisibility::VISIBLE);
        $source->assignBrand($brand->id());
        $source->assignSeason($season->id());
        $source->assignProductGroup($group->id());
        $source->changeDescription('A classic silhouette, with sizes.');
        $source->setDescriptiveAttribute($material, 'Leather');
        $source->setDescriptiveAttribute($color, $black);
        $source->declareVariationAxes([new VariationAxis($size, [$medium])]);
        $source->addStandardVariation([$size->id() => $medium->id()], 'SKU-VAR-SOURCE-M');
        app(ProductRepository::class)->save($source);

        app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $source->id(), categoryId: $sneakers->id()));
        app(ProductTagRepository::class)->save(new ProductTag(id: null, productId: $source->id(), tagId: $summer->id()));

        Livewire::test(ListProducts::class)
            ->callTableAction('duplicate', ProductModel::find($source->id()));

        $duplicateModel = ProductModel::where('slug', '!=', 'variable-air-max')
            ->where('name', 'like', 'Variable Air Max%')
            ->firstOrFail();
        $duplicate = app(ProductRepository::class)->findByIdWithVariations((string) $duplicateModel->id);

        $this->assertSame("Variable Air Max ({$this->translatedDuplicateSuffix()})", $duplicate->name());
        $this->assertNotSame('variable-air-max', $duplicate->slug());
        $this->assertNotSame('SKU-VAR-SOURCE', $duplicate->baseSku());
        $this->assertSame(ProductStatus::DRAFT, $duplicate->status());
        $this->assertSame(CatalogVisibility::VISIBLE, $duplicate->catalogVisibility());
        $this->assertSame($brand->id(), $duplicate->brandId());
        $this->assertSame($season->id(), $duplicate->seasonId());
        $this->assertSame($group->id(), $duplicate->productGroupId());
        $this->assertSame('A classic silhouette, with sizes.', $duplicate->description());

        $attributes = $duplicate->descriptiveAttributes();
        $this->assertSame('Leather', $attributes[(string) $material->id()]);
        $this->assertSame($black->id(), $attributes[(string) $color->id()]->id());

        $categoryIds = array_map(fn ($c) => $c->categoryId(), app(ProductCategoryRepository::class)->findByProductId($duplicate->id()));
        $this->assertSame([$sneakers->id()], $categoryIds);

        $tagIds = array_map(fn ($t) => $t->tagId(), app(ProductTagRepository::class)->findByProductId($duplicate->id()));
        $this->assertSame([$summer->id()], $tagIds);

        // The real, permanent rule this task settled: never copied.
        $this->assertSame([], $duplicate->variationAxes());
        $this->assertCount(0, $duplicate->variations());

        // Photos explicitly NOT copied, same as SIMPLE.
        $this->assertCount(0, app(ProductMediaRepository::class)->findByProductId($duplicate->id()));
    }

    public function test_duplicating_a_variable_product_redirects_to_the_new_products_real_edit_variable_url(): void
    {
        $this->actingAsPanelAdministrator();

        $source = Product::createVariable('Variable Air Force 1', 'SKU-VAR-AF1', 'variable-air-force-1');
        app(ProductRepository::class)->save($source);

        $component = Livewire::test(ListProducts::class)
            ->callTableAction('duplicate', ProductModel::find($source->id()));

        $duplicateModel = ProductModel::where('name', 'like', 'Variable Air Force 1%')
            ->where('id', '!=', $source->id())
            ->firstOrFail();

        $component->assertRedirect(ProductResource::getUrl('edit-variable', ['record' => $duplicateModel->id]));
    }

    /**
     * UPDATED: this test previously asserted the opposite
     * (assertCanNotSeeTableRecords for the VARIABLE row) — that was the
     * real behavior of an earlier, deliberately narrower fix
     * (->where('type', SIMPLE) in modifyQueryUsing()), which has since
     * been superseded by a later task's own requirement: both SIMPLE
     * and VARIABLE products now show in the list by default. The real
     * crash this test's own history is about
     * ($product->universalVariation()->barcode() on null — a VARIABLE
     * Product genuinely has no universal Variation) is now kept
     * unreachable at the action/routing level instead of by excluding
     * the row — see test_a_variable_products_edit_action_is_hidden_...
     * and test_a_variable_products_record_url_always_points_to_view
     * below, which are the real regression coverage for that crash
     * now. Renamed and rewritten in place, not left stale/false.
     */
    public function test_both_simple_and_variable_products_appear_in_the_list(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-M');
        app(ProductRepository::class)->save($variableProduct);

        $simpleProduct = Product::createSimple('Simple Shirt', 'SKU-SIMPLE', 'simple-shirt');
        app(ProductRepository::class)->save($simpleProduct);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([ProductModel::find($variableProduct->id())])
            ->assertCanSeeTableRecords([ProductModel::find($simpleProduct->id())]);
    }

    /**
     * UPDATED: now that EditVariableProduct exists, recordUrl() no
     * longer routes a VARIABLE row away from editing entirely — a
     * staff member who can genuinely edit (canEdit() true) is routed
     * to 'edit-variable', mirroring exactly how a SIMPLE row routes to
     * 'edit'. A view-only staff member still always falls back to
     * 'view', for both types, unchanged.
     */
    public function test_a_variable_products_record_url_points_to_edit_variable_for_a_staff_member_who_can_edit(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-M');
        app(ProductRepository::class)->save($variableProduct);

        $variableModel = ProductModel::find($variableProduct->id());

        $table = Livewire::test(ListProducts::class)->instance()->getTable();
        $recordUrl = $table->getRecordUrl($variableModel);

        $this->assertSame(ProductResource::getUrl('edit-variable', ['record' => $variableModel]), $recordUrl);
    }

    public function test_a_variable_products_record_url_still_falls_back_to_view_for_a_staff_member_without_edit_rights(): void
    {
        $viewOnlyRole = Role::create('View Only For Record Url', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($viewOnlyRole);
        $viewOnlyStaff = Staff::create('view.only.recordurl@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only For Record Url', $viewOnlyRole);
        app(StaffRepository::class)->save($viewOnlyStaff);
        $this->actingAs(StaffPanelUser::find($viewOnlyStaff->id()), 'staff');

        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-M');
        app(ProductRepository::class)->save($variableProduct);

        $variableModel = ProductModel::find($variableProduct->id());

        $table = Livewire::test(ListProducts::class)->instance()->getTable();
        $recordUrl = $table->getRecordUrl($variableModel);

        $this->assertSame(ProductResource::getUrl('view', ['record' => $variableModel]), $recordUrl);
    }

    /**
     * UPDATED: EditAction is now VISIBLE for both a VARIABLE row and a
     * SIMPLE row (canEdit() is the only gate now that EditVariableProduct
     * exists) — what differs per type is the URL it routes to, checked
     * here directly via ->getUrl() rather than visibility.
     *
     * NOT using ->assertTableActionVisible() here — confirmed via
     * direct tracing against the installed Filament v5.8.1 source that
     * this helper is unreliable for an action nested in an ActionGroup
     * (EditAction is, via the ViewAction/EditAction/duplicateAction
     * ActionGroup in table()) once more than one table row exists:
     * resolveTableAction() only sets the new record on the action's
     * ActionGroup (getRootGroup()?->record($record)), but a real prior
     * full-table Blade render already left the CHILD action's own
     * $record property populated directly (from the LAST row
     * rendered) — and Action::getRecord() checks its own $record
     * before ever falling back to its group's, so the group-level
     * update is silently ignored and the stale, last-rendered-row
     * record wins. Setting ->record() directly on the resolved Action
     * (not the group) is what the underlying Blade per-row render loop
     * itself actually does, and reproduces the real rendering behavior
     * correctly and reliably — confirmed by tracing getRecord()->id()
     * matches the intended record after doing so.
     */
    public function test_edit_action_is_visible_for_both_types_and_routes_to_the_right_edit_page(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-M');
        app(ProductRepository::class)->save($variableProduct);

        $simpleProduct = Product::createSimple('Simple Shirt', 'SKU-SIMPLE', 'simple-shirt');
        app(ProductRepository::class)->save($simpleProduct);

        $variableModel = ProductModel::find($variableProduct->id());
        $simpleModel = ProductModel::find($simpleProduct->id());

        $editAction = Livewire::test(ListProducts::class)->instance()->getTable()->getAction('edit');

        $editAction->record($variableModel);
        $this->assertTrue($editAction->isVisible());
        $this->assertSame(ProductResource::getUrl('edit-variable', ['record' => $variableModel]), $editAction->getUrl());

        $editAction->record($simpleModel);
        $this->assertTrue($editAction->isVisible());
        $this->assertSame(ProductResource::getUrl('edit', ['record' => $simpleModel]), $editAction->getUrl());
    }

    public function test_edit_action_is_hidden_on_both_types_for_a_staff_member_without_edit_rights(): void
    {
        $viewOnlyRole = Role::create('View Only For Edit Action', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($viewOnlyRole);
        $viewOnlyStaff = Staff::create('view.only.editaction@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only For Edit Action', $viewOnlyRole);
        app(StaffRepository::class)->save($viewOnlyStaff);
        $this->actingAs(StaffPanelUser::find($viewOnlyStaff->id()), 'staff');

        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-M');
        app(ProductRepository::class)->save($variableProduct);

        $simpleProduct = Product::createSimple('Simple Shirt', 'SKU-SIMPLE', 'simple-shirt');
        app(ProductRepository::class)->save($simpleProduct);

        $editAction = Livewire::test(ListProducts::class)->instance()->getTable()->getAction('edit');

        $editAction->record(ProductModel::find($variableProduct->id()));
        $this->assertTrue($editAction->isHidden());

        $editAction->record(ProductModel::find($simpleProduct->id()));
        $this->assertTrue($editAction->isHidden());
    }

    /**
     * Already proven safe in Step A/C's own CreateVariableProductTest
     * (test_after_creation_the_redirect_lands_on_view_and_it_renders_
     * for_a_variation_less_product, and a variation-bearing equivalent)
     * — a quick reconfirmation here, since a VARIABLE product now
     * routes here from the list itself too, not just after creation.
     */
    public function test_view_page_still_renders_for_a_variable_product_with_a_real_variation(): void
    {
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-M');
        app(ProductRepository::class)->save($variableProduct);

        $variableModel = ProductModel::find($variableProduct->id());

        $this->get(ProductResource::getUrl('view', ['record' => $variableModel]))
            ->assertOk()
            ->assertSee('Variable Shirt');

        Livewire::test(ViewProduct::class, ['record' => $variableModel->id])
            ->assertSuccessful();
    }

    /**
     * Prompt B's own View-page rendering, the VARIABLE half of the
     * task's required coverage: two differently-priced variations must
     * show 'от …' on regular_price (hasUniformRegularPrice() false) —
     * confirms the infolist reads a real, resolver-backed PriceRange,
     * not the old universalVariationId()-only lookup a VARIABLE product
     * never actually had a value for.
     */
    public function test_view_page_shows_from_prefix_for_a_variable_products_non_uniform_regular_price(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsPanelAdministrator();

        $definition = new AttributeDefinition(id: null, code: 'size-from', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $small = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'S');
        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($small);
        app(AttributeValueRepository::class)->save($medium);

        $variableProduct = Product::createVariable('Variable From', 'SKU-VAR-FROM', 'variable-from');
        $variableProduct->declareVariationAxes([new VariationAxis($definition, [$small, $medium])]);
        $variationSmall = $variableProduct->addStandardVariation([$definition->id() => $small->id()], 'SKU-VAR-FROM-S');
        $variationSmall->activate();
        $variationMedium = $variableProduct->addStandardVariation([$definition->id() => $medium->id()], 'SKU-VAR-FROM-M');
        $variationMedium->activate();
        app(ProductRepository::class)->save($variableProduct);

        $regularList = app(PriceListRepository::class)->findSystemListByName('Regular Prices');
        $addItem = function (string $variationId, string $decimal) use ($regularList): void {
            app(PriceListItemRepository::class)->save(new PriceListItem(
                id: null,
                priceListId: $regularList->id(),
                targetType: PriceListItemTargetType::VARIATION,
                targetId: $variationId,
                price: Price::inclusiveOfTax(Money::fromDecimal($decimal, 'EUR'), 0),
            ));
        };
        $addItem($variationSmall->id(), '49.99');
        $addItem($variationMedium->id(), '69.99');

        $variableModel = ProductModel::find($variableProduct->id());

        Livewire::test(ViewProduct::class, ['record' => $variableModel->id])
            ->assertSchemaComponentStateSet('regular_price', __('products.price_from').' 49.99 €')
            ->assertSchemaComponentStateSet('sale_price', null);
    }

    private function translatedDuplicateSuffix(): string
    {
        return __('products.duplicate_suffix');
    }

    /**
     * Regression test for a real bug: mutateFormDataBeforeFill() never
     * seeded is_purchasable/barcode (both live on the universal
     * Variation, not ProductModel), so every Edit form open silently
     * fell back to the field's own ->default() regardless of the real
     * saved value — this must fail against the pre-fix code (the form
     * would show is_purchasable=true, barcode=null instead of the real
     * saved false/'9998887776665') and pass after it.
     */
    public function test_the_edit_form_is_seeded_with_the_products_real_saved_barcode_and_is_purchasable(): void
    {
        $this->actingAsPanelAdministrator();

        $product = Product::createSimple('Seeded Values Product', 'SKU-SEEDED', 'seeded-values-product');
        $product->universalVariation()->setBarcode('9998887776665');
        $product->universalVariation()->setPurchasable(false);
        app(ProductRepository::class)->save($product);

        $productModel = ProductModel::where('slug', 'seeded-values-product')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->assertFormSet([
                'barcode' => '9998887776665',
                'is_purchasable' => false,
            ]);
    }

    public function test_archived_products_are_hidden_from_the_default_list_query(): void
    {
        $this->actingAsPanelAdministrator();

        $active = Product::createSimple('Active Product', 'SKU-ACTIVE', 'active-product');
        app(ProductRepository::class)->save($active);

        $archived = Product::createSimple('Archived Product', 'SKU-ARCHIVED', 'archived-product');
        $archived->archive();
        app(ProductRepository::class)->save($archived);

        $activeModel = ProductModel::where('slug', 'active-product')->firstOrFail();
        $archivedModel = ProductModel::where('slug', 'archived-product')->firstOrFail();

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$activeModel])
            ->assertCanNotSeeTableRecords([$archivedModel]);
    }

    public function test_the_archived_only_filter_shows_only_archived_products_and_excludes_the_rest(): void
    {
        $this->actingAsPanelAdministrator();

        $active = Product::createSimple('Active Product Two', 'SKU-ACTIVE-2', 'active-product-two');
        app(ProductRepository::class)->save($active);

        $archived = Product::createSimple('Archived Product Two', 'SKU-ARCHIVED-2', 'archived-product-two');
        $archived->archive();
        app(ProductRepository::class)->save($archived);

        $activeModel = ProductModel::where('slug', 'active-product-two')->firstOrFail();
        $archivedModel = ProductModel::where('slug', 'archived-product-two')->firstOrFail();

        Livewire::test(ListProducts::class)
            ->filterTable('archived_only', true)
            ->assertCanSeeTableRecords([$archivedModel])
            ->assertCanNotSeeTableRecords([$activeModel]);
    }

    public function test_the_status_fields_help_text_mentions_the_real_archive_consequence(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateProduct::class)
            ->assertSee(__('products.fields.status_archive_warning'));
    }

    public function test_the_list_shows_each_products_real_categories_instead_of_the_created_at_date(): void
    {
        $this->actingAsPanelAdministrator();

        $sneakers = $this->persistedCategory('Sneakers');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Categorized Product',
                'slug' => 'categorized-product',
                'base_sku' => 'SKU-CATLIST',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'categories' => [$sneakers->id()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'categorized-product')->firstOrFail();

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('categories.name', ['Sneakers'], record: $productModel)
            ->assertTableColumnDoesNotExist('created_at');
    }

    public function test_the_categories_filter_shows_only_products_in_the_selected_category(): void
    {
        $this->actingAsPanelAdministrator();

        $sneakers = $this->persistedCategory('Sneakers');
        $boots = $this->persistedCategory('Boots');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Sneaker Product',
                'slug' => 'sneaker-product',
                'base_sku' => 'SKU-SNEAKER',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'categories' => [$sneakers->id()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Boot Product',
                'slug' => 'boot-product',
                'base_sku' => 'SKU-BOOT-FILTER',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'categories' => [$boots->id()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sneakerModel = ProductModel::where('slug', 'sneaker-product')->firstOrFail();
        $bootModel = ProductModel::where('slug', 'boot-product')->firstOrFail();

        Livewire::test(ListProducts::class)
            ->filterTable('categories', $sneakers->id())
            ->assertCanSeeTableRecords([$sneakerModel])
            ->assertCanNotSeeTableRecords([$bootModel]);
    }
}
