<?php

namespace Tests\Feature;

use App\Services\ClientClaimedPickupKindSource;
use App\Services\PickupKindSource;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Persistence\Eloquent\AddressModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shipping stage 5g-2a: the address API takes the pickup kind through the one seam, and refuses it where it cannot be. */
class PickupKindAddressApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function pickup(array $overrides = []): array
    {
        return array_merge([
            'delivery_type' => 'pickup_point', 'recipient_name' => 'Ivan', 'phone' => '+359888123456', 'country' => 'BG', 'carrier_code' => 'econt',
            'pickup_point_reference' => 'office-1', 'pickup_point_name' => 'Center', 'pickup_point_address' => 'Vitosha 100', 'settlement' => 'Sofia',
        ], $overrides);
    }

    public function test_the_seam_is_bound_to_the_client_claimed_source(): void
    {
        $this->assertInstanceOf(ClientClaimedPickupKindSource::class, app(PickupKindSource::class));
    }

    public function test_a_pickup_address_stores_and_returns_the_kind_and_none_when_absent(): void
    {
        $locker = $this->postJson('/api/addresses', $this->pickup(['pickup_point_kind' => 'locker']))->assertStatus(201)->assertJsonPath('pickup_point_kind', 'locker');
        $this->assertSame('locker', AddressModel::findOrFail($locker->json('id'))->pickup_point_kind);

        $none = $this->postJson('/api/addresses', $this->pickup())->assertStatus(201)->assertJsonPath('pickup_point_kind', null);
        $this->assertNull(AddressModel::findOrFail($none->json('id'))->pickup_point_kind);
    }

    public function test_a_kind_is_refused_for_a_street_address_and_for_other_values(): void
    {
        $street = ['delivery_type' => 'street_address', 'recipient_name' => 'Ivan', 'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'V 1'];

        $this->postJson('/api/addresses', $street + ['pickup_point_kind' => 'office'])->assertStatus(422)->assertJsonValidationErrors('pickup_point_kind');

        foreach (['other', 'address', 'nowhere', ['office']] as $bad) {
            $this->postJson('/api/addresses', $this->pickup(['pickup_point_kind' => $bad]))->assertStatus(422)->assertJsonValidationErrors('pickup_point_kind');
        }

        $this->assertSame(0, AddressModel::count());
    }

    public function test_an_update_changes_and_clears_the_kind_and_refuses_a_bad_one(): void
    {
        $account = Account::register('kind-api@example.com', 'hashed-password');
        app(AccountRepository::class)->save($account);
        $this->actingAs(AccountModel::findOrFail($account->id()), 'customer');

        $id = $this->postJson('/api/addresses', $this->pickup(['pickup_point_kind' => 'office']))->assertStatus(201)->json('id');

        $this->putJson("/api/addresses/{$id}", $this->pickup(['pickup_point_kind' => 'locker']))->assertOk()->assertJsonPath('pickup_point_kind', 'locker');
        $this->putJson("/api/addresses/{$id}", $this->pickup())->assertOk()->assertJsonPath('pickup_point_kind', null);
        $this->putJson("/api/addresses/{$id}", $this->pickup(['pickup_point_kind' => 'other']))->assertStatus(422);
        $this->assertNull(AddressModel::findOrFail($id)->pickup_point_kind);
    }
}
