<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Checkout form v2, stage C1d-B, the admin side (checkout-form-v2-design.md 6.1, 6.6): the "Fields at checkout" section of the settings
 * page (the controls, the automatic terms version, the link test, the warnings) and what the order pages show of what the customer ticked.
 */
class CheckoutFieldsSettingsTest extends TestCase
{
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

    private function staff(): void
    {
        $roles = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roles);
        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Admin Tester', $roles->findSystemRoleByName('Administrator'));
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        $this->staff();
        app()->setLocale('en');

        return Livewire::test(CheckoutSettings::class);
    }

    // ---- the settings page ------------------------------------------------------------------------------------------------------------

    public function test_the_page_shows_what_is_stored_and_the_defaults_when_nothing_is(): void
    {
        $this->page()->assertFormSet([
            'call_before_shipping_enabled' => false, 'newsletter_enabled' => false, 'terms_enabled' => true, 'terms_url' => '', 'terms_version' => '', 'privacy_url' => '',
        ]);

        $this->settings()->set(CheckoutFormSettings::NEWSLETTER_KEY, '1');
        $this->settings()->set(CheckoutFormSettings::TERMS_ENABLED_KEY, '0');
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, '/terms');
        $this->settings()->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-01-01');

        $this->page()->assertFormSet(['newsletter_enabled' => true, 'terms_enabled' => false, 'terms_url' => '/terms', 'terms_version' => '2026-01-01']);
    }

    public function test_saving_writes_the_keys_and_forgets_the_defaults_to_keep_the_store_clean(): void
    {
        $this->page()->fillForm([
            'call_before_shipping_enabled' => true, 'newsletter_enabled' => true, 'terms_enabled' => false,
            'terms_url' => ' https://shop.test/terms ', 'privacy_url' => '/privacy',
        ])->call('save')->assertHasNoFormErrors();

        $this->assertSame('1', $this->settings()->get(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY));
        $this->assertSame('1', $this->settings()->get(CheckoutFormSettings::NEWSLETTER_KEY));
        $this->assertSame('0', $this->settings()->get(CheckoutFormSettings::TERMS_ENABLED_KEY));
        $this->assertSame('https://shop.test/terms', $this->settings()->get(CheckoutFormSettings::TERMS_URL_KEY), 'trimmed');
        $this->assertSame('/privacy', $this->settings()->get(CheckoutFormSettings::PRIVACY_URL_KEY));

        $this->page()->fillForm(['call_before_shipping_enabled' => false, 'newsletter_enabled' => false, 'terms_enabled' => true, 'terms_url' => '', 'privacy_url' => ''])
            ->call('save')->assertHasNoFormErrors();

        foreach ([CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, CheckoutFormSettings::NEWSLETTER_KEY, CheckoutFormSettings::TERMS_ENABLED_KEY, CheckoutFormSettings::TERMS_URL_KEY, CheckoutFormSettings::PRIVACY_URL_KEY] as $key) {
            $this->assertNull($this->settings()->get($key), $key.' is forgotten when it holds its default');
        }
    }

    public function test_the_terms_version_is_set_to_today_by_itself_when_the_url_changes_and_only_then(): void
    {
        $today = now()->format('Y-m-d');

        $this->page()->fillForm(['terms_url' => 'https://shop.test/terms'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($today, $this->settings()->get(CheckoutFormSettings::TERMS_VERSION_KEY), 'a new terms page is a new text');

        // The same URL saved again does not move the version.
        $this->settings()->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-03-03');
        $this->page()->fillForm(['terms_url' => 'https://shop.test/terms', 'newsletter_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame('2026-03-03', $this->settings()->get(CheckoutFormSettings::TERMS_VERSION_KEY));

        // A different URL does.
        $this->page()->fillForm(['terms_url' => '/new-terms'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($today, $this->settings()->get(CheckoutFormSettings::TERMS_VERSION_KEY));
    }

    public function test_the_terms_updated_button_sets_the_version_to_today_and_nobody_types_a_version(): void
    {
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, '/terms');
        $this->settings()->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2020-01-01');

        $page = $this->page();
        $page->assertFormFieldIsDisabled('terms_version');
        $page->fillForm(['terms_version' => 'typed-by-hand'])->call('save');
        $this->assertSame('2020-01-01', $this->settings()->get(CheckoutFormSettings::TERMS_VERSION_KEY), 'the field is read-only: a typed value is not stored');

        $this->page()->call('bumpTermsVersion');
        $this->assertSame(now()->format('Y-m-d'), $this->settings()->get(CheckoutFormSettings::TERMS_VERSION_KEY));
    }

    /** @return array<string, array{string, bool}> */
    public static function links(): array
    {
        return [
            'https' => ['https://shop.test/terms', true],
            'http' => ['http://shop.test/terms', true],
            'a path of the store' => ['/terms', true],
            'a script' => ['javascript:alert(1)', false],
            'a protocol-relative address' => ['//evil.test/x', false],
            'no scheme' => ['shop.test/terms', false],
            'too long' => ['/'.'a'.'a'.str_repeat('a', 2050), false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('links')]
    public function test_both_links_must_be_http_https_or_a_path_of_the_store(string $url, bool $accepted): void
    {
        foreach (['terms_url', 'privacy_url'] as $field) {
            $component = $this->page()->fillForm([$field => $url])->call('save');

            $accepted ? $component->assertHasNoFormErrors() : $component->assertHasFormErrors([$field]);
        }

        $this->assertSame($accepted ? $url : null, $this->settings()->get(CheckoutFormSettings::PRIVACY_URL_KEY));
    }

    public function test_the_warnings_show_for_terms_without_a_link_and_for_no_privacy_link(): void
    {
        $page = $this->page();

        $page->assertSee(__('settings.checkout_page.warning_terms_without_url'));
        $page->assertSee(__('settings.checkout_page.warning_no_privacy_url'));

        $page->fillForm(['terms_url' => '/terms', 'privacy_url' => '/privacy']);
        $page->assertDontSee(__('settings.checkout_page.warning_terms_without_url'));
        $page->assertDontSee(__('settings.checkout_page.warning_no_privacy_url'));

        $page->fillForm(['terms_enabled' => false, 'terms_url' => '']);
        $page->assertDontSee(__('settings.checkout_page.warning_terms_without_url'));
    }

    public function test_the_region_section_still_saves_next_to_the_new_one(): void
    {
        $this->page()->fillForm(['default' => 'required', 'exceptions' => [['country' => 'BG', 'mode' => 'hidden']], 'newsletter_enabled' => true])->call('save')->assertHasNoFormErrors();

        $this->assertSame('required', $this->settings()->get('checkout.region_default'));
        $this->assertSame('{"BG":"hidden"}', $this->settings()->get('checkout.region_countries'));
        $this->assertSame('1', $this->settings()->get(CheckoutFormSettings::NEWSLETTER_KEY));
    }

    // ---- the order pages --------------------------------------------------------------------------------------------------------------

    private function placeTicked(): string
    {
        $this->settings()->set(CheckoutFormSettings::CALL_BEFORE_SHIPPING_KEY, '1');
        $this->settings()->set(CheckoutFormSettings::NEWSLETTER_KEY, '1');
        $this->settings()->set(CheckoutFormSettings::TERMS_URL_KEY, '/terms');
        $this->settings()->set(CheckoutFormSettings::TERMS_VERSION_KEY, '2026-10-14');

        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => '2026-10-14', 'call_before_shipping' => true, 'marketing_consent' => true])->assertStatus(201);

        return (string) DB::table('orders')->orderByDesc('id')->value('id');
    }

    public function test_the_order_view_shows_the_real_values_and_na_for_what_was_not_asked(): void
    {
        $orderId = $this->placeTicked();
        $this->staff();
        app()->setLocale('en');

        $html = Livewire::test(ViewOrder::class, ['record' => $orderId])->html();

        $this->assertMatchesRegularExpression('/Terms accepted.{0,1500}?Yes · [A-Z][a-z]{2} \d{1,2}, \d{4} \d\d:\d\d · 2026-10-14/s', $html);
        $this->assertMatchesRegularExpression('/Call before shipping.{0,1500}?Yes/s', $html);
        $this->assertStringContainsString('Newsletter consent', $html);
        $this->assertStringContainsString('Yes — «Subscribe me to the shop&#039;s newsletter with news and offers.»', $html, 'the exact sentence the customer ticked, escaped');
    }

    public function test_an_order_that_was_not_asked_says_na_for_every_choice(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->staff();
        app()->setLocale('en');

        $html = Livewire::test(ViewOrder::class, ['record' => DB::table('orders')->value('id')])->html();

        foreach (['Terms accepted', 'Call before shipping', 'Newsletter consent'] as $label) {
            $this->assertMatchesRegularExpression('/'.preg_quote($label, '/').'.{0,1500}?n\/a/s', $html, $label);
        }
    }

    public function test_the_list_marks_an_order_that_asked_for_a_call_with_a_phone_and_a_tooltip(): void
    {
        $called = $this->placeTicked();
        $this->staff();
        app()->setLocale('en');

        $html = Livewire::test(ListOrders::class)->assertCanSeeTableRecords([OrderModel::find($called)])->html();

        $this->assertSame(1, substr_count($html, 'Call before shipping'), 'the tooltip, once');
    }

    public function test_an_order_that_did_not_ask_has_no_phone_and_the_icon_costs_no_query(): void
    {
        $called = $this->placeTicked();
        $this->checkout($this->streetFields('BG'), ['terms_accepted' => true, 'terms_version' => '2026-10-14'])->assertStatus(201);
        $plain = (string) DB::table('orders')->orderByDesc('id')->value('id');
        $this->staff();
        app()->setLocale('en');

        $this->assertSame(0, substr_count(Livewire::test(ListOrders::class)->searchTable($plain)->html(), 'Call before shipping'));

        // The icon is read from the row the list already loaded: an order with the phone and one without cost the same number of queries.
        $count = function (string $term): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            Livewire::test(ListOrders::class)->searchTable($term);

            return $queries;
        };
        $count($called);
        $this->assertSame($count($called), $count($plain));
    }
}
