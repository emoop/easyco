<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Address\Persistence\Eloquent\AddressModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressControllerTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);
        $model = AccountModel::findOrFail($account->id());

        $this->actingAs($model, 'customer');

        return $model;
    }

    private function streetAddressPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'country' => 'BG',
            'city' => 'Sofia',
            'postal_code' => '1000',
            'address_line_1' => 'Vitosha Blvd 1',
        ], $overrides);
    }

    private function pickupPointPayload(array $overrides = []): array
    {
        return array_merge([
            'delivery_type' => 'pickup_point',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'carrier_code' => 'econt',
            'pickup_point_reference' => 'office-1234',
            'settlement' => 'Sofia',
        ], $overrides);
    }

    // --- store() -------------------------------------------------------------

    public function test_a_guest_can_create_an_address_with_a_null_account_id(): void
    {
        $response = $this->postJson('/api/addresses', $this->streetAddressPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('account_id', null);

        $model = AddressModel::findOrFail($response->json('id'));
        $this->assertNull($model->account_id);
    }

    public function test_a_logged_in_customer_creating_an_address_auto_associates_their_account_id(): void
    {
        $account = $this->loggedInAccount();

        $response = $this->postJson('/api/addresses', $this->streetAddressPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('account_id', (string) $account->id);

        $model = AddressModel::findOrFail($response->json('id'));
        $this->assertSame($account->id, $model->account_id);
    }

    public function test_missing_recipient_name_returns_422(): void
    {
        $payload = $this->streetAddressPayload();
        unset($payload['recipient_name']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['recipient_name']);
    }

    public function test_missing_phone_returns_422(): void
    {
        $payload = $this->streetAddressPayload();
        unset($payload['phone']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['phone']);
    }

    public function test_street_address_payload_including_carrier_code_returns_422(): void
    {
        $payload = $this->streetAddressPayload(['carrier_code' => 'econt']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['carrier_code']);
    }

    public function test_pickup_point_payload_missing_settlement_returns_422(): void
    {
        $payload = $this->pickupPointPayload();
        unset($payload['settlement']);

        $response = $this->postJson('/api/addresses', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['settlement']);
    }

    // --- index() ---------------------------------------------------------------

    public function test_index_without_authentication_returns_401(): void
    {
        $response = $this->getJson('/api/addresses');

        $response->assertStatus(401);
    }

    public function test_index_returns_only_the_logged_in_customers_own_addresses(): void
    {
        $account = $this->loggedInAccount('mine@example.com');
        $this->postJson('/api/addresses', $this->streetAddressPayload())->assertStatus(201);
        $this->postJson('/api/addresses', $this->pickupPointPayload())->assertStatus(201);

        // Another account's address must not leak into this listing.
        $otherAccount = Account::register('other@example.com', 'hashed-password');
        app(AccountRepository::class)->save($otherAccount);
        $otherAddress = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Someone Else',
            phone: '+359888000000',
            accountId: $otherAccount->id(),
            country: 'BG',
            city: 'Varna',
            addressLine1: 'Main St 1',
        );
        app(AddressRepository::class)->save($otherAddress);

        $this->actingAs(AccountModel::findOrFail($account->id), 'customer');
        $response = $this->getJson('/api/addresses');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $ids = array_column($response->json('data'), 'id');
        $this->assertNotContains($otherAddress->id(), $ids);
    }

    // --- update() --------------------------------------------------------------

    public function test_update_happy_path_changes_fields_and_a_follow_up_index_reflects_it(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->streetAddressPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->streetAddressPayload([
            'recipient_name' => 'Petar Petrov',
            'city' => 'Plovdiv',
        ]));

        $response->assertStatus(200);
        $response->assertJsonPath('id', $created['id']);
        $response->assertJsonPath('recipient_name', 'Petar Petrov');
        $response->assertJsonPath('city', 'Plovdiv');

        $index = $this->getJson('/api/addresses');
        $index->assertJsonPath('data.0.id', $created['id']);
        $index->assertJsonPath('data.0.recipient_name', 'Petar Petrov');
        $index->assertJsonPath('data.0.city', 'Plovdiv');
    }

    public function test_updating_another_accounts_address_returns_404_and_changes_nothing(): void
    {
        $owner = Account::register('owner@example.com', 'hashed-password');
        app(AccountRepository::class)->save($owner);
        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Original Name',
            phone: '+359888111111',
            accountId: $owner->id(),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Original St 1',
        );
        app(AddressRepository::class)->save($address);

        $this->loggedInAccount('attacker@example.com');

        $response = $this->putJson("/api/addresses/{$address->id()}", $this->streetAddressPayload([
            'recipient_name' => 'Hijacked Name',
        ]));

        $response->assertStatus(404);

        $reloaded = app(AddressRepository::class)->findById($address->id());
        $this->assertSame('Original Name', $reloaded->recipientName());
    }

    public function test_updating_a_nonexistent_address_returns_404(): void
    {
        $this->loggedInAccount();

        $response = $this->putJson('/api/addresses/999999', $this->streetAddressPayload());

        $response->assertStatus(404);
    }

    public function test_update_violating_exclusivity_returns_422_and_leaves_the_stored_address_unchanged(): void
    {
        $this->loggedInAccount();
        $created = $this->postJson('/api/addresses', $this->streetAddressPayload())->json();

        $response = $this->putJson("/api/addresses/{$created['id']}", $this->streetAddressPayload([
            'carrier_code' => 'econt',
        ]));

        $response->assertStatus(422);

        $reloaded = app(AddressRepository::class)->findById((string) $created['id']);
        $this->assertSame('Ivan Ivanov', $reloaded->recipientName());
        $this->assertSame('Sofia', $reloaded->city());
        $this->assertNull($reloaded->carrierCode());
    }
}
