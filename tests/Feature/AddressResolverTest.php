<?php

namespace Tests\Feature;

use App\Services\AddressResolver;
use App\Services\Exceptions\AddressNotFoundForCheckoutException;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Address\Persistence\Eloquent\AddressModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\AddressResolver — resolves the Address for a checkout,
 * per checkout-domain-design.md §8.4. Real MySQL Feature test, not a
 * mocked Unit test.
 */
class AddressResolverTest extends TestCase
{
    use RefreshDatabase;

    private function accountId(string $email): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    public function test_resolve_new_for_a_guest_saves_a_real_address_row_with_a_genuinely_null_account_id(): void
    {
        $address = app(AddressResolver::class)->resolveNew(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: null,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        $this->assertNotNull($address->id());
        $this->assertNull($address->accountId());

        $model = AddressModel::findOrFail($address->id());
        $this->assertNull($model->account_id);
    }

    public function test_resolve_new_for_a_logged_in_account_saves_a_real_address_row_with_the_given_account_id(): void
    {
        $accountId = $this->accountId('buyer@example.com');

        $address = app(AddressResolver::class)->resolveNew(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: $accountId,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        $this->assertSame($accountId, $address->accountId());

        $model = AddressModel::findOrFail($address->id());
        $this->assertSame($accountId, (string) $model->account_id);
    }

    public function test_resolve_new_with_pickup_point_fields_round_trips_correctly(): void
    {
        $address = app(AddressResolver::class)->resolveNew(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientName: 'Maria Petrova',
            phone: '+359888654321',
            accountId: null,
            carrierCode: 'econt',
            pickupPointReference: 'office-1234',
            settlement: 'Plovdiv',
        );

        $reloaded = app(AddressRepository::class)->findById($address->id());
        $this->assertSame(AddressDeliveryType::PICKUP_POINT, $reloaded->deliveryType());
        $this->assertSame('econt', $reloaded->carrierCode());
        $this->assertSame('office-1234', $reloaded->pickupPointReference());
        $this->assertSame('Plovdiv', $reloaded->settlement());
    }

    public function test_resolve_existing_with_a_valid_address_id_and_matching_account_id_returns_the_real_address(): void
    {
        $accountId = $this->accountId('buyer@example.com');
        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: $accountId,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(AddressRepository::class)->save($address);

        $resolved = app(AddressResolver::class)->resolveExisting($address->id(), $accountId);

        $this->assertSame($address->id(), $resolved->id());
    }

    public function test_resolve_existing_with_a_nonexistent_address_id_throws(): void
    {
        $accountId = $this->accountId('buyer@example.com');

        $this->expectException(AddressNotFoundForCheckoutException::class);

        app(AddressResolver::class)->resolveExisting('999999', $accountId);
    }

    /**
     * The whole point of 404-not-403: a missing address and one owned
     * by someone else must be genuinely indistinguishable to the caller
     * — same exception class, same message, in both cases.
     */
    public function test_resolve_existing_with_an_address_owned_by_a_different_account_throws_the_identical_exception_as_a_missing_one(): void
    {
        $ownerAccountId = $this->accountId('owner@example.com');
        $attackerAccountId = $this->accountId('attacker@example.com');

        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: $ownerAccountId,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(AddressRepository::class)->save($address);

        $missingMessage = null;
        try {
            app(AddressResolver::class)->resolveExisting('999999', $attackerAccountId);
        } catch (AddressNotFoundForCheckoutException $e) {
            $missingMessage = $e->getMessage();
        }

        $wrongOwnerMessage = null;
        try {
            app(AddressResolver::class)->resolveExisting($address->id(), $attackerAccountId);
        } catch (AddressNotFoundForCheckoutException $e) {
            $wrongOwnerMessage = $e->getMessage();
        }

        $this->assertNotNull($missingMessage);
        $this->assertNotNull($wrongOwnerMessage);
        $this->assertSame("No address \"{$address->id()}\" found.", $wrongOwnerMessage);
        $this->assertSame('No address "999999" found.', $missingMessage);

        // Both messages share the exact same template, differing only
        // in the (expected, non-secret) address id — proving the two
        // cases are genuinely indistinguishable in shape, not just
        // individually correct.
        $this->assertSame(
            preg_replace('/"[^"]+"/', '"X"', $missingMessage),
            preg_replace('/"[^"]+"/', '"X"', $wrongOwnerMessage),
        );
    }
}
