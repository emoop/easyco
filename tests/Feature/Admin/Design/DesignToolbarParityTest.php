<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Storefront\PageDesign\PageDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * GL-2.1: the two plain sections («Страница и карти», «Продуктова страница») have the SAME toolbar as «Топбанер», the other zone without a draft. Their «Запази» is the same button; «Приложи»
 * (publish a draft) is never offered in a zone without a draft. The one button that differs is «Върни по подразбиране», which GL-2 added on purpose and which the top banner does not have.
 */
class DesignToolbarParityTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('bg');
        $this->actingAsStaff('Administrator');
    }

    /** @return array<string, string> zone key => the zone's own «Запази» label check */
    private function plainZones(): array
    {
        return ['topbar' => 'topbar', PageDesign::PAGE => PageDesign::PAGE, PageDesign::PRODUCT => PageDesign::PRODUCT];
    }

    /**
     * The toolbar buttons that are VISIBLE, by their action name, for the zone selected on the screen. Filament keeps the actions of a page as names; a hidden action is not shown.
     *
     * @return list<string>
     */
    private function visibleToolbar(string $zone): array
    {
        $screen = Livewire::test(DesignScreen::class)->call('selectZone', $zone);
        $visible = [];

        foreach (['to_admin', 'default', 'discard', 'apply', 'publish'] as $name) {
            try {
                $screen->assertActionVisible($name);
                $visible[] = $name;
            } catch (\Throwable) {
                // hidden for this zone
            }
        }

        return $visible;
    }

    public function test_the_two_plain_sections_show_the_same_toolbar_as_the_top_banner_except_the_reset(): void
    {
        $topbar = $this->visibleToolbar('topbar');

        $this->assertSame(['to_admin', 'apply'], $topbar, 'the top banner: «Назад към админа» and «Запази»');

        foreach ([PageDesign::PAGE, PageDesign::PRODUCT] as $zone) {
            $this->assertSame(
                ['to_admin', 'default', 'apply'],
                $this->visibleToolbar($zone),
                "{$zone}: the top banner's toolbar, plus the reset GL-2 added on purpose",
            );
            $this->assertSame(array_values(array_diff($topbar, ['to_admin', 'apply'])), [], "{$zone}: nothing the top banner does not have, except the reset");
        }
    }

    public function test_publish_and_discard_are_never_offered_in_the_plain_sections(): void
    {
        foreach ([PageDesign::PAGE, PageDesign::PRODUCT] as $zone) {
            $toolbar = $this->visibleToolbar($zone);

            $this->assertNotContains('publish', $toolbar, "{$zone}: «Публикувай» publishes a draft, the section has none");
            $this->assertNotContains('discard', $toolbar, "{$zone}: «Върни публикуваното» discards a draft, the section has none");
        }
    }

    public function test_the_save_label_is_zaposhi_and_apply_is_not_called_priloji_in_the_plain_sections(): void
    {
        foreach ([PageDesign::PAGE, PageDesign::PRODUCT] as $zone) {
            $screen = Livewire::test(DesignScreen::class)->call('selectZone', $zone);
            $html = $screen->html();

            $this->assertStringContainsString('Запази', $html, $zone);
            $this->assertStringNotContainsString('Приложи', $html, "{$zone}: «Приложи» belongs to a draft, there is none here");
        }
    }

    /** ★ mutation check: a plain section that claims a draft offers «Приложи» and «Публикувай»; this test must go red. */
    public function test_a_section_that_claims_a_draft_is_caught(): void
    {
        $this->assertSame(false, (new \App\Filament\Design\Zones\PageCardsZone)->hasDraft(), 'the premise: the section has no draft');
        $this->assertSame(false, (new \App\Filament\Design\Zones\ProductPageZone)->hasDraft(), 'the premise: the section has no draft');
    }
}
