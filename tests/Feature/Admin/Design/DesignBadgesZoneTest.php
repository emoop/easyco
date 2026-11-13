<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Badges\BadgeSet;
use App\Storefront\Appearance\StorefrontAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * P7-1: the «Значки» zone of the «Дизайн» screen: it opens with every built-in OFF at its defaults; «Приложи» writes a draft (the shop is not touched); «Публикувай» writes the COMPILED set (only the enabled rows) and the next
 * storefront read sees it; publishing everything disabled removes the key; a bad value is refused under its field and nothing is written; «Начални настройки» puts the defaults into the working copy; the text is escaped.
 */
class DesignBadgesZoneTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
    }

    private function screen(): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'badges');
    }

    private function stored(string $key): ?array
    {
        $json = app(SiteSettingsRepository::class)->get($key);

        return $json === null ? null : (array) json_decode($json, true);
    }

    public function test_the_zone_is_in_the_list_and_opens_with_everything_off_at_the_defaults(): void
    {
        $screen = $this->screen();

        $this->assertArrayHasKey('badges', DesignScreen::ZONES);
        $this->assertSame('Badges', __('design.zones.badges'));
        $this->assertFalse($screen->get('props.sale_enabled'));
        $this->assertFalse($screen->get('props.new_enabled'));
        $this->assertFalse($screen->get('props.low_stock_enabled'));
        $this->assertSame(['top', 'top', 'bottom'], [$screen->get('props.sale_position'), $screen->get('props.new_position'), $screen->get('props.low_stock_position')]);
        $this->assertSame('word', $screen->get('props.sale_mode'));
        $this->assertEquals(14, $screen->get('props.new_days'));
        $this->assertSame('3', (string) $screen->get('props.low_stock_max'));

        $html = $screen->html();
        $this->assertStringContainsString('aria-describedby="ds-hint-sale_enabled-', $html);
        $this->assertStringContainsString('aria-describedby="ds-hint-new_days-', $html);
        $this->assertStringContainsString('aria-describedby="ds-hint-low_stock_max-', $html);
    }

    public function test_apply_writes_the_draft_and_publish_writes_only_the_enabled_rows(): void
    {
        $screen = $this->screen();

        $screen->set('props.sale_enabled', true)->set('props.sale_mode', 'percent')->set('props.sale_colour', 'green')->set('props.sale_opacity', '80')
            ->set('props.low_stock_enabled', true)->set('props.low_stock_max', '5')->set('props.low_stock_text', 'Only a few')
            ->call('apply')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY), 'the shop is not touched by «Apply»');
        $draft = $this->stored(AppearanceSettings::BADGES_DRAFT_KEY);
        $this->assertSame(['sale', 'new', 'low_stock'], array_column($draft['badges'], 'kind'), 'the draft keeps every built-in row');
        $this->assertTrue($draft['badges'][0]['enabled']);

        $screen->call('publish')->assertHasNoErrors();

        $live = $this->stored(AppearanceSettings::BADGES_KEY);
        $this->assertSame(['sale', 'low_stock'], array_column($live['badges'], 'kind'), 'only the enabled rows are compiled');
        $this->assertSame('percent', $live['badges'][0]['mode']);
        $this->assertSame('green', $live['badges'][0]['colour']);
        $this->assertSame(80, $live['badges'][0]['opacity']);
        $this->assertSame(5, $live['badges'][1]['max']);
        $this->assertSame('Only a few', $live['badges'][1]['text']);
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_DRAFT_KEY), 'the draft is forgotten');
        $this->assertSame(['sale', 'low_stock'], array_keys(app(StorefrontAppearance::class)->badgeSet()), 'the storefront reads the new set at once');

        // reopening starts from what is published, the switched-off «Нови» at its defaults
        $again = $this->screen();
        $this->assertTrue($again->get('props.sale_enabled'));
        $this->assertFalse($again->get('props.new_enabled'));
        $this->assertSame('green', $again->get('props.sale_colour'));
    }

    /** ★ publishing everything disabled removes the key: the shop is exactly what it was */
    public function test_publishing_everything_disabled_forgets_the_key(): void
    {
        $screen = $this->screen();
        $screen->set('props.new_enabled', true)->call('apply')->call('publish');
        $this->assertNotNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY));

        $screen = $this->screen();
        $screen->set('props.new_enabled', false)->call('apply')->call('publish')->assertHasNoErrors();

        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_KEY));
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_DRAFT_KEY));
        $this->assertSame([], app(StorefrontAppearance::class)->badgeSet());
    }

    /** ★ a bad value is refused under its field and nothing is written */
    public function test_a_bad_value_is_refused_and_nothing_is_written(): void
    {
        foreach ([
            ['sale_text', str_repeat('x', 25), 'props.sale_text'],
            ['sale_colour', '#ff0000', 'props.sale_colour'],
            ['sale_colour', 'purple-ish', 'props.sale_colour'],
            ['sale_opacity', '70', 'props.sale_opacity'],
            ['sale_shape', 'star', 'props.sale_shape'],
            ['sale_position', 'middle', 'props.sale_position'],
            ['sale_priority', '100', 'props.sale_priority'],
            ['new_days', '91', 'props.new_days'],
            ['low_stock_max', '7', 'props.low_stock_max'],
        ] as [$field, $value, $error]) {
            $screen = $this->screen()->set("props.{$field}", $value)->call('apply');

            $screen->assertHasErrors([$error]);
            $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_DRAFT_KEY), "{$field}={$value}");
        }
    }

    /** P7-1.2: the third position and 70 % are offered; red + 70 % is refused with its message and nothing is written; black + 70 % publishes */
    public function test_image_bottom_and_opacity_70_are_offered_and_70_is_limited(): void
    {
        $this->assertSame(['top', 'image_bottom', 'bottom'], BadgeSet::POSITIONS);
        $this->assertSame('Bottom, over the picture', __('design.badges.options.position.image_bottom'));

        $html = $this->screen()->html();
        $this->assertStringContainsString('Bottom, over the picture', $html);
        $this->assertStringContainsString('70%', $html);

        $screen = $this->screen()->set('props.sale_enabled', true)->set('props.sale_opacity', '70')->call('apply');
        $screen->assertHasErrors(['props.sale_opacity']);
        $this->assertStringContainsString('only for black, orange and grey', json_encode($screen->errors()->toArray(), JSON_UNESCAPED_UNICODE));
        $this->assertNull(app(SiteSettingsRepository::class)->get(AppearanceSettings::BADGES_DRAFT_KEY));

        $ok = $this->screen()->set('props.sale_enabled', true)->set('props.sale_colour', 'black')->set('props.sale_opacity', '70')->set('props.sale_position', 'image_bottom')->call('apply')->call('publish');
        $ok->assertHasNoErrors();
        $live = $this->stored(AppearanceSettings::BADGES_KEY);
        $this->assertSame([70, 'image_bottom'], [$live['badges'][0]['opacity'], $live['badges'][0]['position']]);
    }

    public function test_the_defaults_button_puts_everything_off_into_the_working_copy(): void
    {
        $screen = $this->screen();
        $screen->set('props.sale_enabled', true)->set('props.new_days', '30')->call('apply');

        $screen->call('resetToDefault');

        $this->assertFalse($screen->get('props.sale_enabled'));
        $this->assertEquals(14, $screen->get('props.new_days'));
    }

    public function test_the_preview_is_a_signed_url_of_the_zone(): void
    {
        $url = $this->screen()->get('previewUrl');

        $this->assertStringContainsString('zone=badges', $url);
        $this->assertStringContainsString('signature=', $url);
    }
}
