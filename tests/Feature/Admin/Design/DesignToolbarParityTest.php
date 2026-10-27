<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-5: ONE button model for every zone of the «Дизайн» screen. The toolbar is defined once (DesignScreen::getHeaderActions): «Към админа» · «Върни публикуваното» · «Начални настройки» (only a zone with
 * initial settings) · «Публикувай» (last, danger red). The panel has ONE button, «Приложи». There is no «Запази» and no toolbar «Приложи» anywhere. «Публикувай» is disabled when there is nothing to publish
 * and while the panel holds edits «Приложи» has not taken. The state indicator says which of three things is true.
 */
class DesignToolbarParityTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    /** The zones and whether each has initial settings («Начални настройки»). */
    private const ZONES = ['topbar' => false, 'header' => false, 'footer' => true, 'checkout' => false, 'page' => true, 'product' => true, 'summary' => true];

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        $this->actingAsStaff('Administrator');
    }

    private function screen(string $zone): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    /** @return array<string, \Filament\Actions\Action> the header actions of the page, by name, in the order they are drawn */
    private function toolbar(Testable $screen): array
    {
        $actions = [];

        foreach ($screen->instance()->getCachedHeaderActions() as $action) {
            $actions[$action->getName()] = $action;
        }

        return $actions;
    }

    /** @return list<string> the names of the toolbar actions that are VISIBLE, in order */
    private function visibleToolbar(Testable $screen): array
    {
        return array_keys(array_filter($this->toolbar($screen), static fn ($action): bool => $action->isVisible()));
    }

    public function test_every_zone_has_exactly_the_same_toolbar_in_the_same_order(): void
    {
        foreach (self::ZONES as $zone => $hasInitialSettings) {
            $this->assertSame(
                $hasInitialSettings ? ['to_admin', 'discard', 'reset', 'publish'] : ['to_admin', 'discard', 'publish'],
                $this->visibleToolbar($this->screen($zone)),
                "{$zone}: «Към админа», «Върни публикуваното», «Начални настройки» (only with initial settings), «Публикувай»",
            );
        }
    }

    public function test_there_is_no_save_and_no_toolbar_apply_in_any_zone_and_the_labels_are_the_model_s(): void
    {
        foreach (array_keys(self::ZONES) as $zone) {
            $actions = $this->toolbar($this->screen($zone));

            $this->assertArrayNotHasKey('apply', $actions, "{$zone}: «Приложи» is the panel's button, not the toolbar's");
            $this->assertArrayNotHasKey('save', $actions, "{$zone}: there is no «Запази»");
            $this->assertSame('Revert to published', (string) $actions['discard']->getLabel(), $zone);
            $this->assertSame('Publish', (string) $actions['publish']->getLabel(), $zone);
            $this->assertSame('Initial settings', (string) $actions['reset']->getLabel(), $zone);

            foreach ($actions as $name => $action) {
                $this->assertDoesNotMatchRegularExpression('/Save|Запази|Apply|Приложи/u', (string) $action->getLabel(), "{$zone}: {$name}");
            }
        }
    }

    public function test_publish_is_danger_red_and_the_last_button(): void
    {
        foreach (array_keys(self::ZONES) as $zone) {
            $actions = $this->toolbar($this->screen($zone));

            $this->assertSame('publish', array_key_last($actions), "{$zone}: the last");
            $this->assertSame('danger', $actions['publish']->getColor(), "{$zone}: red");
            $this->assertNotSame('danger', $actions['discard']->getColor(), $zone);
        }
    }

    public function test_the_panel_has_exactly_one_submit_button_and_it_is_apply(): void
    {
        foreach (array_keys(self::ZONES) as $zone) {
            $html = $this->screen($zone)->html();

            $this->assertSame(1, preg_match('#<aside class="ds-props".*?</aside>#s', $html, $panel), "{$zone}: the panel");
            $this->assertSame(1, preg_match_all('/<button[^>]*type="submit"[^>]*>(.*?)<\/button>/s', $panel[0], $buttons), "{$zone}: one submit");
            $this->assertSame('Apply', trim(strip_tags($buttons[1][0])), $zone);
            $this->assertStringNotContainsString('Save', $panel[0], $zone);
        }
    }

    public function test_publish_is_disabled_with_nothing_to_publish_and_while_the_panel_holds_edits(): void
    {
        foreach (array_keys(self::ZONES) as $zone) {
            $screen = $this->screen($zone);

            $this->assertTrue($this->toolbar($screen)['publish']->isDisabled(), "{$zone}: nothing to publish");
            $screen->assertSet('panelDirty', false);
        }

        // something to publish (a working copy that differs) enables it; the panel's un-applied edits (the browser sets the flag) disable it again
        $screen = $this->screen('page')->set('props.card_radius', '12')->call('apply');

        $this->assertFalse($this->toolbar($screen)['publish']->isDisabled());

        $screen->set('panelDirty', true);
        $this->assertTrue($this->toolbar($screen)['publish']->isDisabled(), 'the panel has edits «Приложи» has not taken');
        $screen->call('publish');
        $this->assertNotNull(app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::PAGE_DESIGN_DRAFT_KEY), 'a publish asked while the panel is dirty is refused');
        $this->assertNull(app(\App\Settings\Contracts\SiteSettingsRepository::class)->get(\App\Settings\AppearanceSettings::PAGE_DESIGN_KEY));

        $screen->set('panelDirty', false)->call('apply')->assertSet('panelDirty', false);
        $this->assertFalse($this->toolbar($screen)['publish']->isDisabled());
    }

    public function test_the_state_indicator_shows_the_three_states(): void
    {
        $screen = $this->screen('page');
        $html = $screen->html();
        $this->assertStringContainsString('data-ds-state="published"', $html);
        $this->assertStringContainsString('Published', $html);

        $screen->set('props.card_radius', '12')->call('apply');
        $html = $screen->html();
        $this->assertStringContainsString('data-ds-state="draft"', $html);
        $this->assertStringContainsString('Draft — not published', $html);

        $screen->set('panelDirty', true);
        $html = $screen->html();
        $this->assertStringContainsString('data-ds-state="panel"', $html);
        $this->assertStringContainsString('The panel has changes that are not applied', $html);

        $screen->set('panelDirty', false)->call('publish');
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());

        $screen->set('props.card_radius', '14')->call('apply')->call('discard');
        $this->assertStringContainsString('data-ds-state="published"', $screen->html());
    }

    public function test_every_zone_reports_a_draft(): void
    {
        foreach (array_keys(self::ZONES) as $zone) {
            $this->assertTrue($this->screen($zone)->instance()->zoneObject()->hasDraft(), "{$zone}: every zone has a working copy");
        }
    }

    public function test_the_reset_asks_for_confirmation_with_the_models_words(): void
    {
        $actions = $this->toolbar($this->screen('page'));
        $reset = $actions['reset'];

        $this->assertTrue($reset->isConfirmationRequired());
        $this->assertSame('This puts the initial settings into the working copy. They take effect after “Publish”.', (string) $reset->getModalDescription());
    }
}
