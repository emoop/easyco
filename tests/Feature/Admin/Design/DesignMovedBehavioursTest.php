<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Footer\FooterLayout;
use App\Storefront\Footer\FooterValidator;
use App\Storefront\Header\HeaderLayout;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-3: what the removed pages «Хедър» / «Футър» tested and the «Дизайн» screen had no test for. A draft is not public; a publish compiles, replaces the reader's cache entry and the shop shows it; the
 * journal never holds a value; the capitals switch of the drawer; an old stored drawer with an account element still publishes; the colours of a row come back after a reload; an unchanged layout
 * written by «Приложи» is the stored layout, element for element.
 */
class DesignMovedBehavioursTest extends TestCase
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
        $this->actingAsStaff('Administrator');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function screen(string $zone = 'header'): Testable
    {
        return Livewire::test(DesignScreen::class)->call('selectZone', $zone);
    }

    private function stored(string $key): ?array
    {
        $json = $this->settings()->get($key);

        return is_string($json) ? json_decode($json, true) : null;
    }

    // ---- header: draft and publish ----------------------------------------------------------------------------------------------------

    public function test_a_header_draft_is_not_public_and_a_publish_compiles_replaces_the_cache_entry_and_the_token_stylesheet(): void
    {
        $screen = $this->screen()->call('selectRow', 'h-d-main')->set('props.height', 52)->call('apply')->assertHasNoErrors();

        $this->assertSame(52, $this->stored(AppearanceSettings::HEADER_DRAFT_KEY)['desktop']['rows']['main']['height']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), 'a draft is not published');
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));

        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);
        $screen->callAction('publish')->assertHasNoErrors();

        $this->assertSame(52, $this->stored(AppearanceSettings::HEADER_COMPILED_KEY)['layout']['desktop']['rows']['main']['height']);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
        $this->assertArrayNotHasKey('stale', Cache::get(StorefrontAppearance::CACHE_KEY) ?? []);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'the 52px is a variable in the token stylesheet');
    }

    public function test_an_out_of_range_height_is_refused_and_nothing_is_stored(): void
    {
        $this->screen()->call('selectRow', 'h-d-main')->set('props.height', 5)->call('apply')->assertHasErrors(['props.height']);

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
    }

    // ---- footer: draft and publish ----------------------------------------------------------------------------------------------------

    public function test_a_footer_draft_is_not_public_and_a_publish_replaces_the_cache_entry_and_the_shop_shows_it(): void
    {
        $screen = $this->screen('footer')->call('selectLayout', 'lower')->set('props.text_template', 'Published line [year]')->call('apply')->assertHasNoErrors();

        $this->assertSame('Published line [year]', $this->stored(AppearanceSettings::FOOTER_DRAFT_KEY)['lower']['text_template']);
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_COMPILED_KEY), 'a draft is not published');
        $this->assertNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY));

        Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);
        $screen->callAction('publish')->assertHasNoErrors();

        $this->assertSame('Published line [year]', $this->stored(AppearanceSettings::FOOTER_COMPILED_KEY)['layout']['lower']['text_template']);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::FOOTER_PUBLISHED_KEY));
        $this->assertArrayNotHasKey('stale', Cache::get(StorefrontAppearance::CACHE_KEY) ?? []);

        $this->app->forgetScopedInstances();
        $this->assertStringContainsString('Published line '.now()->year, $this->get('/')->assertOk()->getContent());
    }

    // ---- the journal never holds a value ------------------------------------------------------------------------------------------------

    public function test_the_journal_names_the_staff_member_and_the_action_and_never_a_value(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $staff = $this->actingAsStaff('Administrator');

        $header = $this->screen()->call('selectRow', 'h-d-main')->set('props.height', 53)->call('apply');
        $header->call('publish');
        $footer = $this->screen('footer')->call('selectLayout', 'lower')->set('props.text_template', 'Secret 4242 [year]')->call('apply');
        $footer->call('publish');

        foreach (['storefront_header' => '53', 'storefront_footer' => '4242'] as $entity => $secret) {
            $rows = DB::table('activity_log')->where('entity_type', $entity)->orderBy('id')->get();

            $this->assertSame(['draft', 'published'], $rows->pluck('field')->all(), $entity);
            $this->assertSame([$staff->name, $staff->name], $rows->pluck('staff_name')->all(), $entity);
            $this->assertSame(['saved', 'saved'], $rows->pluck('new_value')->all(), $entity);
            $this->assertSame([], $rows->filter(fn ($r): bool => str_contains((string) $r->old_value.(string) $r->new_value, $secret))->all(), $entity.': no value in the journal');
        }
    }

    // ---- the drawer ---------------------------------------------------------------------------------------------------------------------

    public function test_the_capitals_switch_is_saved_published_reloaded_and_reaches_the_drawer(): void
    {
        $screen = $this->screen()->call('setDevice', 'mobile')->call('selectLayout')->assertSet('props.drawer_upper', true);

        $screen->set('props.drawer_upper', false)->call('apply')->assertHasNoErrors();
        $this->assertFalse($this->stored(AppearanceSettings::HEADER_DRAFT_KEY)['mobile']['drawer_upper']);

        $screen->callAction('publish')->assertHasNoErrors();
        $this->assertFalse($this->stored(AppearanceSettings::HEADER_PUBLISHED_KEY)['mobile']['drawer_upper']);

        $this->screen()->call('setDevice', 'mobile')->call('selectLayout')->assertSet('props.drawer_upper', false);
        $this->app->forgetScopedInstances();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('class="sf-drawer sf-drawer-left"', $html);
        $this->assertStringNotContainsString('sf-drawer-upper', $html);
    }

    public function test_an_apply_that_does_not_touch_the_switch_keeps_the_stored_value(): void
    {
        $layout = HeaderLayout::default();
        $layout['mobile']['drawer_upper'] = false;
        $this->settings()->set(AppearanceSettings::HEADER_PUBLISHED_KEY, (string) json_encode($layout));

        $this->screen()->call('selectRow', 'h-d-main')->set('props.height', 60)->call('apply')->assertHasNoErrors();

        $this->assertFalse($this->stored(AppearanceSettings::HEADER_DRAFT_KEY)['mobile']['drawer_upper']);
    }

    public function test_an_old_stored_drawer_with_an_account_element_still_publishes(): void
    {
        $old = HeaderLayout::default();
        $old['mobile']['drawer'] = [['type' => 'menu', 'settings' => ['style' => 'links']], ['type' => 'account', 'settings' => ['style' => 'icon']]];
        $this->assertTrue(HeaderValidator::validate($old)->passes());
        $this->settings()->set(AppearanceSettings::HEADER_PUBLISHED_KEY, (string) json_encode($old));

        $this->screen()->callAction('publish')->assertHasNoErrors();

        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY));
        $this->assertSame(['menu', 'account'], array_column($this->stored(AppearanceSettings::HEADER_PUBLISHED_KEY)['mobile']['drawer'], 'type'));
    }

    // ---- what comes back --------------------------------------------------------------------------------------------------------------

    public function test_the_colours_and_text_size_of_a_row_come_back_after_a_reload(): void
    {
        $this->screen()->call('selectRow', 'h-d-top')->set('props.colors.bg', '#112233')->set('props.colors.fg', '#ffffff')->set('props.text_size', 'l')->call('apply')->assertHasNoErrors();
        $this->screen()->callAction('publish')->assertHasNoErrors();

        $top = $this->stored(AppearanceSettings::HEADER_COMPILED_KEY)['layout']['desktop']['rows']['top'];
        $this->assertSame(['#112233', '#ffffff', 'l'], [$top['bg'], $top['fg'], $top['text_size']]);

        $props = $this->screen()->get('tree')['desktop']['rows'][0]['props'];
        $this->assertSame(['colors' => ['bg' => '#112233', 'fg' => '#ffffff'], 'text_size' => 'l'], array_intersect_key($props, array_flip(['colors', 'text_size'])));
    }

    public function test_a_full_header_layout_survives_apply_unchanged_and_the_shop_shows_it(): void
    {
        $el = fn (string $type, array $settings): array => ['type' => $type, 'settings' => $settings];
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['top'] = ['enabled' => true, 'height' => 40, 'left' => [$el('button', ['label' => 'Виж', 'url' => '/sale', 'style' => 'outline'])], 'center' => [$el('spacer', ['width' => 'lg'])], 'right' => [$el('text', ['text' => '0888 123 456', 'url' => '/contacts'])]] + $layout['desktop']['rows']['top'];
        $layout['desktop']['rows']['bottom'] = ['enabled' => true, 'height' => 48, 'left' => [$el('social', ['items' => [['network' => 'facebook', 'url' => 'https://example.test/fb'], ['network' => 'instagram', 'url' => 'https://example.test/ig']]])], 'center' => [$el('html', ['html' => '<p>Одит <b>блок</b></p>'])], 'right' => [$el('text', ['text' => 'Долен текст', 'url' => ''])]] + $layout['desktop']['rows']['bottom'];
        $layout['mobile']['drawer'] = [$el('text', ['text' => 'Чекмедже', 'url' => '']), $el('menu', ['menu' => null, 'style' => 'links'])];
        $layout['mobile']['drawer_side'] = 'right';
        $valid = HeaderValidator::validate($layout);
        $this->assertTrue($valid->passes(), json_encode($valid->errors ?? []));
        $this->settings()->set(AppearanceSettings::HEADER_PUBLISHED_KEY, (string) json_encode($valid->layout));

        $screen = $this->screen()->call('apply')->assertHasNoErrors();

        $this->assertEquals($valid->layout, $this->stored(AppearanceSettings::HEADER_DRAFT_KEY), 'every element, setting and order comes back as it was stored');

        $screen->callAction('publish')->assertHasNoErrors();
        $this->app->forgetScopedInstances();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('0888 123 456', $html);
        $this->assertStringContainsString('Одит <b>блок</b>', $html);
        $this->assertStringContainsString('Чекмедже', $html);
    }

    public function test_a_full_footer_layout_survives_apply_unchanged(): void
    {
        $layout = FooterLayout::default();
        $layout['upper']['rows'][0]['columns'][] = ['span' => 2, 'align' => 'center', 'blocks' => [
            ['type' => 'text', 'settings' => ['heading' => 'Hello', 'body' => 'World']],
            ['type' => 'spacer', 'settings' => ['size' => 'l']],
        ]];
        $layout['upper']['rows'][] = ['columns' => [['span' => 1, 'align' => 'end', 'blocks' => [['type' => 'contacts', 'settings' => ['phone' => '0888 111 222', 'email' => 'a@b.test', 'address' => 'Sofia', 'hours' => '9-18']]]]]];
        $layout['lower']['text_template'] = 'Line [year]';
        $valid = FooterValidator::validate($layout);
        $this->assertTrue($valid->passes(), json_encode($valid->errors ?? []));
        $this->settings()->set(AppearanceSettings::FOOTER_PUBLISHED_KEY, (string) json_encode($valid->layout));

        $this->screen('footer')->call('apply')->assertHasNoErrors();

        $this->assertEquals($valid->layout, $this->stored(AppearanceSettings::FOOTER_DRAFT_KEY));
    }
}
