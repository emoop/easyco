<?php

namespace Tests\Feature\Admin;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Filament\Resources\HtmlBlockResource\Pages\CreateHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\EditHtmlBlock;
use App\Filament\Resources\HtmlBlockResource\Pages\ListHtmlBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HB-5.1: in the HTML block form every explanation of a field is an info icon next to its label, with the text in a tooltip bubble
 * (role="tooltip", linked by aria-describedby, unique ids), and no helper paragraph is left under a field. Checked on the create and the edit
 * page of both kinds. The warnings that must stay visible are not explanations and are not listed here.
 */
class HtmlBlockHintsTest extends TestCase
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

    /** @return list<array{id: string, text: string}> every bubble of the page: its id and its text */
    private function bubbles(string $html): array
    {
        preg_match_all('#<span class="info-hint-bubble" role="tooltip" id="([^"]+)">(.*?)</span>#s', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $x): array => ['id' => $x[1], 'text' => html_entity_decode(trim($x[2]), ENT_QUOTES)], $m);
    }

    /** The page's form, without any helper paragraph under a field, every bubble linked by its icon, every id unique. */
    private function assertHintsAreBubbles(string $html, string $where, int $atLeast): void
    {
        $this->assertStringNotContainsString('fi-fo-field-helper-text', $html, "{$where}: no helper paragraph under a field");

        $bubbles = $this->bubbles($html);
        $ids = array_column($bubbles, 'id');

        $this->assertGreaterThanOrEqual($atLeast, count($bubbles), "{$where}: the explanations are bubbles");
        $this->assertSame(count($ids), count(array_unique($ids)), "{$where}: the bubble ids are unique on the page");

        foreach ($bubbles as $bubble) {
            $this->assertSame(1, substr_count($html, 'aria-describedby="'.$bubble['id'].'"'), "{$where}: the icon of {$bubble['id']} points to its bubble");
            $this->assertNotSame('', $bubble['text'], "{$where}: the bubble {$bubble['id']} has its text");
        }
    }

    public function test_the_create_page_of_a_text_block_has_its_explanations_as_bubbles(): void
    {
        $html = Livewire::test(CreateHtmlBlock::class)->html();

        $this->assertHintsAreBubbles($html, 'create text', 12);
        $this->assertContains((string) __('html_blocks.help.pictures'), array_column($this->bubbles($html), 'text'));
        $this->assertContains((string) __('html_blocks.help.body'), array_column($this->bubbles($html), 'text'));
        $this->assertContains((string) __('html_blocks.conditions.hints.on_sale'), array_column($this->bubbles($html), 'text'));
        $this->assertContains((string) __('html_blocks.conditions.scope'), array_column($this->bubbles($html), 'text'), 'the conditions explanation is in the heading of its section');
    }

    public function test_the_create_page_of_a_picture_block_has_its_explanations_as_bubbles(): void
    {
        $page = Livewire::test(CreateHtmlBlock::class)->fillForm(['kind' => 'card']);
        $html = $page->html();

        $this->assertHintsAreBubbles($html, 'create card', 12);
        $this->assertContains((string) __('html_blocks.card.hints.title'), array_column($this->bubbles($html), 'text'));
        $this->assertContains((string) __('html_blocks.kind.hint'), array_column($this->bubbles($html), 'text'));
    }

    public function test_the_edit_page_of_a_text_block_with_its_style_and_conditions_has_bubbles(): void
    {
        $block = HtmlBlockModel::query()->create(['title' => 'Promo', 'body' => '<p>x</p>', 'style' => ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6]]);

        $html = Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->html();

        $this->assertHintsAreBubbles($html, 'edit text', 15);
        $this->assertContains((string) __('html_blocks.style.hints.pad_v'), array_column($this->bubbles($html), 'text'));
        $this->assertContains((string) __('html_blocks.style.hints.bg'), array_column($this->bubbles($html), 'text'), 'the colour fields keep their explanation next to their clear button');
    }

    public function test_the_edit_page_of_a_picture_block_has_bubbles(): void
    {
        $asset = $this->pickerAsset();
        $block = HtmlBlockModel::query()->create(['title' => 'Card', 'kind' => 'card', 'card' => ['picture' => $asset->id, 'title' => 'Spring']]);

        $html = Livewire::test(EditHtmlBlock::class, ['record' => $block->id])->html();

        $this->assertHintsAreBubbles($html, 'edit card', 12);
    }

    public function test_the_list_page_still_renders_without_helper_paragraphs(): void
    {
        $this->assertStringNotContainsString('fi-fo-field-helper-text', Livewire::test(ListHtmlBlocks::class)->html());
    }

    /** @return Testable the create page with the picture kind, for the other tests of this class */
    private function cardPage(): Testable
    {
        return Livewire::test(CreateHtmlBlock::class)->fillForm(['kind' => 'card']);
    }

    public function test_a_bubble_text_is_the_lang_text_escaped_once(): void
    {
        $html = $this->cardPage()->html();

        $text = (string) __('html_blocks.card.hints.title');
        $this->assertSame(1, substr_count($html, e($text)), 'printed once, in its bubble');
    }
}
