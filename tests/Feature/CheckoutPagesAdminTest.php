<?php

namespace Tests\Feature;

use App\Content\Models\PageModel;
use App\Content\PageWriter;
use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\PageResource;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Pages, stage P1 (pages-design.md section 5), the admin side: the checkout settings choose a page or an address for the terms and the
 * privacy link, and the order view links a `p{id}r{n}` terms version to that revision.
 */
class CheckoutPagesAdminTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
    }

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    private function page(array $override = []): PageModel
    {
        return app(PageWriter::class)->create(array_merge(['title' => 'Terms', 'slug' => 'terms', 'body' => '<p>One.</p>', 'status' => 'published'], $override));
    }

    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAsStaff('Administrator');
        app()->setLocale('en');

        return Livewire::test(CheckoutSettings::class);
    }

    public function test_the_select_offers_only_published_pages_and_the_stored_choice_comes_back(): void
    {
        $terms = $this->page();
        $this->page(['title' => 'Secret draft', 'slug' => 'secret', 'status' => 'draft']);
        $this->settings()->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, (string) $terms->id);

        $component = $this->form();

        $component->assertFormSet(['terms_source' => 'page', 'terms_page_id' => $terms->id, 'privacy_source' => 'url']);
        $html = $component->html();
        $this->assertStringContainsString('Terms', $html);
        $this->assertStringNotContainsString('Secret draft', $html);
    }

    public function test_saving_a_page_stores_its_id_leaves_the_address_and_does_not_touch_the_version_by_date(): void
    {
        $terms = $this->page();
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, 'https://shop.test/old');
        $this->settings()->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-01-01');

        $this->form()->fillForm(['terms_source' => 'page', 'terms_page_id' => $terms->id])->call('save')->assertHasNoFormErrors();

        $this->assertSame((string) $terms->id, $this->settings()->get(CheckoutFormSettings::TERMS_PAGE_ID_KEY));
        $this->assertSame('https://shop.test/old', $this->settings()->get(CheckoutFormSettings::TERMS_URL_KEY));
        $this->assertSame('2026-01-01', $this->settings()->get(CheckoutFormSettings::TERMS_VERSION_KEY), 'in page mode the revision is the version');
        $this->assertSame('/info/terms', app(CheckoutFormSettings::class)->safeTermsUrl());
    }

    public function test_switching_back_to_an_address_forgets_the_page_choice(): void
    {
        $terms = $this->page();
        $privacy = $this->page(['title' => 'Privacy', 'slug' => 'privacy']);
        $this->settings()->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, (string) $terms->id);
        $this->settings()->set(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY, (string) $privacy->id);

        $this->form()
            ->fillForm(['terms_source' => 'url', 'terms_url' => 'https://shop.test/terms', 'privacy_source' => 'url', 'privacy_url' => '/privacy-url'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertNull($this->settings()->get(CheckoutFormSettings::TERMS_PAGE_ID_KEY));
        $this->assertNull($this->settings()->get(CheckoutFormSettings::PRIVACY_PAGE_ID_KEY));
        $this->assertSame('https://shop.test/terms', $this->settings()->get(CheckoutFormSettings::TERMS_URL_KEY));
    }

    public function test_an_unpublished_terms_page_is_warned_about_on_the_settings_page(): void
    {
        $terms = $this->page();
        $this->settings()->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, (string) $terms->id);
        $warning = __('settings.checkout_page.warning_terms_page_unpublished');

        $this->form()->assertDontSee($warning);

        app(PageWriter::class)->archive($terms);
        $this->app->forgetScopedInstances(); // one request per process in production; the test makes several

        $this->form()->assertSee($warning);
    }

    public function test_the_order_view_links_a_page_version_to_that_revision_for_staff_who_may_open_pages(): void
    {
        $terms = $this->page();
        $this->settings()->set(CheckoutFormSettings::TERMS_PAGE_ID_KEY, (string) $terms->id);
        $version = 'p'.$terms->id.'r1';
        $this->app->forgetScopedInstances();
        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => $version])->assertStatus(201);
        $orderId = DB::table('orders')->value('id');
        $url = PageResource::getUrl('revision', ['record' => $terms->id, 'revision' => 1]);

        $this->actingAsStaff('Administrator');
        app()->setLocale('en');
        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
        $this->assertStringContainsString('href="'.e($url).'"', $html);
        $this->assertStringContainsString('>'.$version.'</a>', $html);

        // Staff who may see orders but not pages read the version as plain text.
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();
        $this->assertStringNotContainsString($url, $html);
        $this->assertStringContainsString($version, $html);
    }
}
