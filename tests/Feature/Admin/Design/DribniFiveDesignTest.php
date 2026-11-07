<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Content\ContentSet;
use App\Storefront\Content\ContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DRIBNI-5, the «Дизайн» side: the zone list's order with two dividers; the info hint of «Акордеон»; the two colour fields of the listing cloud in «Съдържание» (one field per row, saved and refused).
 */
class DribniFiveDesignTest extends TestCase
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

    private function screen(string $zone = 'header'): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    /** The zone list as the page prints it: the zone labels in order, and `|` for a divider. @return list<string> */
    private function menu(string $html): array
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $items = [];

        foreach ((new \DOMXPath($dom))->query('//nav[contains(concat(" ", normalize-space(@class), " "), " ds-zones ")]//li') as $li) {
            /** @var \DOMElement $li */
            if (str_contains(' '.$li->getAttribute('class').' ', ' ds-zone-divider ')) {
                $items[] = '|';

                continue;
            }

            $items[] = trim(preg_replace('/\s+/', ' ', (string) $li->textContent));
        }

        return $items;
    }

    /** ★ the groups in the new order, a divider after «Количка и поръчка» and after «Сайдбар» (the first four stay first); every zone is still there */
    public function test_the_zone_list_has_the_new_order_and_two_dividers(): void
    {
        $menu = $this->menu($this->screen()->html());

        $expected = ['topbar', 'header', 'footer', 'checkout', '|', 'home', 'page', 'content', 'catalog', 'sidebar', '|', 'product', 'sections', 'summary', 'below'];
        $labels = array_map(static fn (string $key): string => $key === '|' ? '|' : (string) __('design.zones.'.$key), $expected);

        $this->assertSame($labels, $menu, 'the order of the groups, and the two dividers');
        $this->assertCount(13, array_filter($menu, static fn (string $item): bool => $item !== '|'), 'every zone is listed once');
    }

    /** the zone keys and the way a zone opens are unchanged: the order of the menu is not the order of ZONES */
    public function test_the_menu_order_does_not_change_the_zones(): void
    {
        $this->assertSame(['topbar', 'header', 'footer', 'checkout', 'page', 'product', 'below', 'sections', 'catalog', 'summary', 'home', 'sidebar', 'content'], array_keys(DesignScreen::ZONES));
    }

    /** ★ «Акордеон»: its properties have an info icon with the text about the «Секции на продукта» zone */
    public function test_the_accordion_properties_have_the_info_hint(): void
    {
        $screen = $this->screen('summary')->call('addNode', 'accordion');

        $html = $screen->html();

        $this->assertStringContainsString(__('design.summary.accordion_hint'), $html, 'the hint text is in the panel');
        $this->assertSame('The accordion shows the sections created in the «Product sections» zone.', __('design.summary.accordion_hint', [], 'en'));
        $this->assertSame('В акордеона се показват секциите, създадени в зоната „Секции на продукта“.', __('design.summary.accordion_hint', [], 'bg'));
    }

    /** ★ the two colour fields of the listing cloud: one per row in «Съдържание», saved on «Публикувай», a bad colour refused and nothing written */
    public function test_the_cloud_colours_are_saved_and_a_bad_one_is_refused(): void
    {
        $screen = $this->screen('content');
        $html = $screen->html();

        $this->assertStringContainsString(__('design.content.cloud_bg'), $html);
        $this->assertStringContainsString(__('design.content.cloud_color'), $html);

        $screen->set('props.cloud_bg', '#1A2B3C')->set('props.cloud_color', '#445566')->call('apply')->assertHasNoErrors()->call('publish')->assertHasNoErrors();

        $style = ContentStore::values()['style'];
        $this->assertSame('#1a2b3c', $style['cloud_bg'], 'stored lower case');
        $this->assertSame('#445566', $style['cloud_color']);
        $this->assertStringContainsString('--sf-cloud-bg: #1a2b3c;', (string) $this->css());

        // a bad value is EMPTY (the shop's look), never a stored garbage: the panel's own value is read leniently after «Приложи»
        $this->screen('content')->set('props.cloud_bg', 'red')->call('apply')->call('publish');
        $style = ContentStore::values()['style'];
        $this->assertNull($style['cloud_bg'], 'a bad colour reads as empty');
        $this->assertSame('#445566', $style['cloud_color'], 'the other colour is kept');
        $this->assertStringNotContainsString('--sf-cloud-bg', (string) $this->css());

        // the validator itself refuses a direct bad value (a caller that bypasses the panel)
        [, $errors] = ContentSet::validate(['style' => ['cloud_shape' => 'site', 'title_style' => 'lines', 'cloud_bg' => '#12345', 'cloud_color' => null]], ContentStore::existing());
        $this->assertSame(['style.cloud_bg'], array_keys($errors));
    }

    /** the compiled token stylesheet of the shop, or null */
    private function css(): ?string
    {
        $compiled = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::COMPILED_KEY), true);

        return is_array($compiled) ? Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css') : null;
    }
}
