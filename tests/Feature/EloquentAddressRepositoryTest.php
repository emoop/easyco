<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentAddressRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): AddressRepository
    {
        return app(AddressRepository::class);
    }

    private function accountId(string $email): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    public function test_save_then_find_by_id_round_trips_a_street_address(): void
    {
        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: null,
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: 'Floor 2',
        );

        $this->repository()->save($address);

        $this->assertNotNull($address->id());

        $reloaded = $this->repository()->findById($address->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(AddressDeliveryType::STREET_ADDRESS, $reloaded->deliveryType());
        $this->assertNull($reloaded->accountId());
        $this->assertSame('Ivan Ivanov', $reloaded->recipientName());
        $this->assertSame('+359888123456', $reloaded->phone());
        $this->assertSame('BG', $reloaded->country());
        $this->assertSame('Sofia', $reloaded->city());
        $this->assertSame('1000', $reloaded->postalCode());
        $this->assertSame('Vitosha Blvd 1', $reloaded->addressLine1());
        $this->assertSame('Floor 2', $reloaded->addressLine2());
    }

    public function test_save_then_find_by_id_round_trips_a_pickup_point(): void
    {
        $address = Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientName: 'Maria Petrova',
            phone: '+359888654321',
            carrierCode: 'econt',
            pickupPointReference: 'office-1234',
            settlement: 'Plovdiv',
        );

        $this->repository()->save($address);

        $reloaded = $this->repository()->findById($address->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(AddressDeliveryType::PICKUP_POINT, $reloaded->deliveryType());
        $this->assertSame('econt', $reloaded->carrierCode());
        $this->assertSame('office-1234', $reloaded->pickupPointReference());
        $this->assertSame('Plovdiv', $reloaded->settlement());
        $this->assertNull($reloaded->country());
        $this->assertNull($reloaded->addressLine1());
    }

    public function test_find_by_account_id_returns_every_address_saved_for_that_account(): void
    {
        $accountId = $this->accountId('customer@example.com');
        $otherAccountId = $this->accountId('other@example.com');

        $streetAddress = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: $accountId,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        $pickupPoint = Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            accountId: $accountId,
            carrierCode: 'speedy',
            pickupPointReference: 'office-5678',
            settlement: 'Sofia',
        );
        $othersAddress = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Someone Else',
            phone: '+359888000000',
            accountId: $otherAccountId,
            country: 'BG',
            city: 'Varna',
            addressLine1: 'Main St 1',
        );

        $this->repository()->save($streetAddress);
        $this->repository()->save($pickupPoint);
        $this->repository()->save($othersAddress);

        $results = $this->repository()->findByAccountId($accountId);

        $this->assertCount(2, $results);
        $ids = array_map(fn (Address $address) => $address->id(), $results);
        $this->assertContains($streetAddress->id(), $ids);
        $this->assertContains($pickupPoint->id(), $ids);
        $this->assertNotContains($othersAddress->id(), $ids);
    }

    public function test_a_guest_address_round_trips_via_find_by_id_but_is_excluded_from_find_by_account_id(): void
    {
        $accountId = $this->accountId('customer@example.com');

        $guestAddress = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientName: 'Guest Buyer',
            phone: '+359888999999',
            accountId: null,
            country: 'BG',
            city: 'Burgas',
            addressLine1: 'Sea Blvd 1',
        );

        $this->repository()->save($guestAddress);

        $reloaded = $this->repository()->findById($guestAddress->id());
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->accountId());

        $accountsAddresses = $this->repository()->findByAccountId($accountId);
        $this->assertSame([], $accountsAddresses);
    }
}
