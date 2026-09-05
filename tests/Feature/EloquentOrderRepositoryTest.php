<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentOrderRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): OrderRepository
    {
        return app(OrderRepository::class);
    }

    private function accountId(string $email = 'buyer@example.com'): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    private function clientId(string $name = 'Ivan Ivanov'): string
    {
        $client = new Client(null, $name);
        app(ClientRepository::class)->save($client);

        return $client->id();
    }

    private function transactionId(string $clientId): string
    {
        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(new SaleLine(
            id: null,
            transactionId: '',
            clientId: $clientId,
            priceableId: 'variation-1',
            type: SaleLineType::SALE,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
        ));

        app(TransactionRepository::class)->save($transaction);

        return $transaction->id();
    }

    private function addressId(?string $accountId = null): string
    {
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

        return $address->id();
    }

    private function placedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function test_save_then_find_by_id_round_trips_a_street_address_order(): void
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);
        $accountId = $this->accountId();
        $addressId = $this->addressId($accountId);

        $order = Order::create(
            clientId: $clientId,
            transactionId: $transactionId,
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(300, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: $this->placedAt(),
            accountId: $accountId,
            appliedPromotionCode: 'summer20',
            addressId: $addressId,
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
            addressLine2: 'Floor 2',
        );

        $this->repository()->save($order);
        $this->assertNotNull($order->id());

        $reloaded = $this->repository()->findById($order->id());
        $this->assertNotNull($reloaded);
        $this->assertSame($clientId, $reloaded->clientId());
        $this->assertSame($transactionId, $reloaded->transactionId());
        $this->assertSame($accountId, $reloaded->accountId());
        $this->assertSame('buyer@example.com', $reloaded->email());
        $this->assertSame('EUR', $reloaded->currency()->code());
        $this->assertSame(1000, $reloaded->subtotal()->minorValue());
        $this->assertSame(300, $reloaded->discount()->minorValue());
        // Genuinely round-tripped from the DB, not held over from before save().
        $this->assertSame(700, $reloaded->total()->minorValue());
        $this->assertSame('summer20', $reloaded->appliedPromotionCode());
        $this->assertSame(OrderStatus::PLACED, $reloaded->status());
        $this->assertInstanceOf(DateTimeImmutable::class, $reloaded->placedAt());
        $this->assertSame('2026-01-01 12:00:00', $reloaded->placedAt()->format('Y-m-d H:i:s'));
        $this->assertSame($addressId, $reloaded->addressId());
        $this->assertSame(OrderDeliveryType::STREET_ADDRESS, $reloaded->deliveryType());
        $this->assertSame('Ivan Ivanov', $reloaded->recipientName());
        $this->assertSame('+359888123456', $reloaded->phone());
        $this->assertSame('BG', $reloaded->country());
        $this->assertSame('Sofia', $reloaded->city());
        $this->assertSame('1000', $reloaded->postalCode());
        $this->assertSame('Vitosha Blvd 1', $reloaded->addressLine1());
        $this->assertSame('Floor 2', $reloaded->addressLine2());
        $this->assertNull($reloaded->carrierCode());
    }

    public function test_save_then_find_by_id_round_trips_a_pickup_point_order(): void
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);

        $order = Order::create(
            clientId: $clientId,
            transactionId: $transactionId,
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::PICKUP_POINT,
            recipientName: 'Maria Petrova',
            phone: '+359888654321',
            placedAt: $this->placedAt(),
            carrierCode: 'econt',
            pickupPointReference: 'office-1234',
            settlement: 'Plovdiv',
        );

        $this->repository()->save($order);

        $reloaded = $this->repository()->findById($order->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(OrderDeliveryType::PICKUP_POINT, $reloaded->deliveryType());
        $this->assertSame('econt', $reloaded->carrierCode());
        $this->assertSame('office-1234', $reloaded->pickupPointReference());
        $this->assertSame('Plovdiv', $reloaded->settlement());
        $this->assertNull($reloaded->country());
        $this->assertNull($reloaded->addressLine1());
        $this->assertSame(1000, $reloaded->total()->minorValue());
    }

    public function test_a_guest_order_round_trips_with_genuinely_null_account_and_address_columns(): void
    {
        $clientId = $this->clientId('Guest Buyer');
        $transactionId = $this->transactionId($clientId);

        $order = Order::create(
            clientId: $clientId,
            transactionId: $transactionId,
            email: 'guest@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(500, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Guest Buyer',
            phone: '+359888999999',
            placedAt: $this->placedAt(),
            accountId: null,
            addressId: null,
            country: 'BG',
            city: 'Burgas',
            addressLine1: 'Sea Blvd 1',
        );

        $this->repository()->save($order);

        $reloaded = $this->repository()->findById($order->id());
        $this->assertNull($reloaded->accountId());
        $this->assertNull($reloaded->addressId());

        // Confirm genuinely NULL in the database, not just null in the PHP object.
        $row = DB::table('orders')->where('id', $order->id())->first();
        $this->assertNull($row->account_id);
        $this->assertNull($row->address_id);
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_the_real_orders_table_foreign_key_delete_rules(): void
    {
        // Confirms the actual FK ON DELETE clauses this repository's
        // guarantees depend on — not just trusting the migration file
        // (CLAUDE.md rule 2/project convention).
        $createTable = DB::select('SHOW CREATE TABLE orders')[0]->{'Create Table'};

        $this->assertStringContainsString(
            'CONSTRAINT `ord_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `operational_sales_clients` (`id`) ON DELETE RESTRICT',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `ord_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `operational_sales_transactions` (`id`) ON DELETE RESTRICT',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `ord_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `ord_address_id_foreign` FOREIGN KEY (`address_id`) REFERENCES `addresses` (`id`) ON DELETE SET NULL',
            $createTable
        );
    }

    public function test_deleting_the_backing_client_is_rejected_by_the_database(): void
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);

        $order = Order::create(
            clientId: $clientId,
            transactionId: $transactionId,
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(500, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: $this->placedAt(),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        $this->repository()->save($order);

        $this->expectException(QueryException::class);

        ClientModel::where('id', $clientId)->delete();
    }

    public function test_deleting_the_backing_account_nulls_the_orders_account_id_but_leaves_the_order_intact(): void
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);
        $accountId = $this->accountId();

        $order = Order::create(
            clientId: $clientId,
            transactionId: $transactionId,
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(500, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: $this->placedAt(),
            accountId: $accountId,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        $this->repository()->save($order);
        $orderId = $order->id();

        AccountModel::where('id', $accountId)->forceDelete();

        $reloaded = $this->repository()->findById($orderId);
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->accountId());
        // Everything else survives untouched.
        $this->assertSame('buyer@example.com', $reloaded->email());
        $this->assertSame(500, $reloaded->subtotal()->minorValue());
        $this->assertSame($clientId, $reloaded->clientId());
    }
}
