<?php

namespace Tests\Feature\Media;

use App\Filament\Forms\Components\MediaPicker;
use App\Livewire\MediaPickerBrowser;
use App\Rules\MediaAssetId;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * ML-3 §0: the picker's new options. The `limit` the browser could send is clamped on the server; `minDimensions()` hides what is too small (and refuses it on «Избери» and on upload);
 * `videos()` lists and accepts videos only.
 */
class MediaPickerOptionsTest extends TestCase
{
    use BuildsImageFixtures;
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    private const FIELD = 'data.main_photo_id';

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

    private function browser(array $params = [])
    {
        return Livewire::test(MediaPickerBrowser::class, $params + ['field' => self::FIELD]);
    }

    /** @return list<int> */
    private function listed($component): array
    {
        return $component->viewData('rows')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";
    }

    /** A real JPEG of the given size. */
    private function jpeg(int $w, int $h): string
    {
        return $this->capture(function () use ($w, $h): void {
            $image = imagecreatetruecolor($w, $h);
            imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 10, 120, 200));
            imagejpeg($image);
        });
    }

    // ---- the limit ---------------------------------------------------------------------------------------------------------------

    public function test_a_limit_sent_by_the_browser_is_clamped_on_the_server(): void
    {
        $now = now();
        $rows = [];

        for ($i = 0; $i < MediaPickerBrowser::MAX_LIMIT + 5; $i++) {
            $rows[] = ['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/bulk-{$i}.jpg", 'processing_status' => 'ready', 'variants' => json_encode([['tier' => 'thumbnail', 'width' => 10, 'height' => 10, 'quality' => 80, 'path' => "uploads/2026/10/bulk-{$i}-thumbnail.webp"]]), 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            MediaAssetModel::query()->insert($chunk);
        }

        $component = $this->browser()->set('limit', 100000);

        $this->assertCount(MediaPickerBrowser::MAX_LIMIT, $this->listed($component), 'at most the clamp, however large the limit sent');
        $this->assertTrue($component->viewData('hasMore'));

        $this->assertCount(MediaPickerBrowser::MAX_LIMIT, $this->listed($this->browser()->set('limit', 470)->call('more')), 'and «Още» stops at the clamp too');
        $this->assertCount(MediaPickerBrowser::PER_PAGE, $this->listed($this->browser()->set('limit', -5)), 'a negative number is the first page');
    }

    // ---- minDimensions -----------------------------------------------------------------------------------------------------------

    public function test_the_library_lists_only_big_enough_pictures_and_marks_those_of_unknown_size(): void
    {
        $big = $this->pickerAsset(['width' => 800, 'height' => 700]);
        $this->pickerAsset(['width' => 400, 'height' => 900]);
        $this->pickerAsset(['width' => 900, 'height' => 599]);
        $unknown = $this->pickerAsset(['width' => null, 'height' => null]);

        $this->assertCount(4, $this->listed($this->browser()), 'without a minimum everything is listed');

        $component = $this->browser(['minWidth' => 600, 'minHeight' => 600]);
        $this->assertEqualsCanonicalizing([$big->id, $unknown->id], $this->listed($component));
        $this->assertSame(1, substr_count($component->html(), 'неизвестен размер'), 'only the unknown-size card carries the note');
    }

    public function test_a_picture_of_unknown_or_too_small_size_is_refused_on_choose(): void
    {
        $unknown = $this->pickerAsset(['width' => null, 'height' => null]);
        $small = $this->pickerAsset(['width' => 300, 'height' => 300]);
        $big = $this->pickerAsset();
        $params = ['minWidth' => 600, 'minHeight' => 600];

        $this->browser($params)->call('toggle', $unknown->id)->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        // a crafted call for an id the library does not even list is refused the same way
        $this->browser($params)->call('toggle', $small->id)->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        $this->browser($params)->call('toggle', $big->id)->call('confirm')->assertHasNoErrors()->assertDispatched('media-picked');
    }

    public function test_the_rule_checks_the_minimum_but_not_for_pictures_the_record_already_has(): void
    {
        $small = $this->pickerAsset(['width' => 300, 'height' => 300]);
        $unknown = $this->pickerAsset(['width' => null, 'height' => null]);
        $big = $this->pickerAsset();
        $check = fn (mixed $value, array $exempt = []): bool => Validator::make(['v' => $value], ['v' => [new MediaAssetId(null, MediaAssetId::TYPES, 600, 600, $exempt)]])->passes();

        $this->assertTrue($check($big->id));
        $this->assertFalse($check($small->id));
        $this->assertFalse($check($unknown->id), 'unknown size is refused until the backfill');
        $this->assertTrue($check($small->id, [$small->id]), 'a photo the product already has is not re-checked: an old small one must not block a save');
        $this->assertFalse($check($small->id, [$big->id]));
    }

    public function test_the_upload_tab_refuses_a_file_below_the_minimum_with_the_message_and_stores_nothing(): void
    {
        $component = $this->browser(['tab' => 'upload', 'minWidth' => 600, 'minHeight' => 600])
            ->set('files', [UploadedFile::fake()->createWithContent('tiny.jpg', $this->jpeg(300, 200))]);

        $this->assertSame(0, MediaAssetModel::query()->count(), 'nothing is stored');
        $this->assertCount(1, $component->get('refused'));
        $this->assertStringContainsString('300x200', $component->get('refused')[0]['reason']);
        $this->assertStringContainsString('600x600', $component->get('refused')[0]['reason']);

        $component = $this->browser(['tab' => 'upload', 'minWidth' => 600, 'minHeight' => 600])
            ->set('files', [UploadedFile::fake()->createWithContent('ok.jpg', $this->jpeg(640, 640))]);

        $this->assertSame([], $component->get('refused'));
        $this->assertSame(1, MediaAssetModel::query()->count());
        $this->assertSame('library', $component->get('tab'));
        $this->assertCount(1, $component->get('selected'));
    }

    public function test_the_minimum_defaults_to_the_configured_number_not_a_copy_of_it(): void
    {
        config(['services.media.min_image_dimension_px' => 777]);
        $field = MediaPicker::make('x')->minDimensions();
        $reflection = new \ReflectionClass($field);

        $this->assertSame(777, $reflection->getProperty('minWidth')->getValue($field));
        $this->assertSame(777, $reflection->getProperty('minHeight')->getValue($field));
    }

    // ---- videos() ----------------------------------------------------------------------------------------------------------------

    public function test_video_mode_lists_videos_only(): void
    {
        $image = $this->pickerAsset();
        $video = $this->pickerVideo();
        $this->pickerVideo(['processing_status' => 'pending']);
        $this->pickerVideo(['missing_at' => now()]);

        $this->assertSame([$video->id], $this->listed($this->browser(['kind' => 'video'])));
        $this->assertSame([$image->id], $this->listed($this->browser()));
    }

    public function test_video_mode_confirms_a_video_and_refuses_a_picture(): void
    {
        $image = $this->pickerAsset();
        $video = $this->pickerVideo();

        $this->browser(['kind' => 'video'])->call('toggle', $video->id)->call('confirm')->assertHasNoErrors()->assertDispatched('media-picked');
        $this->browser(['kind' => 'video'])->call('toggle', $image->id)->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        $this->browser()->call('toggle', $video->id)->call('confirm')->assertHasErrors(['selected']);
    }

    public function test_the_upload_tab_in_video_mode_takes_a_video_and_refuses_a_picture(): void
    {
        $component = $this->browser(['kind' => 'video', 'tab' => 'upload'])
            ->set('files', [UploadedFile::fake()->createWithContent('photo.jpg', $this->jpeg(700, 700))]);

        $this->assertSame(0, MediaAssetModel::query()->count());
        $this->assertSame(__('media.picker.videos_only'), $component->get('refused')[0]['reason']);
        $this->assertStringContainsString('video/mp4', $component->html());
        $this->assertStringNotContainsString('image/jpeg', $component->html());

        $component = $this->browser(['kind' => 'video', 'tab' => 'upload'])
            ->set('files', [UploadedFile::fake()->createWithContent('clip.mp4', $this->mp4())]);

        $this->assertSame([], $component->get('refused'));
        $asset = MediaAssetModel::query()->firstOrFail();
        $this->assertSame('video', $asset->type);
        $this->assertSame([(int) $asset->id], $component->get('selected'));
    }

    public function test_video_mode_respects_the_video_size_limit(): void
    {
        config(['services.media.max_video_size_kb' => 1]);

        $component = $this->browser(['kind' => 'video', 'tab' => 'upload'])
            ->set('files', [UploadedFile::fake()->createWithContent('big.mp4', $this->mp4().str_repeat("\0", 4096))]);

        $this->assertSame(0, MediaAssetModel::query()->count());
        $this->assertSame(__('media.library.upload.too_large'), $component->get('refused')[0]['reason']);
    }

    public function test_video_mode_ignores_a_minimum_size(): void
    {
        $video = $this->pickerVideo();

        $this->assertSame([$video->id], $this->listed($this->browser(['kind' => 'video', 'minWidth' => 600, 'minHeight' => 600])), 'a size makes no sense for a video');
    }
}
