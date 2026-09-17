<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
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

    public function test_setting_descriptive_attributes_on_create_then_changing_and_clearing_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        $material = $this->persistedTextDefinition('material');
        [$color, $black, $white] = $this->persistedSelectDefinitionWithValues('color');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Plain Tee',
                'slug' => 'plain-tee',
                'base_sku' => 'SKU-TEE',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'descriptive_attributes' => [
                    $material->id() => 'Cotton',
                    $color->id() => $black->id(),
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'plain-tee')->firstOrFail();

        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $attributes = $product->descriptiveAttributes();
        $this->assertSame('Cotton', $attributes[(string) $material->id()]);
        $this->assertSame($black->id(), $attributes[(string) $color->id()]->id());

        // Change material, clear color.
        Livewire::test(EditProduct::class, ['record' => $productModel->id])
            ->fillForm([
                'descriptive_attributes' => [
                    $material->id() => 'Polyester',
                    $color->id() => null,
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $reloadedAttributes = $reloaded->descriptiveAttributes();
        $this->assertSame('Polyester', $reloadedAttributes[(string) $material->id()]);
        $this->assertArrayNotHasKey((string) $color->id(), $reloadedAttributes);
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
     * Supersedes an earlier, now-invalid version of this test that
     * asserted the duplicate action was merely HIDDEN on a VARIABLE
     * row (assertTableActionHidden) — that assertion itself requires
     * the record to still be resolvable within the table's own query,
     * which no longer holds true given the real fix below (the row is
     * excluded from the query entirely, not just given a hidden
     * action), so it started throwing "Record no longer exists"
     * instead of proving anything.
     *
     * The real fix for the real bug this confirms: a VARIABLE
     * product's row used to still appear in this table (SIMPLE-only by
     * this Resource's own scope), with a live Edit button that crashed
     * on $product->universalVariation()->barcode() — a VARIABLE
     * Product genuinely has no universal Variation. Filtered at the
     * table's own query level (->where('type', SIMPLE)), not just via
     * a hidden action, so the row is absent from the list entirely —
     * proven here via the real Filament assertCanNotSeeTableRecords()
     * assertion, which strictly subsumes "no action on it is visible
     * either," since there is no row to check an action against at all.
     */
    public function test_a_variable_product_does_not_appear_anywhere_in_the_list(): void
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
            ->assertCanNotSeeTableRecords([ProductModel::find($variableProduct->id())])
            ->assertCanSeeTableRecords([ProductModel::find($simpleProduct->id())]);
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
