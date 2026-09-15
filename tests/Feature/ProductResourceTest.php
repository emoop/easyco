<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
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
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Season;
use EasyCo\Catalog\Tag;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
                'description' => 'A classic silhouette.',
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
        $this->assertSame('A classic silhouette.', $product->description());
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
                'description' => 'Now with a real description.',
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
        $this->assertSame('Now with a real description.', $reloaded->description());
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

    public function test_uploading_photos_on_create_results_in_real_media_asset_and_product_media_rows(): void
    {
        $this->actingAsPanelAdministrator();

        \Illuminate\Support\Facades\Storage::fake(config('services.media.default_disk', 'public'));

        $file1 = UploadedFile::fake()->image('photo1.jpg');
        $file2 = UploadedFile::fake()->image('photo2.jpg');

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Photographed Product',
                'slug' => 'photographed-product',
                'base_sku' => 'SKU-PHOTO',
                'status' => ProductStatus::DRAFT->value,
                'catalog_visibility' => CatalogVisibility::HIDDEN->value,
                'photos' => [$file1, $file2],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $productModel = ProductModel::where('slug', 'photographed-product')->firstOrFail();
        $pivots = app(ProductMediaRepository::class)->findByProductId((string) $productModel->id);

        $this->assertCount(2, $pivots);
        $this->assertSame(0, $pivots[0]->sortOrder());
        $this->assertSame(1, $pivots[1]->sortOrder());
    }

    public function test_exceeding_the_real_configured_max_photo_count_is_rejected_with_the_real_message(): void
    {
        $this->actingAsPanelAdministrator();

        \Illuminate\Support\Facades\Storage::fake(config('services.media.default_disk', 'public'));

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
                'photos' => $files,
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

    public function test_a_products_row_navigates_to_view_and_edit_button_is_visible(): void
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
        $this->assertSame(ProductResource::getUrl('view', ['record' => $productModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(ProductResource::getUrl('edit', ['record' => $productModel]))->assertOk();
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

        foreach ($table->getRecords() as $record) {
            $actionNames = array_map(
                fn ($action) => $action->getName(),
                $table->getRecordActions($record)
            );
            $this->assertNotContains('delete', $actionNames);
        }

        $this->assertArrayNotHasKey('delete', ProductResource::getPages());
    }
}
