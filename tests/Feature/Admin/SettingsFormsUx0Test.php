<?php

namespace Tests\Feature\Admin;

use App\Content\Menus\MenuModel;
use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Filament\Pages\Settings\BankTransferSettings;
use App\Filament\Pages\Settings\CatalogSettings;
use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\Pages\Settings\HeaderSettings;
use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\Pages\Settings\MailSettings;
use App\Filament\Pages\Settings\StorefrontSettings;
use App\Filament\Resources\MenuResource;
use App\Filament\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\CreatePage;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Feature\Storefront\BuildsMenus;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * UX-0 (the "settings do not save" audit, tests/Browser/admin-save-audit.mjs): the guards of what a real browser showed and the Livewire tests could not.
 *
 *  1. Every admin form hands its validation to the SERVER (`novalidate`): the browser's own `required` check blocked the Save silently, a hidden field could not
 *     even show its tooltip.
 *  2. A refusal that comes from our own code (the header validator, the menu writer, the page writer) dispatches Filament's `form-validation-error`, the event that
 *     opens the collapsed section / tab / repeater item holding the first error, so "nothing happened" never looks like "saved".
 *  3. A header made before the menus existed shows a warning and is pointed at `main` by a migration.
 *  4. The standard validation messages exist in Bulgarian (the audit saw "The категория field is required.").
 */
class SettingsFormsUx0Test extends TestCase
{
    use BuildsMenus;
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
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

    // ---- 1. novalidate ----------------------------------------------------------------------------------------------------------------

