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
 * LG-1 §0: the picker's `mimeTypes()` (the logo: PNG and WebP, decided by the stored `mime`, never the file name) and `plainImagesOnly()` reaching the browser (a `social_image` is neither listed nor
 * accepted for a product photo field).
 */
class MediaPickerMimeTest extends TestCase
{
    use BuildsImageFixtures;
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    private const ALLOWED = ['image/png', 'image/webp'];

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
        return Livewire::test(MediaPickerBrowser::class, $params + ['field' => 'data.image', 'mimes' => self::ALLOWED, 'plain' => true]);
    }

    /** @return list<int> */
    private function listed($component): array
    {
        return $component->viewData('rows')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function png(int $w = 300, int $h = 100): string
    {
        return $this->capture(function () use ($w, $h): void {
            $image = imagecreatetruecolor($w, $h);
            imagepng($image);
        });
    }

    private function jpeg(): string
    {
        return $this->capture(function (): void {
            imagejpeg(imagecreatetruecolor(300, 100));
        });
    }

    public function test_the_library_lists_only_the_allowed_types_and_marks_a_row_with_no_mime_yet(): void
    {
        $png = $this->pickerAsset(['mime' => 'image/png']);
        $webp = $this->pickerAsset(['mime' => 'image/webp']);
        $this->pickerAsset(['mime' => 'image/jpeg']);
        $this->pickerAsset(['mime' => 'image/gif']);
        $unknown = $this->pickerAsset(['mime' => null]);

        $component = $this->browser();

        $this->assertEqualsCanonicalizing([$png->id, $webp->id, $unknown->id], $this->listed($component));
        $this->assertSame(1, substr_count($component->html(), 'неизвестен тип'), 'only the row with no mime carries the note');
        $this->assertCount(5, $this->listed($this->browser(['mimes' => null])), 'without the option every picture is listed');
    }

    public function test_choose_refuses_a_jpeg_and_a_row_of_unknown_type_even_for_an_id_the_library_does_not_list(): void
    {
        $png = $this->pickerAsset(['mime' => 'image/png']);
        $jpeg = $this->pickerAsset(['mime' => 'image/jpeg']);
        $unknown = $this->pickerAsset(['mime' => null]);

        $this->browser()->call('toggle', $png->id)->call('confirm')->assertHasNoErrors()->assertDispatched('media-picked');
        $this->browser()->call('toggle', $jpeg->id)->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        $this->browser()->call('toggle', $unknown->id)->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
    }

    public function test_the_rule_refuses_a_jpeg_by_its_stored_mime_whatever_the_file_is_called(): void
    {
        $png = $this->pickerAsset(['mime' => 'image/png', 'path' => 'uploads/2026/10/a.png', 'original_name' => 'logo.png']);
        $webp = $this->pickerAsset(['mime' => 'image/webp']);
        // the NAME says png, the stored type says jpeg: the type wins
        $liar = $this->pickerAsset(['mime' => 'image/jpeg', 'path' => 'uploads/2026/10/b.png', 'original_name' => 'logo.png']);
        $unknown = $this->pickerAsset(['mime' => null, 'path' => 'uploads/2026/10/c.png']);
        $check = fn (mixed $value): bool => Validator::make(['v' => $value], ['v' => [new MediaAssetId(null, ['image'], null, null, [], self::ALLOWED)]])->passes();

        $this->assertTrue($check($png->id));
        $this->assertTrue($check($webp->id));
        $this->assertFalse($check($liar->id), 'a JPEG with a .png name is a JPEG');
        $this->assertFalse($check($unknown->id), 'an unknown type is refused until the backfill');
        $this->assertFalse($check(987654));

        $message = Validator::make(['v' => $unknown->id], ['v' => [new MediaAssetId(null, ['image'], null, null, [], self::ALLOWED)]])->errors()->first('v');
        $this->assertStringContainsString('media:backfill-meta', $message);
        $message = Validator::make(['v' => $liar->id], ['v' => [new MediaAssetId(null, ['image'], null, null, [], self::ALLOWED)]])->errors()->first('v');
        $this->assertStringContainsString('PNG и WebP', $message);
    }

    public function test_the_field_option_reaches_the_rule_and_the_modal(): void
    {
        $field = MediaPicker::make('image')->mimeTypes(['image/png', 'image/webp'])->plainImagesOnly();
        $jpeg = $this->pickerAsset(['mime' => 'image/jpeg']);

        $rule = (new \ReflectionClass($field))->getProperty('mimes')->getValue($field);
        $this->assertSame(self::ALLOWED, $rule);
        $this->assertFalse(Validator::make(['v' => $jpeg->id], ['v' => [new MediaAssetId(null, ['image'], null, null, [], $rule)]])->passes());
    }

    public function test_the_upload_tab_refuses_a_jpeg_before_storing_and_takes_a_png(): void
    {
        $component = $this->browser(['tab' => 'upload'])->set('files', [UploadedFile::fake()->createWithContent('logo.png', $this->jpeg())]);

        $this->assertSame(0, MediaAssetModel::query()->count(), 'nothing is stored');
        $this->assertSame('тук се приемат само PNG и WebP', $component->get('refused')[0]['reason'], 'a JPEG with a .png name is still a JPEG');

        $component = $this->browser(['tab' => 'upload'])->set('files', [UploadedFile::fake()->createWithContent('logo.jpg', $this->png())]);

        $this->assertSame([], $component->get('refused'), 'a PNG with a .jpg name is a PNG');
        $this->assertSame(1, MediaAssetModel::query()->count());
        $this->assertSame('image/png', MediaAssetModel::query()->first()->mime);
    }

    public function test_the_file_input_accepts_only_the_listed_types(): void
    {
        $html = $this->browser(['tab' => 'upload'])->html();

        $this->assertStringContainsString('accept="image/png,image/webp"', $html);
        $this->assertStringContainsString('тук се приемат само PNG и WebP', $html);
        $this->assertStringContainsString('image/jpeg', $this->browser(['tab' => 'upload', 'mimes' => null])->html());
    }

    public function test_plain_images_only_reaches_the_browser(): void
    {
        $image = $this->pickerAsset();
        $social = $this->pickerAsset(['type' => 'social_image']);

        $this->assertSame([$image->id], $this->listed($this->browser(['mimes' => null, 'plain' => true])), 'a social image is not listed for a product photo');
        $this->assertEqualsCanonicalizing([$image->id, $social->id], $this->listed($this->browser(['mimes' => null, 'plain' => false])));

        $this->browser(['mimes' => null, 'plain' => true])->call('toggle', $social->id)->call('confirm')->assertHasErrors(['selected'])->assertNotDispatched('media-picked');
        $this->browser(['mimes' => null, 'plain' => false])->call('toggle', $social->id)->call('confirm')->assertHasNoErrors()->assertDispatched('media-picked');
    }

    public function test_the_modal_wrapper_passes_both_options_on(): void
    {
        $html = view('filament.forms.components.media-picker-modal', ['field' => 'data.image', 'multiple' => false, 'max' => 1, 'tab' => 'library', 'kind' => 'image', 'minWidth' => null, 'minHeight' => null, 'mimes' => self::ALLOWED, 'plain' => true])->render();

        $this->assertStringContainsString('wire:id', $html);
    }
}
