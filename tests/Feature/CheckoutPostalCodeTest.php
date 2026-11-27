<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * COUR-2a.5: the postcode is REQUIRED for a delivery to an address (Bulgaria has many villages and small towns with the same name in different regions:
 * the name alone cannot identify the place, the postcode with the region does), checked on the SERVER. Bulgaria's format is four digits; for any other
 * country a non-empty value is enough (no format is invented). An office or a locker is not asked for it: its postcode comes from the point's own place.
 * Everything through POST /api/checkout.
 */
class CheckoutPostalCodeTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRegionOrders();
    }

    private function withoutPostcode(string $country = 'BG'): array
    {
        $fields = $this->streetFields($country);
        unset($fields['postal_code']);

        return $fields;
    }

    public function test_an_address_delivery_without_a_postcode_is_refused_with_the_translated_error_and_writes_nothing(): void
    {
        foreach ([$this->withoutPostcode(), $this->streetFields('BG', ['postal_code' => '']), $this->streetFields('BG', ['postal_code' => '   ']), $this->streetFields('BG', ['postal_code' => null])] as $fields) {
            $response = $this->checkout($fields)->assertStatus(422)->assertJsonValidationErrors('postal_code');

            $this->assertSame(__('storefront.checkout_postal_code_required'), $response->json('errors.postal_code.0'));
        }

        $this->assertSame(0, DB::table('orders')->count());
        $this->assertSame(0, DB::table('addresses')->count());

        $this->checkout($this->streetFields('BG', ['postal_code' => '1000']))->assertStatus(201);
        $this->assertSame('1000', DB::table('orders')->sole()->postal_code);
    }

    public function test_the_error_is_translated_in_both_languages(): void
    {
        foreach (['bg', 'en'] as $locale) {
            $this->assertNotSame('storefront.checkout_postal_code_required', __('storefront.checkout_postal_code_required', [], $locale));
            $this->assertNotSame('storefront.checkout_postal_code_invalid', __('storefront.checkout_postal_code_invalid', [], $locale));
            $this->assertNotSame('storefront.js_checkout.ck_postal_required', __('storefront.js_checkout.ck_postal_required', [], $locale));
        }
    }

    public function test_bulgaria_takes_four_digits_and_nothing_else(): void
    {
        foreach (['123', '12345', 'ABCD', '10 00', '1000a'] as $bad) {
            $response = $this->checkout($this->streetFields('BG', ['postal_code' => $bad]))->assertStatus(422)->assertJsonValidationErrors('postal_code');

            $this->assertSame(__('storefront.checkout_postal_code_invalid'), $response->json('errors.postal_code.0'), "postcode {$bad}");
        }

        $this->assertSame(0, DB::table('orders')->count());
        $this->checkout($this->streetFields('BG', ['postal_code' => '6450']))->assertStatus(201);
    }

    public function test_another_country_needs_a_non_empty_postcode_and_no_format_is_invented(): void
    {
        $this->checkout($this->withoutPostcode('DE'))->assertStatus(422)->assertJsonValidationErrors('postal_code');

        foreach (['10115', 'SW1A 1AA', 'A-123'] as $code) {
            $this->checkout($this->streetFields('DE', ['postal_code' => $code]))->assertStatus(201);
        }
    }

    public function test_an_office_or_a_locker_is_not_asked_for_a_postcode_and_one_sent_is_refused(): void
    {
        $this->checkout($this->pickupFields())->assertStatus(201);
        $this->checkout($this->pickupFields(['pickup_point_kind' => 'locker']))->assertStatus(201);
        $this->assertSame([null, null], DB::table('orders')->pluck('postal_code')->all());

        // the postcode of a point comes from its own place (the order's pick-up snapshot), never from the form
        $this->checkout($this->pickupFields(['postal_code' => '1000']))->assertStatus(422)->assertJsonValidationErrors('postal_code');
    }

    public function test_a_saved_street_address_without_a_postcode_is_refused_and_one_with_it_is_accepted(): void
    {
        $account = Account::register('postal@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');

        $without = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', 'Testov', '+359888111222', (string) $account->id(), 'BG', 'Sofia', null, 'V 1');
        $with = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', 'Testov', '+359888111222', (string) $account->id(), 'BG', 'Sofia', '1000', 'V 2');
        app(AddressRepository::class)->save($without);
        app(AddressRepository::class)->save($with);

        $response = $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), [], ['address_id' => (string) $without->id()]))
            ->assertStatus(422)->assertJsonValidationErrors('postal_code');

        $this->assertSame(__('storefront.checkout_postal_code_required'), $response->json('errors.postal_code.0'));
        $this->assertSame(0, DB::table('orders')->count());

        $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), [], ['address_id' => (string) $with->id()]))->assertStatus(201);
        $this->assertSame('1000', DB::table('orders')->sole()->postal_code);
    }

    public function test_another_accounts_saved_address_is_still_the_same_404_and_not_a_postcode_hint(): void
    {
        $owner = Account::register('owner@example.com', 'hashed-password');
        $other = Account::register('other@example.com', 'hashed-password');
        app(AccountRepository::class)->save($owner);
        app(AccountRepository::class)->save($other);
        $address = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', 'Testov', '+359888111222', (string) $owner->id(), 'BG', 'Sofia', null, 'V 1');
        app(AddressRepository::class)->save($address);
        $this->actingAs(AccountModel::findOrFail($other->id()), 'customer');

        $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), [], ['address_id' => (string) $address->id()]))
            ->assertStatus(404)->assertJsonMissingValidationErrors('postal_code');
    }
}
