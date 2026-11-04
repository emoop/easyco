<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * HINT-1 (Design screen): a field's explanation is an info icon next to its label, not a paragraph under the field. The text is still in the page, in a `role="tooltip"` bubble that the icon button points to with
 * `aria-describedby`; the button has an accessible name and never submits a form; a field without a hint has no icon. The look and the behaviour are CSS (resources/css/design-screen.css) and Alpine, read in the
 * browser check (tests/Browser/hint-1.mjs).
 */
class DesignInfoHintTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        Storage::fake('public');
    }

    private function screen(string $zone): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    /** @return list<array{id: string, text: string}> every bubble of the page: its id and its text */
    private function bubbles(string $html): array
    {
        preg_match_all('#<span class="info-hint-bubble" role="tooltip" id="([^"]+)">(.*?)</span>#s', $html, $m, PREG_SET_ORDER);

        return array_map(static fn (array $x): array => ['id' => $x[1], 'text' => html_entity_decode(trim($x[2]), ENT_QUOTES)], $m);
    }

    public function test_the_hint_is_in_a_bubble_the_icon_button_points_to_and_not_a_paragraph_under_the_field(): void
    {
        $html = $this->screen('catalog')->html();
        $text = (string) __('design.page_design.hints.cols');

        $this->assertContains($text, array_column($this->bubbles($html), 'text'), 'the text is in a bubble');
        $this->assertSame(1, substr_count($html, $text), 'and only there: not printed again as a helper paragraph');
        $this->assertStringNotContainsString('fi-fo-field-helper-text', $html, 'no field of this zone has a helper paragraph under it any more');

        foreach ($this->bubbles($html) as $bubble) {
            $this->assertSame(1, substr_count($html, 'aria-describedby="'.$bubble['id'].'"'), "the icon button of {$bubble['id']} is linked to its bubble");
        }
    }

    public function test_the_icon_is_a_button_with_an_accessible_name_that_never_submits(): void
    {
        $html = $this->screen('catalog')->html();

        preg_match_all('#<button type="button" class="info-hint-btn"[^>]*>#', $html, $buttons);

        $this->assertNotEmpty($buttons[0]);
        $this->assertCount(count($this->bubbles($html)), $buttons[0], 'one button per bubble');

        foreach ($buttons[0] as $button) {
            $this->assertStringContainsString('aria-label="Information"', $button);
            $this->assertStringContainsString('aria-describedby="ds-hint-', $button);
        }
    }

    public function test_the_accessible_name_follows_the_language(): void
    {
        app()->setLocale('bg');

        $this->assertStringContainsString('aria-label="Информация"', $this->screen('catalog')->html());
    }

    public function test_one_icon_per_visible_field_with_a_hint_and_none_for_a_field_without(): void
    {
        // the full-width layout: columns, phone columns, per page, layout, the three built-in filters and (DRIBNI-4) the card name (the side and its width are hidden)
        $this->assertCount(8, $this->bubbles($this->screen('catalog')->html()));
        $this->assertCount(10, $this->bubbles($this->screen('catalog')->set('props.layout', 'sidebar')->html()), 'the side panel adds its side and width');

        // the banner: the switch, the text (a live character counter, not an explanation) and the colours have no hint; only the link and its label do
        $this->assertCount(2, $this->bubbles($this->screen('topbar')->html()));
    }

    public function test_the_ids_are_unique_on_the_page(): void
    {
        $ids = array_column($this->bubbles($this->screen('catalog')->set('props.layout', 'sidebar')->html()), 'id');

        $this->assertSame($ids, array_values(array_unique($ids)));
    }
}
