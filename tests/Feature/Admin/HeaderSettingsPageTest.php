<?php

namespace Tests\Feature\Admin;

use App\Filament\NavigationGroup;
use App\Filament\Pages\Settings\HeaderSettings;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\Header\HeaderLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage D2: the admin page «Хедър» / "Header": reached by the content permission, saves a DRAFT, publishes (compiles, replaces the reader's cache entry),
 * discards the draft, maps the validator's messages onto form fields, journals the action (never a value) and links to its help anchor.
 */
class HeaderSettingsPageTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        app()->setLocale('en');
        Storage::fake('public');
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(HeaderSettings::class);
    }

    public function test_the_content_roles_reach_the_page_and_a_role_without_the_permission_does_not(): void
    {
        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);
            $this->get(HeaderSettings::getUrl())->assertOk();
        }

        $this->actingAsStaff('Product Entry');
        $this->get(HeaderSettings::getUrl())->assertForbidden();
    }

    public function test_it_sits_in_the_admin_group_with_its_own_label(): void
    {
        $this->assertSame(NavigationGroup::ADMIN, HeaderSettings::getNavigationGroup());
        $this->assertSame('Header', HeaderSettings::getNavigationLabel());
        app()->setLocale('bg');
        $this->assertSame('Хедър', HeaderSettings::getNavigationLabel());
    }

    public function test_it_starts_from_the_seeded_default_and_saves_a_draft_that_is_not_public(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['desktop.rows.main.height' => 52])->callAction('save_draft')->assertHasNoFormErrors();

        $draft = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY), true);
        $this->assertSame(52, $draft['desktop']['rows']['main']['height']);
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), 'a draft is not published');
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
    }

    public function test_publishing_stores_compiles_and_replaces_the_cache_entry(): void
    {
        $this->actingAsStaff('Administrator');
        \Illuminate\Support\Facades\Cache::forever(StorefrontAppearance::CACHE_KEY, ['stale' => true]);

        $this->page()->fillForm(['desktop.rows.main.height' => 52])->callAction('publish')->assertHasNoFormErrors();

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        $this->assertSame(52, $compiled['layout']['desktop']['rows']['main']['height']);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
        $this->assertArrayNotHasKey('stale', \Illuminate\Support\Facades\Cache::get(StorefrontAppearance::CACHE_KEY) ?? []);
        $this->assertNotNull($this->settings()->get(AppearanceSettings::COMPILED_KEY), 'the 52px is a variable in the token stylesheet');
    }

    public function test_a_range_error_is_shown_on_its_own_field_and_nothing_is_stored(): void
    {
        $this->actingAsStaff('Administrator');

        $this->page()->fillForm(['desktop.rows.main.height' => 5])->call('publish')->assertHasErrors(['data.desktop.rows.main.height']);

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY));
        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY));
    }

    public function test_discard_forgets_the_draft_and_goes_back_to_the_published_layout(): void
    {
        $this->actingAsStaff('Administrator');
        $component = $this->page();
        $component->fillForm(['desktop.rows.main.height' => 52])->callAction('publish');
        $component->fillForm(['desktop.rows.main.height' => 60])->callAction('save_draft');
        $this->assertNotNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));

        $component->callAction('discard');

        $this->assertNull($this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY));
        $component->assertFormSet(['desktop.rows.main.height' => 52]);
        $this->assertSame(64, HeaderLayout::default()['desktop']['rows']['main']['height']);
    }

    public function test_every_action_is_journalled_without_a_value(): void
    {
        $this->settings()->set('admin.activity_log_enabled', '1');
        $staff = $this->actingAsStaff('Administrator');

        $component = $this->page();
        $component->fillForm(['desktop.rows.main.height' => 53])->callAction('save_draft');
        $component->callAction('publish');

        $rows = DB::table('activity_log')->where('entity_type', 'storefront_header')->orderBy('id')->get();

        $this->assertSame(['draft', 'published'], $rows->pluck('field')->all());
        $this->assertSame([$staff->name, $staff->name], $rows->pluck('staff_name')->all());
        $this->assertStringNotContainsString('53', (string) json_encode($rows->all()));
    }

    public function test_the_help_anchor_exists_in_both_languages_and_the_page_links_to_it(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertStringContainsString('{#action-appearance-header}', (string) file_get_contents(resource_path("help/{$locale}/appearance.md")));
        }

        $this->actingAsStaff('Administrator');
        $html = $this->get(HeaderSettings::getUrl())->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/admin/help/appearance\#action-appearance-header"#', $html);
    }
}
