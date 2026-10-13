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
 * Checkout RN-1 (checkout-region-and-note-design.md 2.4, 3): the region of a street address by the merchant's per-country rule — the
 * REQUEST is the truth — and the customer's order note. Everything through POST /api/checkout and the address API.
 */
class RegionNoteCheckoutTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRegionOrders();
    }

    // ---- the 2.4 table, row by row ---------------------------------------------------------------------------------------------

    public function test_optional_stores_a_given_region_trimmed_and_null_when_absent_or_blank(): void
    {
        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']))->assertStatus(201)->assertJsonPath('order.region', 'Sofia-city');
        $this->checkout($this->streetFields('BG'))->assertStatus(201)->assertJsonPath('order.region', null);
        $this->checkout($this->streetFields('BG', ['region' => '']))->assertStatus(201)->assertJsonPath('order.region', null);
        $this->checkout($this->streetFields('BG', ['region' => '   ']))->assertStatus(201)->assertJsonPath('order.region', null);
        $this->checkout($this->streetFields('BG', ['region' => '  Plovdiv  ']))->assertStatus(201)->assertJsonPath('order.region', 'Plovdiv');

        $this->assertSame(['Plovdiv', null, null, null, 'Sofia-city'], DB::table('orders')->orderByDesc('id')->pluck('region')->all());
    }

    public function test_required_refuses_an_absent_or_blank_region_with_a_field_error_and_writes_nothing(): void
    {
        $this->regionRule('optional', ['US' => 'required']);

        foreach ([[], ['region' => ''], ['region' => '   ']] as $extra) {
            $this->checkout($this->streetFields('US', $extra))->assertStatus(422)->assertJsonValidationErrors('region');
        }

        $this->assertSame(0, DB::table('orders')->count());

        $this->checkout($this->streetFields('US', ['region' => 'Ohio']))->assertStatus(201)->assertJsonPath('order.region', 'Ohio');
        // a country the rule does not name keeps the default (optional)
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
    }

    public function test_the_default_can_be_required_for_every_country(): void
    {
        $this->regionRule('required');

        $this->checkout($this->streetFields('BG'))->assertStatus(422)->assertJsonValidationErrors('region');
        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']))->assertStatus(201);
    }

    public function test_hidden_ignores_a_given_region_and_stores_null_and_the_order_is_placed(): void
    {
        $this->regionRule('optional', ['BG' => 'hidden']);

        $this->checkout($this->streetFields('BG', ['region' => 'Sofia-city']))->assertStatus(201)->assertJsonPath('order.region', null);
        $this->checkout($this->streetFields('BG'))->assertStatus(201)->assertJsonPath('order.region', null);
        $this->assertSame([null, null], DB::table('orders')->pluck('region')->all());
        $this->assertSame([null, null], DB::table('order_placement_snapshots')->pluck('region')->all());
        $this->assertSame([null, null], DB::table('addresses')->pluck('region')->all());
    }

    public function test_the_region_is_stored_on_the_order_the_snapshot_and_the_address_and_returned_on_a_replay(): void
    {
        $cart = $this->regionCart();
        $body = $this->regionPayload($cart, $this->streetFields('BG', ['region' => 'Sofia-city']), ['customer_note' => "Leave it at the door.\nThank you!"]);

        $this->postJson('/api/checkout', $body)->assertStatus(201)->assertJsonPath('order.region', 'Sofia-city')->assertJsonPath('order.customer_note', "Leave it at the door.\nThank you!");

        $this->assertSame('Sofia-city', DB::table('orders')->sole()->region);
        $this->assertSame('Sofia-city', DB::table('order_placement_snapshots')->sole()->region);
        $this->assertSame('Sofia-city', DB::table('addresses')->sole()->region);
        $this->assertSame("Leave it at the door.\nThank you!", DB::table('order_placement_snapshots')->sole()->customer_note, 'the snapshot copies the note too');

        $this->postJson('/api/checkout', $body)->assertStatus(201)->assertJsonPath('already_placed', true)
            ->assertJsonPath('order.region', 'Sofia-city')->assertJsonPath('order.customer_note', "Leave it at the door.\nThank you!");
    }

    public function test_a_pickup_point_with_a_region_is_refused_and_one_without_has_none(): void
    {
        $this->checkout($this->pickupFields(['region' => 'Sofia-city']))->assertStatus(422)->assertJsonValidationErrors('region');
        $this->assertSame(0, DB::table('orders')->count());

        $this->checkout($this->pickupFields())->assertStatus(201)->assertJsonPath('order.region', null);
    }

    public function test_a_region_with_a_saved_address_is_refused(): void
    {
        $this->regionCart();
        $body = $this->regionPayload($this->regionCart(), [], ['address_id' => '1', 'region' => 'Sofia-city']);

        $this->postJson('/api/checkout', $body)->assertStatus(422)->assertJsonValidationErrors('address_id');
    }

    public function test_a_saved_address_without_a_region_still_checks_out_under_a_required_country(): void
    {
        $this->regionRule('required');
        $account = Account::register('region@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');
        $address = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', 'Testov', '+359888111222', (string) $account->id(), 'BG', 'Sofia', '1000', 'V 1');
        app(AddressRepository::class)->save($address);

        $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), [], ['address_id' => (string) $address->id()]))
            ->assertStatus(201)->assertJsonPath('order.region', null);
    }

    public function test_a_saved_address_brings_its_own_region(): void
    {
        $account = Account::register('region2@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');
        $address = Address::create(AddressDeliveryType::STREET_ADDRESS, 'Ana', 'Testov', '+359888111222', (string) $account->id(), 'BG', 'Sofia', '1000', 'V 1', null, null, null, null, null, null, null, 'Sofia-city');
        app(AddressRepository::class)->save($address);

        $this->postJson('/api/checkout', $this->regionPayload($this->regionCart(), [], ['address_id' => (string) $address->id()]))
            ->assertStatus(201)->assertJsonPath('order.region', 'Sofia-city');
        $this->assertSame('Sofia-city', DB::table('order_placement_snapshots')->sole()->region);
    }

    // ---- the note ------------------------------------------------------------------------------------------------------------------

    public function test_a_multi_line_note_is_stored_byte_for_byte_including_crlf(): void
    {
        $note = "Line one\r\nLine two\n\nLine «four» <b>&</b> — Кирилица 😀";

        $this->checkout($this->streetFields(), ['customer_note' => $note])->assertStatus(201);

        $this->assertSame($note, DB::table('orders')->sole()->customer_note);
    }

    /** @return array<string, array{string}> */
    public static function refusedNotes(): array
    {
        return [
            'a tab' => ["a\tb"],
            'a NUL' => ["a\x00b"],
            'a vertical tab' => ["a\x0Bb"],
            'a C1 control (U+0085)' => ["a\u{0085}b"],
            'a bidi override (U+202E)' => ["a\u{202E}b"],
            'a bidi isolate (U+2066)' => ["a\u{2066}b"],
            'invalid UTF-8' => ["a\xC3\x28b"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedNotes')]
    public function test_a_note_with_a_refused_character_is_a_field_error(string $note): void
    {
        $body = $this->regionPayload($this->regionCart(), $this->streetFields(), ['customer_note' => $note]);

        // a body with invalid UTF-8 cannot be encoded as JSON by the test client: that one is sent form-encoded
        $response = mb_check_encoding($note, 'UTF-8') ? $this->postJson('/api/checkout', $body) : $this->post('/api/checkout', $body, ['Accept' => 'application/json']);

        $response->assertStatus(422)->assertJsonValidationErrors('customer_note');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_the_length_is_counted_in_characters_not_bytes(): void
    {
        $this->checkout($this->streetFields(), ['customer_note' => str_repeat('я', 1000)])->assertStatus(201);
        $this->assertSame(1000, mb_strlen((string) DB::table('orders')->sole()->customer_note));

        $this->checkout($this->streetFields(), ['customer_note' => str_repeat('я', 1001)])->assertStatus(422)->assertJsonValidationErrors('customer_note');
        $this->checkout($this->streetFields(), ['customer_note' => str_repeat('x', 1001)])->assertStatus(422)->assertJsonValidationErrors('customer_note');
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_a_blank_note_is_no_note(): void
    {
        foreach ([null, '', '   ', "\r\n \n"] as $note) {
            $this->checkout($this->streetFields(), $note === null ? [] : ['customer_note' => $note])->assertStatus(201)->assertJsonPath('order.customer_note', null);
        }

        $this->assertSame([null, null, null, null], DB::table('orders')->pluck('customer_note')->all());
    }

    public function test_a_note_goes_with_a_saved_address_and_with_a_pickup_point(): void
    {
        $this->checkout($this->pickupFields(), ['customer_note' => 'Please call'])->assertStatus(201)->assertJsonPath('order.customer_note', 'Please call');
    }

    // ---- the address API --------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function addressBody(string $country = 'BG', array $extra = []): array
    {
        return array_merge(['delivery_type' => 'street_address', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Testov', 'phone' => '+359888123456', 'country' => $country, 'city' => 'Sofia', 'address_line_1' => 'V 1'], $extra);
    }

    public function test_the_address_api_applies_the_same_rule_and_returns_the_region(): void
    {
        $this->regionRule('optional', ['US' => 'required', 'BG' => 'optional', 'DE' => 'hidden']);

        $this->postJson('/api/addresses', $this->addressBody('BG', ['region' => ' Sofia-city ']))->assertStatus(201)->assertJsonPath('region', 'Sofia-city');
        $this->postJson('/api/addresses', $this->addressBody('BG'))->assertStatus(201)->assertJsonPath('region', null);
        $this->postJson('/api/addresses', $this->addressBody('US'))->assertStatus(422)->assertJsonValidationErrors('region');
        $this->postJson('/api/addresses', $this->addressBody('US', ['region' => 'Ohio']))->assertStatus(201)->assertJsonPath('region', 'Ohio');
        $this->postJson('/api/addresses', $this->addressBody('DE', ['region' => 'Bayern']))->assertStatus(201)->assertJsonPath('region', null);
    }

    public function test_the_address_api_refuses_a_region_on_a_pickup_point_and_a_note_does_not_exist_there(): void
    {
        $pickup = ['delivery_type' => 'pickup_point', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Testov', 'phone' => '+359888123456', 'country' => 'BG', 'carrier_code' => 'econt', 'pickup_point_reference' => 'o-1', 'pickup_point_name' => 'N', 'pickup_point_address' => 'A', 'settlement' => 'Sofia'];

        $this->postJson('/api/addresses', $pickup + ['region' => 'Sofia-city'])->assertStatus(422)->assertJsonValidationErrors('region');
        $this->postJson('/api/addresses', $pickup + ['customer_note' => 'x'])->assertStatus(201)->assertJsonMissingPath('customer_note');
    }

    public function test_the_address_api_update_changes_and_clears_the_region(): void
    {
        $account = Account::register('region3@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');

        $id = $this->postJson('/api/addresses', $this->addressBody('BG', ['region' => 'Sofia-city']))->assertStatus(201)->json('id');
        $this->putJson("/api/addresses/{$id}", $this->addressBody('BG', ['region' => 'Plovdiv']))->assertOk()->assertJsonPath('region', 'Plovdiv');
        $this->putJson("/api/addresses/{$id}", $this->addressBody('BG'))->assertOk()->assertJsonPath('region', null);
    }

    public function test_the_quote_is_not_changed_by_a_region(): void
    {
        $this->freeShippingMethods();
        $this->regionCart();

        // region does not price: the quote ignores it (an unknown field is not read) and its response has no region key
        $json = $this->postJson('/api/shipping/quote', ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia', 'region' => 'Sofia-city'])->assertOk()->json();

        $this->assertArrayNotHasKey('region', $json);
    }
}
