<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderPlacementSnapshotModel;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * order-editing-design.md §2.1/§2.2, stage 1 (schema only) — proves the two
 * new migrations for real against a real DB, not just "the migration file
 * looks right" (CLAUDE.md's own standing rule). Fixture helpers mirror
 * EloquentOrderRepositoryTest's own established shapes.
 */
class OrderPlacementSnapshotSchemaTest extends TestCase
{
    use RefreshDatabase;

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

    private function orderId(): string
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);

        $order = Order::create(
            clientId: $clientId,
            transactionId: $transactionId,
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::zero('EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-09-29 10:00:00'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order->id();
    }

    private function snapshotRow(string $orderId): array
    {
        return [
            'order_id' => $orderId,
            'subtotal_minor' => 1000,
            'subtotal_currency' => 'EUR',
            'discount_minor' => 0,
            'discount_currency' => 'EUR',
            'total_minor' => 1000,
            'total_currency' => 'EUR',
            'applied_promotion_code' => null,
            'delivery_type' => 'street_address',
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'country' => 'BG',
            'city' => 'Sofia',
            'postal_code' => null,
            'address_line_1' => 'Vitosha Blvd 1',
            'address_line_2' => null,
            'carrier_code' => null,
            'pickup_point_reference' => null,
            'settlement' => null,
            'created_at' => new DateTimeImmutable('2026-09-29 10:00:00'),
        ];
    }

    // --- order_placement_snapshots ---------------------------------------

    public function test_a_second_snapshot_for_the_same_order_id_is_refused_by_the_unique_index(): void
    {
        $orderId = $this->orderId();
        OrderPlacementSnapshotModel::create($this->snapshotRow($orderId));

        try {
            OrderPlacementSnapshotModel::create($this->snapshotRow($orderId));
            $this->fail('A second order_placement_snapshots row for the same order_id must be refused.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode(), 'must be a real unique-constraint violation (SQLSTATE 23000).');
        }
    }

    public function test_deleting_the_backing_order_is_rejected_by_the_database(): void
    {
        $orderId = $this->orderId();
        OrderPlacementSnapshotModel::create($this->snapshotRow($orderId));

        $this->expectException(QueryException::class);

        DB::table('orders')->where('id', $orderId)->delete();
    }

    public function test_the_real_table_shape(): void
    {
        $createTable = DB::select('SHOW CREATE TABLE order_placement_snapshots')[0]->{'Create Table'};

        $this->assertStringContainsString(
            'UNIQUE KEY `order_placement_snapshots_order_id_unique` (`order_id`)',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `ops_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT',
            $createTable
        );

        foreach ([
            '`subtotal_minor` bigint unsigned NOT NULL',
            '`subtotal_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL',
            '`discount_minor` bigint unsigned NOT NULL',
            '`discount_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL',
            '`total_minor` bigint unsigned NOT NULL',
            '`total_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL',
            '`applied_promotion_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`delivery_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
            '`recipient_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
            '`phone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL',
            '`country` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`city` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`postal_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`address_line_1` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`address_line_2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`carrier_code` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`pickup_point_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`settlement` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL',
            '`created_at` timestamp NOT NULL',
        ] as $expectedColumn) {
            $this->assertStringContainsString($expectedColumn, $createTable, "column definition missing/wrong: {$expectedColumn}");
        }

        $this->assertStringNotContainsString('`updated_at`', $createTable, 'this row is never updated — no updated_at column (§2.1).');
    }

    public function test_every_field_persists_and_reads_back_exactly(): void
    {
        $orderId = $this->orderId();
        $row = $this->snapshotRow($orderId);
        $row['applied_promotion_code'] = 'DEMO10';
        $row['postal_code'] = '1000';
        $row['address_line_2'] = 'Floor 2';
        $row['carrier_code'] = 'econt';
        $row['pickup_point_reference'] = 'EO-123';
        $row['settlement'] = 'Sofia district';

        OrderPlacementSnapshotModel::create($row);

        $reread = DB::table('order_placement_snapshots')->where('order_id', $orderId)->first();

        foreach ($row as $column => $value) {
            if ($value instanceof DateTimeImmutable) {
                continue;
            }

            // order_id comes back as an int from a raw DB read (MySQL's own
            // driver typing), everything else as the string it was written
            // as — compare loosely only for that one integer-typed column.
            if ($column === 'order_id') {
                $this->assertEquals($value, $reread->$column, "column {$column} did not round-trip.");

                continue;
            }

            $this->assertSame($value, $reread->$column, "column {$column} did not round-trip exactly.");
        }
    }

    // --- orders.edit_revision / orders.tracking_number -------------------

    public function test_edit_revision_defaults_to_zero_and_tracking_number_defaults_to_null_for_a_fresh_order(): void
    {
        $orderId = $this->orderId();

        $row = DB::table('orders')->where('id', $orderId)->first();

        $this->assertSame(0, $row->edit_revision);
        $this->assertNull($row->tracking_number);
    }

    /**
     * Simulates a row written before this stage's migration existed (a raw
     * insert bypassing the domain layer entirely, mirroring how a
     * pre-existing dev-database row would look once the migration runs
     * against it): the new columns must still default exactly the same way
     * for a row Eloquent/the domain layer never touched directly.
     */
    public function test_edit_revision_and_tracking_number_default_correctly_for_a_pre_existing_style_row_too(): void
    {
        $clientId = $this->clientId();
        $transactionId = $this->transactionId($clientId);

        $orderId = DB::table('orders')->insertGetId([
            'client_id' => $clientId,
            'transaction_id' => $transactionId,
            'email' => 'legacy@example.com',
            'currency' => 'EUR',
            'subtotal_minor' => 1000,
            'discount_minor' => 0,
            'total_minor' => 1000,
            'status' => 'placed',
            'placed_at' => new DateTimeImmutable('2026-01-01'),
            'delivery_type' => 'street_address',
            'recipient_name' => 'Legacy Buyer',
            'phone' => '+359888000000',
            'created_at' => new DateTimeImmutable('2026-01-01'),
            'updated_at' => new DateTimeImmutable('2026-01-01'),
        ]);

        $row = DB::table('orders')->where('id', $orderId)->first();

        $this->assertSame(0, $row->edit_revision);
        $this->assertNull($row->tracking_number);
    }
}
