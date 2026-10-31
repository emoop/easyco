<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Media\DeleteOutcome;
use App\Media\MediaDeleter;
use App\Media\Usage\MediaUsageRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HB-4: the «Фонова снимка» fields of «Стил на блока»: shown only with a picture, saved and read back, refused when they are not a picture of the library or not in a closed list, in both languages,
 * and a picture that is a block's background shows up in the library's «used by» list and cannot be deleted by accident.
 */
class HtmlBlockBackgroundFormTest extends TestCase
{
    use BuildsShippingZones;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
    }

    /** @param  array<string, mixed>  $style */
    private function create(array $style): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Spring banner', 'body' => '<p>Sale</p>', 'style' => $style])->call('create');
    }

    private function stored(): mixed
    {
        $json = DB::table('html_blocks')->orderByDesc('id')->value('style');

        return $json === null ? null : json_decode((string) $json, true);
    }

    public function test_the_picture_choices_are_shown_only_with_a_picture(): void
    {
        $asset = $this->pickerAsset();
        $form = Livewire::test(CreateHtmlBlock::class);

        foreach (['bg_size', 'bg_position', 'overlay', 'min_height', 'valign'] as $field) {
            $form->assertFormFieldIsHidden('style.'.$field);
        }

        $form->assertFormFieldIsVisible('style.bg_image');
        $form->fillForm(['style' => ['bg_image' => $asset->id]]);

        foreach (['bg_size', 'bg_position', 'overlay', 'min_height', 'valign'] as $field) {
            $form->assertFormFieldIsVisible('style.'.$field);
        }
    }

    public function test_the_form_saves_the_picture_and_its_choices_and_reads_them_back(): void
    {
        $asset = $this->pickerAsset();

        $this->create(['bg_image' => $asset->id, 'bg_size' => 'contain', 'bg_position' => 'top', 'overlay' => '40', 'min_height' => '240', 'valign' => 'center', 'color' => '#ffffff'])->assertHasNoFormErrors();

        $this->assertEquals(['color' => '#ffffff', 'bg_image' => $asset->id, 'bg_size' => 'contain', 'bg_position' => 'top', 'overlay' => 40, 'min_height' => 240, 'valign' => 'center'], $this->stored());

        $block = HtmlBlockModel::query()->latest('id')->firstOrFail();
        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])
            ->assertFormSet(['style.bg_image' => $asset->id, 'style.bg_size' => 'contain', 'style.bg_position' => 'top', 'style.overlay' => '40', 'style.min_height' => '240', 'style.valign' => 'center']);
    }

    public function test_only_non_defaults_are_stored_and_the_picture_alone_is_enough(): void
    {
        $asset = $this->pickerAsset();

        $this->create(['bg_image' => $asset->id, 'bg_size' => 'cover', 'bg_position' => 'center', 'overlay' => '0', 'min_height' => '0', 'valign' => 'top'])->assertHasNoFormErrors();

        $this->assertEquals(['bg_image' => $asset->id], $this->stored());
    }

    public function test_choices_without_a_picture_are_dropped(): void
    {
        $this->create(['bg' => '#112233', 'overlay' => '40', 'min_height' => '240', 'valign' => 'center'])->assertHasNoFormErrors();

        $this->assertEquals(['bg' => '#112233'], $this->stored());
    }

    public function test_removing_the_picture_drops_its_choices_with_it(): void
    {
        $asset = $this->pickerAsset();
        $this->create(['bg_image' => $asset->id, 'overlay' => '60'])->assertHasNoFormErrors();
        $block = HtmlBlockModel::query()->latest('id')->firstOrFail();

        Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->fillForm(['style' => ['bg_image' => null]])->call('save')->assertHasNoFormErrors();

        $this->assertNull($this->stored(), 'no picture, no other style: nothing is stored');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): array
    {
        return [
            'an overlay of 35' => [['overlay' => '35'], 'overlay'],
            'a height of 161' => [['min_height' => '161'], 'min_height'],
            'a size that is stretch' => [['bg_size' => 'stretch'], 'bg_size'],
            'a position with two words' => [['bg_position' => 'left top'], 'bg_position'],
            'a vertical position that is middle' => [['valign' => 'middle'], 'valign'],
        ];
    }

    /** @param  array<string, mixed>  $style */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_a_value_outside_a_closed_list_is_a_field_error_and_nothing_is_saved(array $style, string $field): void
    {
        $asset = $this->pickerAsset();

        $this->create(['bg_image' => $asset->id] + $style)->assertHasFormErrors(['style.'.$field]);

        $this->assertSame(0, DB::table('html_blocks')->count());
    }

    public function test_an_id_that_is_not_a_picture_of_the_library_is_refused(): void
    {
        $video = $this->pickerVideo();

        $this->create(['bg_image' => 987654])->assertHasFormErrors(['style.bg_image']);
        $this->create(['bg_image' => $video->id])->assertHasFormErrors(['style.bg_image']);
        $this->create(['bg_image' => '5;color:red'])->assertHasFormErrors(['style.bg_image']);

        $this->assertSame(0, DB::table('html_blocks')->count());
    }

    public function test_the_labels_and_the_hints_are_in_both_languages(): void
    {
        $asset = $this->pickerAsset();

        foreach ([['en', ['Background picture', 'Darkening', 'Minimum height', 'Text vertically', 'Light text on a dark picture: set the text colour.', 'Medium']], ['bg', ['Фонова снимка', 'Затъмняване', 'Минимална височина', 'Текст по вертикала', 'Светъл текст върху тъмна снимка: задайте цвят на текста.', 'Средно']]] as [$locale, $texts]) {
            app()->setLocale($locale);
            $html = Livewire::test(CreateHtmlBlock::class)->fillForm(['style' => ['bg_image' => $asset->id]])->html();

            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $html, "{$locale}: {$text}");
            }
        }
    }

    public function test_the_library_lists_the_usage_and_refuses_to_delete_a_picture_that_is_a_background(): void
    {
        $asset = $this->pickerAsset();
        $this->create(['bg_image' => $asset->id, 'overlay' => '20'])->assertHasNoFormErrors();
        $block = HtmlBlockModel::query()->latest('id')->firstOrFail();

        $registry = new MediaUsageRegistry;
        $this->assertSame(1, $registry->counts([$asset->id])[$asset->id] ?? 0);

        $usages = $registry->usagesOf($asset->id);
        $this->assertCount(1, $usages);
        $this->assertSame('html_block_background', $usages[0]->kind);
        $this->assertSame('HTML block “Spring banner” — background picture', $usages[0]->label);
        $this->assertStringContainsString('/'.$block->id.'/edit', (string) $usages[0]->editUrl);

        $outcome = app(MediaDeleter::class)->delete($asset->id);
        $this->assertSame(DeleteOutcome::IN_USE, $outcome->status);
        $this->assertSame(1, $outcome->uses);
        $this->assertTrue(DB::table('catalog_media')->where('id', $asset->id)->exists(), 'the picture stays');

        // «unused» filter: the picture is not offered as unused
        $unused = \EasyCo\Media\Persistence\Eloquent\MediaAssetModel::query();
        foreach ($registry->providers() as $provider) {
            $provider->excludeUsed($unused);
        }
        $this->assertFalse($unused->whereKey($asset->id)->exists());

        // when the block no longer shows it, it can go
        DB::table('html_blocks')->where('id', $block->id)->update(['style' => null]);
        $this->assertTrue(app(MediaDeleter::class)->delete($asset->id)->wasDeleted());
    }
}
