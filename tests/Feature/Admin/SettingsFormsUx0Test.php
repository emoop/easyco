<?php

namespace Tests\Feature\Admin;

use App\Content\Menus\MenuModel;
use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Filament\Pages\Settings\AppearanceSettings as AppearancePage;
use App\Filament\Pages\Settings\BankTransferSettings;
use App\Filament\Pages\Settings\CatalogSettings;
use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\Pages\Settings\MailSettings;
use App\Filament\Pages\Settings\StorefrontSettings;
use App\Filament\Resources\HtmlBlockResource;
use App\Filament\Resources\PageResource;
use App\Filament\Resources\PageResource\Pages\CreatePage;
use App\Filament\Resources\PageResource\Pages\EditPage;
use App\Settings\Contracts\SiteSettingsRepository;
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
 *  3. The standard validation messages exist in Bulgarian (the audit saw "The категория field is required.").
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
            AppearancePage::getUrl(), BankTransferSettings::getUrl(), CatalogSettings::getUrl(), CheckoutSettings::getUrl(), LocaleSettings::getUrl(),
            MailSettings::getUrl(), StorefrontSettings::getUrl(), HtmlBlockResource::getUrl('create'),
            PageResource::getUrl('create'), PageResource::getUrl('edit', ['record' => $page]),
        ];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<form\b[^>]*\bnovalidate\b/', $html, $url.': the form must carry novalidate');
        }
    }

 

    // (The menu editor is no Filament form any more: it is a page of its own that shows the errors on the cards and opens and scrolls to the first one itself; see MenusPageTest.)

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

    // ---- 3. (retired by MENU-2) --------------------------------------------------------------------------------------------------------------------
    // UX-0 warned about a header menu element still on `categories` and pointed it at `main` with a migration. MENU-2 removed the `source` setting altogether (a menu
    // element shows the menu of its location) and its own migration (2026_10_23_000002, tested in Menu2MigrationTest) strips `source` from every stored layout, so the
    // warning and the three tests of the old migration were removed with the feature they guarded.

    // ---- 3. the messages in Bulgarian --------------------------------------------------------------------------------------------------------------

    public function test_the_standard_validation_messages_exist_in_bulgarian(): void
    {
        app()->setLocale('bg');

        $this->assertSame('Полето категория е задължително.', __('validation.required', ['attribute' => 'категория']));
        $this->assertStringContainsString('не може да е по-дълго от 120 знака', __('validation.max.string', ['attribute' => 'етикет', 'max' => 120]));
        $this->assertStringNotContainsString('The ', __('validation.min.numeric', ['attribute' => 'x', 'min' => 1]));
    }
}
