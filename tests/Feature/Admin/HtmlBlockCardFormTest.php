<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockCard;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\ListHtmlBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HB-5: the admin form of the «Снимка с текст» kind: the kind is chosen at creation and only shown afterwards, the card's fields are shown only for the card kind (and «Стил на блока» only for the text kind),
 * the values are validated on the server (the messages sit under the fields), the live preview carries the shop's classes, the list shows the kind, and every label and hint exists in both languages.
 */
class HtmlBlockCardFormTest extends TestCase
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

    /** @param  array<string, mixed>  $card */
    private function create(array $card, string $kind = 'card'): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Spring', 'kind' => $kind, 'card' => $card])->call('create');
    }

    public function test_the_kind_is_chosen_at_creation_and_the_card_fields_follow_it(): void
    {
        $form = Livewire::test(CreateHtmlBlock::class)->assertFormSet(['kind' => 'text']);

        foreach (['picture', 'title', 'subtitle', 'text', 'link', 'alt', 'title_size', 'overlay', 'halign', 'valign', 'height', 'text_color', 'border_w', 'border_color', 'radius'] as $field) {
            $form->assertFormFieldIsHidden('card.'.$field);
        }

        $form->assertFormFieldIsVisible('body')->assertFormFieldIsVisible('style.bg');
        $form->fillForm(['kind' => 'card']);

        foreach (['picture', 'title', 'subtitle', 'text', 'link', 'alt', 'title_size', 'overlay', 'halign', 'valign', 'height', 'text_color', 'border_w', 'border_color', 'radius'] as $field) {
            $form->assertFormFieldIsVisible('card.'.$field);
        }

        $form->assertFormFieldIsHidden('body')->assertFormFieldIsHidden('style.bg')->assertFormFieldIsHidden('pictures');
        $form->assertFormFieldIsHidden('card.text_custom')->assertFormFieldIsHidden('card.border_custom');
        $form->fillForm(['card' => ['text_color' => 'custom', 'border_color' => 'custom']])->assertFormFieldIsVisible('card.text_custom')->assertFormFieldIsVisible('card.border_custom');
    }

    public function test_a_card_is_saved_read_back_and_prints(): void
    {
        $asset = $this->pickerAsset();

        $this->create(['picture' => $asset->id, 'title' => 'Spring', 'subtitle' => 'New in', 'text' => "a\nb", 'link' => '/product-category/clothing', 'title_size' => 'xl', 'height' => '400', 'border_w' => '2', 'radius' => '16'])->assertHasNoFormErrors();

        $row = DB::table('html_blocks')->first();
        $card = json_decode((string) $row->card, true);

        $this->assertSame('card', $row->kind);
        $this->assertSame('', $row->body);
        $this->assertNull($row->style);
        $this->assertNull($row->pictures);
        $this->assertSame([$asset->id, 'xl', 400, 2, 16, "a\nb"], [$card['picture'], $card['title_size'], $card['height'], $card['border_w'], $card['radius'], $card['text']]);
        $this->assertStringContainsString('sf-pc-t-xl', HtmlBlockModel::renderMany([(int) $row->id])[(int) $row->id]);

        $edit = Livewire::test(EditHtmlBlock::class, ['record' => $row->id]);
        $edit->assertFormSet(['kind' => 'card', 'card.picture' => $asset->id, 'card.title' => 'Spring', 'card.height' => '400', 'card.title_size' => 'xl']);
        $edit->assertFormFieldIsDisabled('kind')->assertFormFieldIsVisible('card.title')->assertFormFieldIsHidden('body');
        $edit->fillForm(['card' => ['title' => 'Summer']])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Summer', json_decode((string) DB::table('html_blocks')->value('card'), true)['title']);
        $this->assertSame('card', DB::table('html_blocks')->value('kind'), 'the kind is never changed');
    }

    public function test_the_server_refuses_a_bad_card_under_the_field(): void
    {
        $asset = $this->pickerAsset();

        $this->create(['picture' => $asset->id, 'title' => 'T', 'link' => 'javascript:alert(1)'])->assertHasFormErrors(['card.link']);
        $this->create(['picture' => $asset->id, 'title' => str_repeat('a', 81)])->assertHasFormErrors(['card.title']);
        $this->create(['picture' => $asset->id])->assertHasFormErrors(['card.alt']);
        $this->create(['picture' => $asset->id, 'title' => 'T', 'text_color' => 'custom', 'text_custom' => 'red'])->assertHasFormErrors(['card.text_custom']);
        $this->create(['title' => 'T'])->assertHasFormErrors(['card.picture']);
        $this->create(['picture' => 999999, 'title' => 'T'])->assertHasFormErrors(['card.picture']);
        $video = $this->pickerVideo();
        $this->create(['picture' => $video->id, 'title' => 'T'])->assertHasFormErrors(['card.picture']);

        $this->assertSame(0, DB::table('html_blocks')->count());
    }

    public function test_the_text_kind_still_saves_as_before_and_stores_no_card(): void
    {
        Livewire::test(CreateHtmlBlock::class)->fillForm(['title' => 'Old', 'body' => '<p>Hi</p>', 'style' => ['bg' => '#2e7d32']])->call('create')->assertHasNoFormErrors();

        $row = DB::table('html_blocks')->first();

        $this->assertSame('text', $row->kind);
        $this->assertNull($row->card);
        $this->assertSame(['bg' => '#2e7d32'], json_decode((string) $row->style, true));
    }

    public function test_the_live_preview_has_the_shops_classes_and_the_list_shows_the_kind(): void
    {
        $asset = $this->pickerAsset();
        $html = Livewire::test(CreateHtmlBlock::class)->fillForm(['kind' => 'card', 'card' => ['picture' => $asset->id, 'title' => 'Preview me', 'title_size' => 'm', 'halign' => 'left', 'radius' => '8']])->html();

        $this->assertStringContainsString('sf-pc sf-pc-t-m sf-pc-ha-left', $html);
        $this->assertStringContainsString('sf-pc-r-8', $html);
        $this->assertStringContainsString('Preview me', $html);

        HtmlBlockModel::query()->create(['title' => 'A card', 'kind' => 'card', 'card' => ['picture' => $asset->id, 'title' => 'x']]);
        HtmlBlockModel::query()->create(['title' => 'A text', 'body' => '<p>x</p>']);

        Livewire::test(ListHtmlBlocks::class)->assertSee('Picture with text')->assertSee('Text');
    }

    public function test_the_explanations_are_info_icons_and_every_label_and_hint_exists_in_both_languages(): void
    {
        $html = Livewire::test(CreateHtmlBlock::class)->fillForm(['kind' => 'card'])->html();

        $this->assertGreaterThanOrEqual(10, substr_count($html, 'aria-describedby="ds-hint-card-'), 'the hints are info icons (HINT-1)');

        $fields = array_keys(HtmlBlockCard::DEFAULTS);

        foreach (['bg', 'en'] as $locale) {
            app()->setLocale($locale);

            foreach (array_diff($fields, ['picture']) as $key) {
                $this->assertNotSame('html_blocks.card.fields.'.$key, __('html_blocks.card.fields.'.$key, [], $locale), "{$locale} label {$key}");
                $this->assertNotSame('html_blocks.card.hints.'.$key, __('html_blocks.card.hints.'.$key, [], $locale), "{$locale} hint {$key}");
            }

            foreach (['picture_required', 'bad_picture', 'bad_text', 'too_long', 'bad_link', 'alt_required', 'bad_choice', 'bad_color', 'color_required', 'too_many'] as $code) {
                $this->assertNotSame('html_blocks.card.errors.'.$code, __('html_blocks.card.errors.'.$code, ['max' => 3], $locale), "{$locale} error {$code}");
            }

            foreach (HtmlBlockCard::KINDS as $kind) {
                $this->assertNotSame('html_blocks.kind.'.$kind, __('html_blocks.kind.'.$kind, [], $locale));
            }

            foreach (['title_size' => HtmlBlockCard::TITLE_SIZES, 'overlay' => HtmlBlockCard::OVERLAYS, 'halign' => HtmlBlockCard::HALIGNS, 'valign' => HtmlBlockCard::VALIGNS, 'height' => HtmlBlockCard::HEIGHTS,
                'text_color' => HtmlBlockCard::TEXT_COLORS, 'border_w' => HtmlBlockCard::BORDER_WIDTHS, 'border_color' => HtmlBlockCard::BORDER_COLORS, 'radius' => HtmlBlockCard::RADII] as $key => $values) {
                foreach ($values as $value) {
                    $this->assertNotSame("html_blocks.card.options.{$key}.{$value}", __("html_blocks.card.options.{$key}.{$value}", [], $locale), "{$locale} option {$key}.{$value}");
                }
            }
        }

    }
}