    public function test_every_admin_form_hands_its_validation_to_the_server(): void
    {
        $this->actingAsStaff('Administrator');
        $page = app(PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'status' => 'published']);
        $urls = [
            AppearancePage::getUrl(), BankTransferSettings::getUrl(), CatalogSettings::getUrl(), CheckoutSettings::getUrl(), HeaderSettings::getUrl(), LocaleSettings::getUrl(),
            MailSettings::getUrl(), StorefrontSettings::getUrl(), MenuResource::getUrl('edit', ['record' => MenuModel::query()->where('key', 'main')->firstOrFail()]),
            PageResource::getUrl('create'), PageResource::getUrl('edit', ['record' => $page]),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<form\b[^>]*\bnovalidate\b/', $html, $url.': the form must carry novalidate');
        }
    }

    // ---- 2. our own refusals open the block that holds the error ---------------------------------------------------------------------------

    public function test_a_header_refusal_dispatches_the_event_that_opens_the_block(): void
    {
        $this->actingAsStaff('Administrator');
        $component = Livewire::test(HeaderSettings::class);
        $logo = array_key_first($component->get('data.desktop.rows.main.center'));

        $component->set('data.desktop.rows.main.height', 44)->set("data.desktop.rows.main.center.{$logo}.data.height", 40)->callAction('publish');

        $component->assertHasErrors()->assertDispatched('form-validation-error');
    }

    public function test_a_menu_refusal_dispatches_the_event_that_opens_the_block(): void
    {
        $this->actingAsStaff('Administrator');
        $this->saveMenu('main', [$this->menuItem('link', ['label' => 'Home', 'url' => '/'])]);
        $component = Livewire::test(EditMenu::class, ['record' => MenuModel::query()->where('key', 'main')->value('id')]);
        $key = array_key_first($component->get('data.items'));

        $component->set("data.items.{$key}.url", 'javascript:alert(1)')->call('save');

        $component->assertHasErrors(["data.items.{$key}.url"])->assertDispatched('form-validation-error');
    }

    public function test_a_page_refusal_dispatches_the_event_that_opens_the_block(): void
    {
        $this->actingAsStaff('Administrator');
        $page = app(PageWriter::class)->create(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>x</p>', 'status' => 'published']);

        // the SEO title sits in a collapsed section; a bidirectional override is refused by the writer
        $edit = Livewire::test(EditPage::class, ['record' => $page->id])->fillForm(['meta_title' => "Bad\u{202E}title"])->call('save');
        $edit->assertHasErrors(['data.meta_title'])->assertDispatched('form-validation-error');

        $create = Livewire::test(CreatePage::class)->fillForm(['title' => 'Terms again', 'slug' => 'terms', 'body' => '<p>y</p>', 'status' => 'draft'])->call('create');
        $create->assertHasErrors(['data.slug'])->assertDispatched('form-validation-error');
        $this->assertSame(1, PageModel::query()->count());
    }

    // ---- 3. a header made before the menus existed ----------------------------------------------------------------------------------------------

    public function test_the_header_page_warns_while_a_menu_element_still_shows_the_category_tree(): void
    {
        $this->actingAsStaff('Administrator');
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings']['source'] = 'categories';
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($layout));

        Livewire::test(HeaderSettings::class)->assertSee(__('header.warnings.categories_source'));

        $layout['desktop']['rows']['main']['left'][0]['settings']['source'] = 'main';
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($layout));

        Livewire::test(HeaderSettings::class)->assertDontSee(__('header.warnings.categories_source'));
        app()->setLocale('bg');
        $this->assertSame('Това меню не следва „Менюта“ — изберете „Главно меню“', __('header.warnings.categories_source'));
    }

    /** @return array<string, mixed> a layout as D2 stored it: its menu elements show the category tree */
    private function preD3Layout(): array
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings'] = ['style' => 'links']; // no source at all (the D2 default stored without one)
        $layout['mobile']['drawer'][0]['settings'] = ['source' => 'categories', 'style' => 'links'];

        return $layout;
    }

    private function runHeaderMigration(): void
    {
        (require database_path('migrations/2026_10_22_000001_point_header_menus_at_main.php'))->up();
        $this->app->forgetScopedInstances();
    }

    public function test_the_migration_points_a_pre_d3_header_at_main_and_is_idempotent(): void
    {
        $layout = $this->preD3Layout();
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($layout));

        $this->runHeaderMigration();

        foreach ([AppearanceSettings::HEADER_PUBLISHED_KEY, AppearanceSettings::HEADER_DRAFT_KEY] as $key) {
            $stored = json_decode((string) $this->settings()->get($key), true);
            $this->assertSame('main', $stored['desktop']['rows']['main']['left'][0]['settings']['source'], $key.': desktop');
            $this->assertSame('main', $stored['mobile']['drawer'][0]['settings']['source'], $key.': drawer');
        }

        $compiled = json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), true);
        $this->assertSame('main', $compiled['layout']['desktop']['rows']['main']['left'][0]['settings']['source'], 'the compiled copy the shop reads');
        $this->assertSame('main', app(\App\Storefront\Appearance\StorefrontAppearance::class)->header()['desktop']['rows']['main']['left'][0]['settings']['source']);

        $before = [
            $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), $this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY),
        ];
        $this->runHeaderMigration();
        $this->assertSame($before, [
            $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY), $this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY),
        ], 'a second run changes nothing');
    }

    public function test_the_migration_leaves_a_header_that_already_points_at_a_menu_alone(): void
    {
        $layout = HeaderLayout::default();
        $layout['desktop']['rows']['main']['left'][0]['settings']['source'] = 'footer';
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $before = $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY);

        $this->runHeaderMigration();

        $this->assertSame($before, $this->settings()->get(AppearanceSettings::HEADER_COMPILED_KEY));
        $this->assertSame('footer', json_decode((string) $this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), true)['desktop']['rows']['main']['left'][0]['settings']['source']);
    }

    public function test_the_migration_changes_nothing_without_a_main_menu(): void
    {
        $layout = $this->preD3Layout();
        app(HeaderCompiler::class)->publish($layout, $this->settings());
        $this->settings()->set(AppearanceSettings::HEADER_DRAFT_KEY, json_encode($layout));
        MenuModel::query()->where('key', 'main')->delete();
        $before = [$this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY)];

        $this->runHeaderMigration();

        $this->assertSame($before, [$this->settings()->get(AppearanceSettings::HEADER_PUBLISHED_KEY), $this->settings()->get(AppearanceSettings::HEADER_DRAFT_KEY)]);
    }

    // ---- 4. the messages in Bulgarian --------------------------------------------------------------------------------------------------------------

    public function test_the_standard_validation_messages_exist_in_bulgarian(): void
    {
        app()->setLocale('bg');

        $this->assertSame('Полето категория е задължително.', __('validation.required', ['attribute' => 'категория']));
        $this->assertStringContainsString('не може да е по-дълго от 120 знака', __('validation.max.string', ['attribute' => 'етикет', 'max' => 120]));
        $this->assertStringNotContainsString('The ', __('validation.min.numeric', ['attribute' => 'x', 'min' => 1]));
    }
}
