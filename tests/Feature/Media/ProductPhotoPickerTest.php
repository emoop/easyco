<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Livewire\MediaPickerBrowser;
use App\Media\MediaFilters;
use App\Media\Usage\MediaUsageRegistry;
use App\Storefront\Reader\CatalogReader;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * ML-3 §1: the product photos through the media picker. The form state is MEDIA IDS (main photo first = sort_order 0, then the gallery); the save is an id diff against the image pivots:
 * a removed photo is only DETACHED (the asset stays in «Медия»), a new one goes through ProductMediaCountGuard, the order is written as sort_order, and nothing a browser sends is trusted.
 */
class ProductPhotoPickerTest extends TestCase
{
    use BuildsImageFixtures;
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
        $this->actingAsStaff('Administrator');
    }

    private function product(string $slug = 'photo-product', string $name = 'Photo Product'): ProductModel
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper($slug), $slug);
        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    private function attach(ProductModel $product, int $mediaId, int $sort): void
    {
        app(ProductMediaRepository::class)->save(new ProductMedia(id: null, productId: (string) $product->id, mediaId: (string) $mediaId, sortOrder: $sort));
    }

    /** @return array<int, int> media id => sort_order, in sort order */
    private function pivots(ProductModel $product): array
    {
        return ProductMediaModel::query()->where('product_id', $product->id)->orderBy('sort_order')->pluck('sort_order', 'media_id')->map(fn ($v): int => (int) $v)->all();
    }

    private function edit(ProductModel $product)
    {
        return Livewire::test(EditProduct::class, ['record' => $product->id]);
    }

    private function jpeg(int $w, int $h): string
    {
        return $this->capture(function () use ($w, $h): void {
            $image = imagecreatetruecolor($w, $h);
            imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 10, 120, 200));
            imagejpeg($image);
        });
    }

    // ---- the form state ----------------------------------------------------------------------------------------------------------

    public function test_the_edit_form_is_filled_with_ids_main_photo_first(): void
    {
        $product = $this->product();
        [$a, $b, $c] = [$this->pickerAsset()->id, $this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($product, $b, 0);
        $this->attach($product, $a, 1);
        $this->attach($product, $c, 2);

        $this->edit($product)->assertFormSet(['main_photo_id' => $b, 'gallery_photo_ids' => [$a, $c]]);
    }

    public function test_the_gallery_picker_holds_one_less_than_the_per_product_maximum(): void
    {
        config(['services.media.max_photos_per_product' => 6]);
        $form = Livewire::test(CreateProduct::class)->instance()->form;

        $this->assertSame(5, $form->getComponent('gallery_photo_ids')->getMaxItems());
        $this->assertFalse($form->getComponent('main_photo_id')->isMultiple());
    }

    // ---- create ------------------------------------------------------------------------------------------------------------------

    public function test_create_with_pictures_uploaded_through_the_picker_attaches_those_assets(): void
    {
        $uploaded = [];

        foreach (['main.jpg', 'second.jpg'] as $name) {
            $browser = Livewire::test(MediaPickerBrowser::class, ['field' => 'data.main_photo_id', 'tab' => 'upload', 'minWidth' => 600, 'minHeight' => 600])
                ->set('files', [UploadedFile::fake()->createWithContent($name, $this->jpeg(700, 700))]);
            $this->assertSame([], $browser->get('refused'));
            $uploaded[] = $browser->get('selected')[0];
        }

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Uploaded', 'slug' => 'uploaded', 'base_sku' => 'SKU-UP', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'main_photo_id' => $uploaded[0], 'gallery_photo_ids' => [$uploaded[1]]])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = ProductModel::where('slug', 'uploaded')->firstOrFail();
        $this->assertSame([$uploaded[0] => 0, $uploaded[1] => 1], $this->pivots($product));
        $this->assertSame(2, MediaAssetModel::query()->count(), 'no second asset is made from a path: the picker made them');
    }

    public function test_create_refuses_a_client_sent_path_or_garbage_instead_of_an_id(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Evil', 'slug' => 'evil', 'base_sku' => 'SKU-EVIL', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'main_photo_id' => 'uploads/2026/10/x.jpg', 'gallery_photo_ids' => ['../../etc/passwd', 0, -4]])
            ->call('create')
            ->assertHasFormErrors(['main_photo_id', 'gallery_photo_ids']);

        $this->assertNull(ProductModel::where('slug', 'evil')->first());
        $this->assertSame(0, ProductMediaModel::query()->count());
    }

    public function test_create_refuses_an_id_that_does_not_exist(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Ghost', 'slug' => 'ghost', 'base_sku' => 'SKU-GHOST', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'main_photo_id' => 987654])
            ->call('create')
            ->assertHasFormErrors(['main_photo_id']);

        $this->assertNull(ProductModel::where('slug', 'ghost')->first());
    }

    // ---- edit: order, detach -----------------------------------------------------------------------------------------------------

    public function test_reordering_the_gallery_and_swapping_the_main_photo_changes_sort_order(): void
    {
        $product = $this->product();
        [$a, $b, $c] = [$this->pickerAsset()->id, $this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($product, $a, 0);
        $this->attach($product, $b, 1);
        $this->attach($product, $c, 2);
        $before = ProductMediaModel::query()->where('product_id', $product->id)->pluck('id', 'media_id')->all();

        $this->edit($product)->fillForm(['main_photo_id' => $a, 'gallery_photo_ids' => [$c, $b]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$a => 0, $c => 1, $b => 2], $this->pivots($product));

        $this->edit($product)->fillForm(['main_photo_id' => $b, 'gallery_photo_ids' => [$a, $c]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$b => 0, $a => 1, $c => 2], $this->pivots($product));
        $this->assertSame($before, ProductMediaModel::query()->where('product_id', $product->id)->pluck('id', 'media_id')->all(), 'the pivots are kept and re-ordered, not deleted and made again');
    }

    public function test_removing_a_photo_only_detaches_it_and_the_asset_becomes_unused(): void
    {
        $product = $this->product();
        [$a, $b] = [$this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($product, $a, 0);
        $this->attach($product, $b, 1);
        $this->assertSame([], MediaFilters::use(MediaAssetModel::query(), 'unused')->pluck('id')->all());

        $this->edit($product)->fillForm(['gallery_photo_ids' => []])->call('save')->assertHasNoFormErrors();

        $this->assertSame([$a => 0], $this->pivots($product));
        $this->assertNotNull(MediaAssetModel::find($b), 'the asset stays in «Медия»');
        $this->assertSame([$b], MediaFilters::use(MediaAssetModel::query(), 'unused')->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'and is «неизползвана» now');
    }

    public function test_removing_the_main_photo_makes_the_first_gallery_photo_the_main_one(): void
    {
        $product = $this->product();
        [$a, $b] = [$this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($product, $a, 0);
        $this->attach($product, $b, 1);

        $this->edit($product)->fillForm(['main_photo_id' => null])->call('save')->assertHasNoFormErrors();

        $this->assertSame([$b => 0], $this->pivots($product), 'sort_order 0 is the main photo, as before (no is_primary field)');
    }

    public function test_adding_a_picked_photo_keeps_the_existing_ones(): void
    {
        $product = $this->product();
        [$a, $b, $new] = [$this->pickerAsset()->id, $this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($product, $a, 0);
        $this->attach($product, $b, 1);

        $this->edit($product)->fillForm(['gallery_photo_ids' => [$b, $new]])->call('save')->assertHasNoFormErrors();

        $this->assertSame([$a => 0, $b => 1, $new => 2], $this->pivots($product));
    }

    // ---- refusals ----------------------------------------------------------------------------------------------------------------

    public function test_the_same_asset_as_main_photo_and_in_the_gallery_is_refused(): void
    {
        $product = $this->product();
        [$a, $b] = [$this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($product, $a, 0);

        $component = $this->edit($product)->fillForm(['main_photo_id' => $a, 'gallery_photo_ids' => [$b, $a]])->call('save');

        $component->assertHasFormErrors(['gallery_photo_ids']);
        $this->assertContains('Тази снимка вече е главна снимка', $component->errors()->get('data.gallery_photo_ids'));
        $this->assertSame([$a => 0], $this->pivots($product), 'nothing was saved');
    }

    public function test_the_same_asset_cannot_be_twice_in_the_gallery(): void
    {
        $product = $this->product();
        $a = $this->pickerAsset()->id;

        $this->edit($product)->fillForm(['gallery_photo_ids' => [$a, $a]])->call('save')->assertHasFormErrors(['gallery_photo_ids']);
        $this->assertSame([], $this->pivots($product));
    }

    public function test_a_video_id_in_a_photo_field_is_refused(): void
    {
        $product = $this->product();
        $video = $this->pickerVideo()->id;

        $this->edit($product)->fillForm(['main_photo_id' => $video])->call('save')->assertHasFormErrors(['main_photo_id']);
        $this->edit($product)->fillForm(['gallery_photo_ids' => [$video]])->call('save')->assertHasFormErrors(['gallery_photo_ids']);
        $this->assertSame([], $this->pivots($product));
    }

    public function test_a_social_image_is_not_a_product_photo(): void
    {
        $product = $this->product();
        $social = $this->pickerAsset(['type' => 'social_image'])->id;

        $this->edit($product)->fillForm(['main_photo_id' => $social])->call('save')->assertHasFormErrors(['main_photo_id']);
        $this->assertSame([], $this->pivots($product));
    }

    public function test_more_than_the_gallery_maximum_is_refused_by_the_field(): void
    {
        config(['services.media.max_photos_per_product' => 3]);
        $product = $this->product();
        $ids = array_map(fn () => $this->pickerAsset()->id, range(1, 3));

        $this->edit($product)->fillForm(['gallery_photo_ids' => $ids])->call('save')->assertHasFormErrors(['gallery_photo_ids']);
        $this->assertSame([], $this->pivots($product));
    }

    public function test_a_picture_below_the_minimum_size_is_refused_but_an_old_small_photo_of_the_product_is_kept(): void
    {
        $product = $this->product();
        $oldSmall = $this->pickerAsset(['width' => 320, 'height' => 240])->id;
        $newSmall = $this->pickerAsset(['width' => 320, 'height' => 240])->id;
        $unknown = $this->pickerAsset(['width' => null, 'height' => null])->id;
        $this->attach($product, $oldSmall, 0);

        $this->edit($product)->fillForm(['gallery_photo_ids' => [$newSmall]])->call('save')->assertHasFormErrors(['gallery_photo_ids']);
        $this->edit($product)->fillForm(['gallery_photo_ids' => [$unknown]])->call('save')->assertHasFormErrors(['gallery_photo_ids']);
        $this->assertSame([$oldSmall => 0], $this->pivots($product));

        $this->edit($product)->call('save')->assertHasNoFormErrors();
        $this->assertSame([$oldSmall => 0], $this->pivots($product), 'a no-op save of a product with an old small photo still works');
    }

    public function test_a_photo_beyond_the_count_guard_shows_the_guards_message_and_saves_nothing(): void
    {
        // the guard counts EVERY pivot of the product (the video too): a video + main + 9 gallery photos is one too many, although the gallery field itself (max 9) accepts the list
        $max = (int) config('services.media.max_photos_per_product', 10);
        $product = $this->product();
        $video = $this->pickerVideo()->id;
        $keep = array_map(fn () => $this->pickerAsset()->id, range(1, $max - 2));
        $this->attach($product, $video, 0);

        foreach ($keep as $i => $id) {
            $this->attach($product, $id, $i);
        }

        $before = $this->pivots($product);
        $this->assertCount($max - 1, $before);
        $newOnes = array_map(fn () => $this->pickerAsset()->id, range(1, 2));
        $all = [...$keep, ...$newOnes];

        $this->edit($product)
            ->fillForm(['main_photo_id' => $all[0], 'gallery_photo_ids' => array_slice($all, 1)])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(MediaLimitExceededException::forProduct((string) $product->id, $max, $max)->getMessage());

        $this->assertSame($before, $this->pivots($product), 'the whole save was undone');
    }

    // ---- reuse -------------------------------------------------------------------------------------------------------------------

    public function test_one_asset_on_two_products_lists_both_in_where_used_and_detaching_from_one_leaves_the_other(): void
    {
        $one = $this->product('first', 'First');
        $two = $this->product('second', 'Second');
        $shared = $this->pickerAsset()->id;

        $this->edit($one)->fillForm(['main_photo_id' => $shared])->call('save')->assertHasNoFormErrors();
        $this->edit($two)->fillForm(['main_photo_id' => $shared])->call('save')->assertHasNoFormErrors();

        $this->assertSame(2, ProductMediaModel::query()->where('media_id', $shared)->count(), 'two pivots, one asset');
        $labels = array_map(fn ($usage): string => $usage->label, (new MediaUsageRegistry)->usagesOf($shared));
        $this->assertCount(2, $labels);
        $this->assertStringContainsString('First', implode(' | ', $labels));
        $this->assertStringContainsString('Second', implode(' | ', $labels));

        $this->edit($one)->fillForm(['main_photo_id' => null])->call('save')->assertHasNoFormErrors();

        $this->assertSame([], $this->pivots($one));
        $this->assertSame([$shared => 0], $this->pivots($two));
        $this->assertNotNull(MediaAssetModel::find($shared));
    }

    // ---- nothing else changes ----------------------------------------------------------------------------------------------------

    public function test_a_no_op_save_leaves_the_pivots_and_the_storefront_gallery_exactly_as_they_were(): void
    {
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->seedPricing();
        $this->makeProduct(100, ['name' => 'Summer dress', 'slug' => 'summer-dress', 'price' => '49.90', 'stock' => 3]);
        $this->makeImage(501, 100, 0, alt: 'Front');
        $this->makeImage(502, 100, 1, alt: 'Side');
        $this->makeImage(503, 100, 2);
        // the raw fixture has no shipping class on its variation, which the edit form requires
        DB::table('catalog_variations')->where('product_id', 100)->update(['shipping_class' => 'standard']);

        $reader = function (): CatalogReader {
            $this->app->forgetScopedInstances();

            return app(CatalogReader::class);
        };
        $pivotRows = fn (): string => json_encode(DB::table('catalog_product_media')->orderBy('id')->get(['id', 'product_id', 'media_id', 'sort_order', 'autoplay'])->all());
        $before = ['pivots' => $pivotRows(), 'images' => json_encode($reader()->product('summer-dress')->images)];
        $this->assertNotSame('[]', $before['images']);

        Livewire::test(EditProduct::class, ['record' => 100])->call('save')->assertHasNoFormErrors();

        $this->assertSame($before['pivots'], $pivotRows(), 'the same pivot rows: same ids, same media, same order');
        $this->assertSame($before['images'], json_encode($reader()->product('summer-dress')->images), 'the product page shows the same image URLs in the same order');
    }

    public function test_an_archive_after_a_picker_save_still_keeps_only_the_demoted_main_photo(): void
    {
        $product = $this->product();
        $assets = [];

        foreach (['main', 'second', 'third'] as $name) {
            $base = "uploads/2026/10/{$name}.jpg";
            $thumb = "uploads/2026/10/{$name}-thumbnail.webp";
            Storage::disk('public')->put($base, 'original');
            Storage::disk('public')->put($thumb, 'thumb');
            $assets[$name] = $this->pickerAsset(['path' => $base, 'variants' => [['tier' => 'thumbnail', 'width' => 400, 'height' => 400, 'quality' => 80, 'path' => $thumb]]]);
        }

        $this->edit($product)
            ->fillForm(['main_photo_id' => $assets['main']->id, 'gallery_photo_ids' => [$assets['second']->id, $assets['third']->id], 'status' => ProductStatus::ARCHIVED->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $pivots = $this->pivots($product);
        $this->assertCount(1, $pivots, 'only the main photo survives an archive, as before');
        $this->assertNull(MediaAssetModel::find($assets['second']->id));
        $this->assertNull(MediaAssetModel::find($assets['third']->id));
        $this->assertSame('uploads/2026/10/main-thumbnail.webp', MediaAssetModel::find(array_key_first($pivots))->path, 'the main photo is demoted to its thumbnail');
    }

    // ---- the variable product's own page -----------------------------------------------------------------------------------------

    public function test_the_variable_product_page_uses_the_same_picker_fields_and_id_sync(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        app(ProductRepository::class)->save($product);
        $model = ProductModel::where('slug', 'variable-shirt')->firstOrFail();
        [$a, $b, $c] = [$this->pickerAsset()->id, $this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($model, $a, 0);
        $this->attach($model, $b, 1);

        Livewire::test(EditVariableProduct::class, ['record' => $model->id])
            ->assertFormSet(['main_photo_id' => $a, 'gallery_photo_ids' => [$b]])
            ->fillForm(['main_photo_id' => $c, 'gallery_photo_ids' => [$a]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$c => 0, $a => 1], $this->pivots($model));
        $this->assertNotNull(MediaAssetModel::find($b), 'detached only');
    }

    // ---- the reorder buttons -----------------------------------------------------------------------------------------------------

    public function test_the_gallery_shows_left_and_right_buttons_and_the_edges_are_disabled(): void
    {
        $product = $this->product();
        foreach ([0, 1, 2] as $sort) {
            $this->attach($product, $this->pickerAsset()->id, $sort);
        }

        $html = $this->edit($product)->html();

        $this->assertSame(2, substr_count($html, 'aria-label="Премести наляво"'), 'one per gallery tile (the main photo is its own field)');
        $this->assertSame(2, substr_count($html, 'aria-label="Премести надясно"'));
        $this->assertSame(2, preg_match_all('/class="mpf-move"[^>]*disabled/', $html), 'the first «←» and the last «→» are disabled');
        $this->assertSame(2, substr_count($html, 'class="mpf-x"'), 'a × on each gallery tile');
    }

    public function test_moving_a_picture_swaps_it_with_its_neighbour_and_stops_at_the_edges(): void
    {
        $this->assertSame([2, 1, 3], \App\Media\PickerOrder::move([1, 2, 3], 1, -1));
        $this->assertSame([1, 3, 2], \App\Media\PickerOrder::move(['1', '2', '3'], 1, 1));
        $this->assertSame([1, 2, 3], \App\Media\PickerOrder::move([1, 2, 3], 0, -1), 'nothing left of the first');
        $this->assertSame([1, 2, 3], \App\Media\PickerOrder::move([1, 2, 3], 2, 1), 'nothing right of the last');
    }

    // ---- access ------------------------------------------------------------------------------------------------------------------

    public function test_a_user_without_the_needed_permission_cannot_use_the_picker_of_a_product(): void
    {
        $this->actingAsCustomRole([\EasyCo\Staff\Enums\Permission::ORDER_VIEW]);

        Livewire::test(MediaPickerBrowser::class, ['field' => 'data.main_photo_id', 'minWidth' => 600, 'minHeight' => 600])->assertForbidden();
    }
}
