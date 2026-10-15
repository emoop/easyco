<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\CheckoutSettings;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Settings\CheckoutFormSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Stage PH-2: the merchant's OPTIONAL "mobile phone numbers only" for the storefront (checkout and the address book). Off by default; never applies to
 * staff in the order editor; a number that is not valid at all keeps the PH-1 message.
 */
class PhoneMobileOnlyTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    private const LANDLINE = '2458712';

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        app()->setLocale('en');
    }

    private function mobileOnly(bool $on): void
    {
        $settings = app(SiteSettingsRepository::class);
        $on ? $settings->set(CheckoutFormSettings::PHONE_MOBILE_ONLY_KEY, '1') : $settings->forget(CheckoutFormSettings::PHONE_MOBILE_ONLY_KEY);
        $this->app->forgetScopedInstances();
    }

    private function place(string $phone, string $country = 'BG'): \Illuminate\Testing\TestResponse
    {
        return $this->checkout($this->streetFields($country), ['phone' => $phone]);
    }

    private const MOBILE_MESSAGE = 'Please enter a mobile number: the courier sends the delivery message to it.';

    private const INVALID_MESSAGE = 'The phone number is not valid. Check that no digit is missing.';

    public function test_with_the_option_off_a_sofia_landline_is_accepted(): void
    {
        $this->assertFalse(app(CheckoutFormSettings::class)->phoneMobileOnly(), 'off by default');

        $id = (string) $this->place(self::LANDLINE)->assertStatus(201)->json('order.id');

        $this->assertSame('+3592458712', DB::table('orders')->where('id', $id)->value('phone'));
    }

    public function test_with_the_option_on_the_same_landline_is_refused_with_the_mobile_message_and_a_mobile_is_accepted(): void
    {
        $this->mobileOnly(true);

        $this->place(self::LANDLINE)->assertStatus(422)->assertJsonPath('errors.phone.0', self::MOBILE_MESSAGE);
        $this->assertSame(0, DB::table('orders')->count());

        $this->place('0888 123 456')->assertStatus(201);
    }

    public function test_the_mobile_message_is_the_bulgarian_sentence_in_a_bulgarian_shop(): void
    {
        $this->mobileOnly(true);
        app()->setLocale('bg');

        $this->place(self::LANDLINE)->assertStatus(422)->assertJsonPath('errors.phone.0', 'Моля, въведете мобилен номер: на него куриерът изпраща съобщение за доставката.');
    }

    public function test_with_the_option_on_a_number_that_is_not_valid_at_all_keeps_the_invalid_message(): void
    {
        $this->mobileOnly(true);

        $this->place('088812345')->assertStatus(422)->assertJsonPath('errors.phone.0', self::INVALID_MESSAGE);
    }

    public function test_with_the_option_on_a_us_number_passes_because_it_may_be_a_mobile(): void
    {
        $this->mobileOnly(true);

        $id = (string) $this->place('+1 202 555 0123', 'US')->assertStatus(201)->json('order.id');
        $this->assertSame('+12025550123', DB::table('orders')->where('id', $id)->value('phone'));
    }

    public function test_the_address_book_follows_the_same_option(): void
    {
        $account = Account::register('mobile-'.uniqid().'@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $customer = AccountModel::findOrFail($account->id());
        $payload = ['delivery_type' => 'street_address', 'recipient_first_name' => 'Ana', 'recipient_last_name' => 'Petrova', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1'];

        $this->actingAs($customer, 'customer')->postJson('/api/addresses', $payload + ['phone' => self::LANDLINE])->assertStatus(201);

        $this->mobileOnly(true);
        $this->actingAs($customer, 'customer')->postJson('/api/addresses', $payload + ['phone' => self::LANDLINE])->assertStatus(422)->assertJsonPath('errors.phone.0', self::MOBILE_MESSAGE);
        $this->actingAs($customer, 'customer')->postJson('/api/addresses', $payload + ['phone' => '0888 123 456'])->assertStatus(201);
    }

    public function test_the_admin_order_editor_still_accepts_a_landline_while_the_option_is_on(): void
    {
        $id = (string) $this->place('0888123456')->assertStatus(201)->json('order.id');
        $this->mobileOnly(true);
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(ViewOrder::class, ['record' => $id]);
        $component->mountAction(TestAction::make('edit_order')->schemaComponent(true, 'infolist'));
        $seeded = $component->instance()->mountedActions[0]['data'];

        $component->fillForm(['lines' => $seeded['lines'], 'delivery' => array_merge($seeded['delivery'], ['phone' => '02 123 4567'])])->callMountedAction()->assertHasNoFormErrors();

        $this->assertSame('+35921234567', DB::table('orders')->where('id', $id)->value('phone'), 'staff may type any valid number');
    }

    public function test_the_settings_page_toggle_saves_and_reads_back(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(CheckoutSettings::class)->assertFormSet(['phone_mobile_only' => false])
            ->fillForm(['phone_mobile_only' => true])->call('save')->assertHasNoFormErrors();
        $this->assertSame('1', app(SiteSettingsRepository::class)->get(CheckoutFormSettings::PHONE_MOBILE_ONLY_KEY));
        $this->app->forgetScopedInstances();

        Livewire::test(CheckoutSettings::class)->assertFormSet(['phone_mobile_only' => true])
            ->fillForm(['phone_mobile_only' => false])->call('save')->assertHasNoFormErrors();
        $this->assertNull(app(SiteSettingsRepository::class)->get(CheckoutFormSettings::PHONE_MOBILE_ONLY_KEY), 'off = the key is forgotten');
    }

    public function test_the_form_settings_still_read_everything_with_one_query(): void
    {
        $this->app->forgetScopedInstances();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $form = app(CheckoutFormSettings::class);
        $form->phoneMobileOnly();
        $form->callBeforeShippingEnabled();
        $form->termsEnabled();
        $form->newsletterEnabled();

        $this->assertSame(1, $queries, 'the new key rides the existing getMany');
    }
}
