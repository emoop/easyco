<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\BrandResource\Pages\CreateBrand;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
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
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Exceptions\ImageNotCleanableException;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Storage\MediaUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-0: every upload goes through MediaStorageAdapter::store(), which strips the metadata of an image original and refuses an image it cannot clean; video is stored as it came. Each upload path
 * shows the refusal as a validation error (a form field error, an API 422), never as a 500.
 */
class CleanUploadTest extends TestCase
{
    use BuildsImageFixtures;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    private const HEIC = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
        $this->giveProductsAShippingClass();
    }

    private function heic(): string
    {
        return self::HEIC.str_repeat("\x07", 400);
    }

    /** @return array<int, string> every file on the disk */
    private function files(): array
    {
        return Storage::disk('public')->allFiles();
    }

    // ---- the adapter ---------------------------------------------------------------------------------------------------------------

    public function test_store_writes_an_original_without_metadata_and_keeps_the_pixels(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());

        $stored = app(MediaStorageAdapter::class)->store($dirty, 'IMG_0001.JPG');
        $bytes = Storage::disk('public')->get($stored->path);

        $this->assertStringNotContainsString(self::MARKER, $bytes);
        $this->assertSame($this->fromSos($dirty), $this->fromSos($bytes));
        $this->assertMatchesRegularExpression('#^uploads/\d{4}/\d{2}/[0-9a-f-]{36}\.JPG$#', $stored->path, 'the client\'s own extension is kept when it names the format');
    }

    public function test_the_extension_follows_what_is_stored(): void
    {
        $adapter = app(MediaStorageAdapter::class);

        $this->assertStringEndsWith('.jpg', $adapter->store($this->capture(fn () => imagebmp($this->gd())), 'scan.bmp')->path, 'a converted picture is a .jpg');
        $this->assertStringEndsWith('.png', $adapter->store($this->png($this->dirtyPngChunks()), 'wrong-name.jpg')->path, 'the content decides, not the name');
        $this->assertStringEndsWith('.jpeg', $adapter->store($this->jpeg(), 'a.jpeg')->path);
    }

    public function test_a_video_is_stored_exactly_as_it_came(): void
    {
        $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x05", 200).self::MARKER;

        $stored = app(MediaStorageAdapter::class)->store($mp4, 'clip.mp4');

        $this->assertSame($mp4, Storage::disk('public')->get($stored->path));
        $this->assertStringEndsWith('.mp4', $stored->path);
    }

    public function test_a_picture_that_cannot_be_cleaned_is_refused_and_nothing_is_stored(): void
    {
        try {
            app(MediaStorageAdapter::class)->store($this->heic(), 'phone.heic');
            $this->fail('stored');
        } catch (ImageNotCleanableException $e) {
            $this->assertStringContainsString('ImageMagick', $e->getMessage());
        }

        $this->assertSame([], $this->files());
    }

    public function test_the_helper_turns_the_refusal_into_a_field_error(): void
    {
        try {
            MediaUpload::path($this->heic(), 'phone.heic', 'data.logo');
            $this->fail('no error');
        } catch (ValidationException $e) {
            $this->assertSame(['data.logo'], array_keys($e->errors()));
        }

        $this->assertSame([], $this->files());
        $this->assertStringStartsWith('uploads/', MediaUpload::path($this->jpeg($this->dirtyJpegSegments()), 'ok.jpg', 'data.logo'));
    }

    public function test_the_processing_still_makes_the_same_variants_from_a_clean_upload(): void
    {
        $stored = app(MediaStorageAdapter::class)->store($this->jpeg($this->dirtyJpegSegments()), 'a.jpg');
        $asset = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => $stored->path, 'processing_status' => 'pending', 'variants' => []]);

        (new ProcessMediaAssetJob((int) $asset->id))->handle(app(\EasyCo\Media\Contracts\MediaAssetRepository::class), app(\EasyCo\Media\Contracts\MediaImageProcessor::class), app(MediaStorageAdapter::class));

        $asset->refresh();
        $this->assertSame('ready', $asset->processing_status);
        $this->assertNotEmpty($asset->variants);
    }

    // ---- the API -----------------------------------------------------------------------------------------------------------------

    public function test_the_api_stores_a_clean_original(): void
    {
        $this->actingAsAdministrator();
        config(['services.media.min_image_dimension_px' => 10]);
        $this->app->forgetInstance(\App\Http\Controllers\Api\MediaController::class);
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $file = UploadedFile::fake()->createWithContent('phone.jpg', str_repeat('x', 0).$dirty);

        $response = $this->postJson('/api/media', ['file' => $file]);
        $response->assertCreated();

        $this->assertStringNotContainsString(self::MARKER, Storage::disk('public')->get($response->json('path')));
    }

    public function test_the_api_refuses_a_picture_it_cannot_clean_with_a_422(): void
    {
        $this->actingAsAdministrator();
        $file = UploadedFile::fake()->createWithContent('phone.heic', $this->heic());

        $this->postJson('/api/media', ['file' => $file])->assertStatus(422);

        $this->assertSame([], $this->files());
    }

    // ---- the admin forms ---------------------------------------------------------------------------------------------------------

    // ML-2: a brand logo (and every picture field outside the products) is picked or uploaded through the media picker: its upload tab is the one place these rules are met now

    public function test_a_picture_that_cannot_be_cleaned_is_refused_by_the_picker_upload_not_a_500(): void
    {
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(\App\Livewire\MediaPickerBrowser::class, ['field' => 'data.logo_media_asset_id'])
            ->set('files', [UploadedFile::fake()->createWithContent('logo.jpg', $this->heic())]);

        $this->assertSame(['logo.jpg'], array_column($component->get('refused'), 'name'));
        $this->assertSame([], $this->files());
    }

    public function test_a_picture_uploaded_through_the_picker_is_stored_clean(): void
    {
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(\App\Livewire\MediaPickerBrowser::class, ['field' => 'data.logo_media_asset_id'])
            ->set('files', [UploadedFile::fake()->createWithContent('logo.jpg', $this->jpeg($this->dirtyJpegSegments()))]);

        $paths = array_values(array_filter($this->files(), fn (string $f): bool => str_starts_with($f, 'uploads/')));
        $this->assertCount(1, $paths);
        $this->assertStringNotContainsString(self::MARKER, Storage::disk('public')->get($paths[0]));
        $this->assertCount(1, $component->get('selected'));
    }

    public function test_a_product_photo_that_cannot_be_cleaned_is_a_field_error_not_a_500(): void
    {
        // ML-3: the product photo is a media picker; its upload tab (with the product's minimum size) refuses a picture that cannot be cleaned, stores nothing and does not crash
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(\App\Livewire\MediaPickerBrowser::class, ['field' => 'data.main_photo_id', 'minWidth' => 600, 'minHeight' => 600])
            ->set('files', [UploadedFile::fake()->createWithContent('main.jpg', $this->heic())]);

        $this->assertSame(['main.jpg'], array_column($component->get('refused'), 'name'));
        $this->assertSame([], $this->files());
    }

    public function test_an_html_block_picture_that_cannot_be_cleaned_is_a_field_error_not_a_500(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateHtmlBlock::class)
            ->fillForm(['title' => 'B', 'body' => '<p>x</p>', 'pictures' => ['a' => ['image' => [UploadedFile::fake()->createWithContent('p.jpg', $this->heic())], 'media_asset_id' => null, 'alt' => '', 'url' => '']]])
            ->call('create')
            ->assertHasErrors();

        $this->assertSame([], $this->files());
    }

    public function test_a_variation_photo_that_cannot_be_cleaned_is_a_field_error_not_a_500(): void
    {
        // ML-3: a variation photo is a media picker; its upload tab refuses a picture that cannot be cleaned, stores nothing and does not crash
        $this->actingAsPanelAdministrator();

        $component = Livewire::test(\App\Livewire\MediaPickerBrowser::class, ['field' => 'data.existing_variations.x.variation_photo_ids', 'multiple' => true, 'max' => 3, 'minWidth' => 600, 'minHeight' => 600])
            ->set('files', [UploadedFile::fake()->createWithContent('black.jpg', $this->heic())]);

        $this->assertSame(['black.jpg'], array_column($component->get('refused'), 'name'));
        $this->assertSame([], $this->files());
        $this->assertSame(0, \EasyCo\Media\Persistence\Eloquent\VariationMediaModel::query()->count());
    }

    private function actingAsPanelAdministrator(): void
    {
        $this->actingAs(\App\Filament\StaffPanelUser::find($this->actingAsAdministrator()->id), 'staff');
    }
}
