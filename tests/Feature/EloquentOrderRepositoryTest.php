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
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use Illuminate\Database\Events\QueryExecuted;
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
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: 'variation-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(1000, 'EUR'),
            soldAttributes: [],
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

    public function test_has_any_for_account_is_true_after_a_real_order(): void
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

        $this->assertTrue($this->repository()->hasAnyForAccount($accountId));
    }

    public function test_has_any_for_account_is_false_for_an_account_with_no_orders(): void
    {
        $accountId = $this->accountId();

        $this->assertFalse($this->repository()->hasAnyForAccount($accountId));
    }

    /**
     * A guest order (account_id null) is invisible to this check — a
     * customer who ordered as a guest and later registered counts as
     * new, per §8.1's own deliberate no-guest-deduplication decision.
     */
    public function test_has_any_for_account_is_false_when_the_only_order_was_placed_as_a_guest(): void
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);
        $accountId = $this->accountId();

        $guestOrder = Order::create(
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
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        $this->repository()->save($guestOrder);

        $this->assertFalse($this->repository()->hasAnyForAccount($accountId));
    }

    /**
     * order-lifecycle-design.md §7.4/R11's own reversal, tested against a
     * real DB: a CANCELLED order was called off and never became a
     * purchase — it must not count toward new_customers_only.
     */
    public function test_has_any_for_account_is_false_when_the_only_order_is_cancelled(): void
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
            status: OrderStatus::CANCELLED,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        $this->repository()->save($order);

        $this->assertFalse($this->repository()->hasAnyForAccount($accountId));
    }

    /**
     * The other direction of the same rule: a REFUNDED order really was a
     * purchase — it must still count.
     */
    public function test_has_any_for_account_is_true_when_the_only_order_is_refunded(): void
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
            status: OrderStatus::REFUNDED,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        $this->repository()->save($order);

        $this->assertTrue($this->repository()->hasAnyForAccount($accountId));
    }

    /**
     * A saved street-address order — the fixture the four tests below share,
     * with the status as the one thing a caller varies.
     */
    private function savedOrder(OrderStatus $status = OrderStatus::PLACED): Order
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);

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
            status: $status,
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: 'Vitosha Blvd 1',
        );

        $this->repository()->save($order);

        return $order;
    }

    /**
     * Every accessor's answer, as scalar values, so two reconstitutions can
     * be compared field by field (status included).
     *
     * @return array<string, mixed>
     */
    private function allFields(Order $order): array
    {
        return [
            'id' => $order->id(),
            'clientId' => $order->clientId(),
            'accountId' => $order->accountId(),
            'transactionId' => $order->transactionId(),
            'email' => $order->email(),
            'currency' => $order->currency()->code(),
            'subtotal' => $order->subtotal()->minorValue(),
            'discount' => $order->discount()->minorValue(),
            'total' => $order->total()->minorValue(),
            'appliedPromotionCode' => $order->appliedPromotionCode(),
            'status' => $order->status()->value,
            'placedAt' => $order->placedAt()->format('Y-m-d H:i:s'),
            'addressId' => $order->addressId(),
            'deliveryType' => $order->deliveryType()->value,
            'recipientName' => $order->recipientName(),
            'phone' => $order->phone(),
            'country' => $order->country(),
            'city' => $order->city(),
            'postalCode' => $order->postalCode(),
            'addressLine1' => $order->addressLine1(),
            'addressLine2' => $order->addressLine2(),
            'carrierCode' => $order->carrierCode(),
            'pickupPointReference' => $order->pickupPointReference(),
            'settlement' => $order->settlement(),
        ];
    }

    public function test_find_by_id_for_update_returns_the_order_find_by_id_returns(): void
    {
        $order = $this->savedOrder();

        $plain = $this->repository()->findById($order->id());
        $locked = DB::transaction(fn (): ?Order => $this->repository()->findByIdForUpdate($order->id()));

        $this->assertNotNull($plain);
        $this->assertNotNull($locked);
        $this->assertNotSame($plain, $locked, 'The locked read reconstitutes its own Order rather than reusing one.');
        $this->assertSame($this->allFields($plain), $this->allFields($locked));
    }

    public function test_find_by_id_for_update_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull(
            DB::transaction(fn (): ?Order => $this->repository()->findByIdForUpdate('999999'))
        );
    }

    /**
     * The lock is in the STATEMENT, not in a comment: the read is one select
     * of the orders row by primary key, ending in `for update`, while the
     * unlocked findById() in the same window is the same select without it.
     */
    public function test_find_by_id_for_update_reads_the_orders_row_with_for_update(): void
    {
        $order = $this->savedOrder();

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        DB::transaction(function () use ($order): void {
            $this->repository()->findByIdForUpdate($order->id());
        });
        DB::transaction(function () use ($order): void {
            $this->repository()->findById($order->id());
        });

        $orderReads = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from `orders`'),
        ));

        $this->assertCount(2, $orderReads, 'One locked read and one plain read of orders — nothing else.');

        [$locked, $plain] = $orderReads;

        $this->assertStringContainsString('select * from `orders` where `orders`.`id` = ?', $locked);
        $this->assertStringEndsWith('for update', strtolower($locked));
        $this->assertStringNotContainsString('for update', strtolower($plain));
    }

    /**
     * The round trip the lock exists for: read under the lock, move the
     * status, save — and findById() then reads the new status back while
     * every other column of the row is byte-identical to what it was.
     */
    public function test_a_locked_read_then_a_transition_then_save_persists_only_the_status(): void
    {
        $order = $this->savedOrder();
        $before = (array) DB::table('orders')->where('id', $order->id())->first();

        DB::transaction(function () use ($order): void {
            $locked = $this->repository()->findByIdForUpdate($order->id());
            $this->assertNotNull($locked);

            $locked->confirm();
            $this->repository()->save($locked);
        });

        $reloaded = $this->repository()->findById($order->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(OrderStatus::CONFIRMED, $reloaded->status());

        $after = (array) DB::table('orders')->where('id', $order->id())->first();

        $this->assertSame('placed', $before['status']);
        $this->assertSame('confirmed', $after['status']);

        $changed = array_values(array_diff(array_keys(array_diff_assoc($after, $before)), ['updated_at']));

        $this->assertSame(['status'], $changed, 'A transition may not rewrite any other column.');
    }
}
