<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\ProductMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * ML-3 §3: the product video through the media picker in video mode (`video_id`), with the existing autoplay toggle stored on the pivot and the one-video rule of VideoCountGuard.
 */
class ProductVideoPickerTest extends TestCase
{
    use BuildsShippingZones;
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

    private function product(): ProductModel
    {
        $product = Product::createSimple('Clip', 'SKU-CLIP', 'clip');
        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', 'clip')->firstOrFail();
    }

    private function edit(ProductModel $product)
    {
        return Livewire::test(EditProduct::class, ['record' => $product->id]);
    }

    /** @return list<array{media: int, autoplay: bool}> the video pivots of a product */
    private function videoPivots(ProductModel $product): array
    {
        return ProductMediaModel::query()->where('product_id', $product->id)->get()
            ->filter(fn ($pivot): bool => MediaAssetModel::find($pivot->media_id)?->type === 'video')
            ->map(fn ($pivot): array => ['media' => (int) $pivot->media_id, 'autoplay' => (bool) $pivot->autoplay])->values()->all();
    }

    public function test_picking_a_video_with_autoplay_saves_it_on_the_pivot_and_the_form_is_filled_back(): void
    {
        $product = $this->product();
        $video = $this->pickerVideo()->id;

        $this->edit($product)->fillForm(['video_id' => $video, 'video_autoplay' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame([['media' => $video, 'autoplay' => true]], $this->videoPivots($product));

        $this->edit($product)->assertFormSet(['video_id' => $video, 'video_autoplay' => true])
            ->fillForm(['video_autoplay' => false])->call('save')->assertHasNoFormErrors();
        $this->assertSame([['media' => $video, 'autoplay' => false]], $this->videoPivots($product), 'autoplay is updated on the same pivot');
        $this->assertSame(1, ProductMediaModel::query()->where('product_id', $product->id)->count());
    }

    public function test_replacing_the_video_detaches_the_old_one_and_keeps_its_asset(): void
    {
        $product = $this->product();
        [$old, $new] = [$this->pickerVideo()->id, $this->pickerVideo()->id];
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $old, 0, true));

        $this->edit($product)->fillForm(['video_id' => $new, 'video_autoplay' => false])->call('save')->assertHasNoFormErrors();

        $this->assertSame([['media' => $new, 'autoplay' => false]], $this->videoPivots($product));
        $this->assertNotNull(MediaAssetModel::find($old), 'detached only');
    }

    public function test_clearing_the_video_detaches_it(): void
    {
        $product = $this->product();
        $video = $this->pickerVideo()->id;
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $video, 0));

        $this->edit($product)->fillForm(['video_id' => null])->call('save')->assertHasNoFormErrors();

        $this->assertSame([], $this->videoPivots($product));
        $this->assertNotNull(MediaAssetModel::find($video));
    }

    public function test_a_picture_in_the_video_field_is_refused_and_a_video_in_a_photo_field_too(): void
    {
        $product = $this->product();
        $image = $this->pickerAsset()->id;

        $this->edit($product)->fillForm(['video_id' => $image])->call('save')->assertHasFormErrors(['video_id']);
        $this->edit($product)->fillForm(['video_id' => 'uploads/x.mp4'])->call('save')->assertHasFormErrors(['video_id']);
        $this->edit($product)->fillForm(['main_photo_id' => $this->pickerVideo()->id])->call('save')->assertHasFormErrors(['main_photo_id']);
        $this->assertSame(0, ProductMediaModel::query()->count());
    }

    public function test_a_second_video_is_refused_by_the_video_count_guard(): void
    {
        // The form holds ONE video id and detaches the old video before attaching the new one (as before), so the guard can only trip when it still sees a video — a race with another
        // request (the API attaching one in between). That is simulated by a guard whose repository reports one video already attached.
        $product = $this->product();
        $c = $this->pickerVideo()->id;
        $sees = \Mockery::mock(ProductMediaRepository::class);
        $sees->shouldReceive('countByProductIdAndType')->andReturn(1);
        $this->app->instance(\EasyCo\Media\VideoCountGuard::class, new \EasyCo\Media\VideoCountGuard($sees));

        $this->edit($product)
            ->fillForm(['video_id' => $c])
            ->call('save')
            ->assertNotified(MediaLimitExceededException::forProductVideo((string) $product->id)->getMessage());

        $this->assertSame([], $this->videoPivots($product), 'nothing was attached');
    }

    public function test_two_videos_that_got_attached_behind_the_forms_back_are_replaced_by_the_one_submitted(): void
    {
        $product = $this->product();
        [$a, $b, $c] = [$this->pickerVideo()->id, $this->pickerVideo()->id, $this->pickerVideo()->id];
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $a, 0));
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $b, 1));

        $this->edit($product)->fillForm(['video_id' => $c])->call('save')->assertHasNoFormErrors();

        $this->assertSame([$c], array_column($this->videoPivots($product), 'media'));
    }

    public function test_the_count_guard_counts_the_video_too(): void
    {
        config(['services.media.max_photos_per_product' => 2]);
        $product = $this->product();
        [$p1, $p2] = [$this->pickerAsset()->id, $this->pickerAsset()->id];
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $p1, 0));
        app(ProductMediaRepository::class)->save(new ProductMedia(null, (string) $product->id, (string) $p2, 1));
        $this->app->forgetInstance(\EasyCo\Media\ProductMediaCountGuard::class);

        $this->edit($product)
            ->fillForm(['video_id' => $this->pickerVideo()->id])
            ->call('save')
            ->assertNotified(MediaLimitExceededException::forProduct((string) $product->id, 2, 2)->getMessage());

        $this->assertSame([], $this->videoPivots($product));
    }

    public function test_create_with_a_video_attaches_it_and_the_field_looks_like_the_others(): void
    {
        $video = $this->pickerVideo()->id;

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'With clip', 'slug' => 'with-clip', 'base_sku' => 'SKU-WC', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'video_id' => $video, 'video_autoplay' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = ProductModel::where('slug', 'with-clip')->firstOrFail();
        $this->assertSame([['media' => $video, 'autoplay' => true]], $this->videoPivots($product));

        $html = Livewire::test(CreateProduct::class)->html();
        $this->assertStringContainsString('Добави видео', $html);
        $this->assertStringContainsString('Няма добавено видео', $html);
    }
}
