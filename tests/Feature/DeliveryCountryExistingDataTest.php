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
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Shipping stage 3.0b, owner decision D1: existing data. The two migrations are
 * GATES (they refuse, naming rows, while a stored country is malformed) and the
 * artisan command is the ONLY way historical pickup-point rows without a
 * country are filled — deliberately, by an operator, never automatically.
 */
class DeliveryCountryExistingDataTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS_GATE = __DIR__.'/../../packages/EasyCo/Address/database/migrations/2026_10_04_000001_require_alpha2_country_on_addresses.php';

    private const ORDER_GATE = __DIR__.'/../../packages/EasyCo/Order/database/migrations/2026_10_04_000002_require_alpha2_country_on_orders.php';

    private function insertAddress(string $deliveryType, ?string $country): int
    {
        return DB::table('addresses')->insertGetId([
            'delivery_type' => $deliveryType,
            'recipient_name' => 'Ivan Ivanov',
            'phone' => '+359888123456',
            'country' => $country,
            'city' => $deliveryType === 'street_address' ? 'Sofia' : null,
            'address_line_1' => $deliveryType === 'street_address' ? 'Vitosha Blvd 1' : null,
            'carrier_code' => $deliveryType === 'pickup_point' ? 'econt' : null,
            'pickup_point_reference' => $deliveryType === 'pickup_point' ? 'office-1' : null,
            'settlement' => $deliveryType === 'pickup_point' ? 'Varna' : null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    /** An order saved through the domain, then its country forced to whatever the test needs. */
    private function insertOrder(OrderDeliveryType $type, ?string $country): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $client->id(),
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

        $street = $type === OrderDeliveryType::STREET_ADDRESS;
        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::zero('EUR'),
            deliveryType: $type,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01 12:00:00'),
            country: 'BG',
            city: $street ? 'Sofia' : null,
            addressLine1: $street ? 'Vitosha Blvd 1' : null,
            carrierCode: $street ? null : 'econt',
            pickupPointReference: $street ? null : 'office-1',
            settlement: $street ? null : 'Varna',
        );
        app(OrderRepository::class)->save($order);

        DB::table('orders')->where('id', $order->id())->update(['country' => $country]);

        return (string) $order->id();
    }

    private function country(string $table, int|string $id): ?string
    {
        return DB::table($table)->where('id', $id)->value('country');
    }

    private function runGate(string $file): void
    {
        (require $file)->up();
    }

    // --- the migrations are gates, not guesses -------------------------------------------------

    public function test_the_migration_gate_passes_when_every_country_is_a_two_letter_uppercase_code_or_null(): void
    {
        $this->insertAddress('street_address', 'BG');
        $this->insertAddress('pickup_point', null);
        $this->insertOrder(OrderDeliveryType::STREET_ADDRESS, 'GR');
        $this->insertOrder(OrderDeliveryType::PICKUP_POINT, null);

        $this->runGate(self::ADDRESS_GATE);
        $this->runGate(self::ORDER_GATE);

        $this->assertTrue(true, 'neither gate threw');
    }

    public function test_the_address_gate_aborts_on_a_malformed_stored_country_and_names_the_row(): void
    {
        $this->insertAddress('street_address', 'BG');
        $bad = $this->insertAddress('street_address', 'bg');
        $worse = $this->insertAddress('pickup_point', 'BGR');

        try {
            $this->runGate(self::ADDRESS_GATE);
            $this->fail('the gate must refuse a malformed stored country.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('addresses.country', $e->getMessage());
            $this->assertStringContainsString("{$bad} => \"bg\"", $e->getMessage(), 'a lowercase code is caught although MySQL compares case-insensitively');
            $this->assertStringContainsString("{$worse} => \"BGR\"", $e->getMessage());
            $this->assertStringContainsString('2 rows', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame('bg', $this->country('addresses', $bad), 'the gate changes nothing');
    }

    public function test_the_order_gate_aborts_on_a_malformed_stored_country_and_names_the_order(): void
    {
        $bad = $this->insertOrder(OrderDeliveryType::STREET_ADDRESS, 'Bg');
        $this->insertOrder(OrderDeliveryType::STREET_ADDRESS, 'BG');

        try {
            $this->runGate(self::ORDER_GATE);
            $this->fail('the gate must refuse a malformed stored country.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('orders.country', $e->getMessage());
            $this->assertStringContainsString("{$bad} => \"Bg\"", $e->getMessage());
            $this->assertStringContainsString('1 row', $e->getMessage());
        }

        $this->assertSame('Bg', $this->country('orders', $bad));
    }

    // --- addresses:backfill-pickup-country -----------------------------------------------------

    public function test_the_backfill_dry_run_changes_nothing_and_says_what_it_would_do(): void
    {
        $pickup = $this->insertAddress('pickup_point', null);
        $order = $this->insertOrder(OrderDeliveryType::PICKUP_POINT, null);

        $this->artisan('addresses:backfill-pickup-country', ['code' => 'BG', '--dry-run' => true, '--orders' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('addresses: 1 pickup-point row would be filled')
            ->expectsOutputToContain('orders: 1 pickup-point row would be filled')
            ->assertExitCode(0);

        $this->assertNull($this->country('addresses', $pickup));
        $this->assertNull($this->country('orders', $order));
    }

    public function test_the_real_run_fills_only_null_pickup_rows_and_touches_no_street_address(): void
    {
        $nullPickup = $this->insertAddress('pickup_point', null);
        $otherNullPickup = $this->insertAddress('pickup_point', null);
        $hasCountry = $this->insertAddress('pickup_point', 'GR');
        $street = $this->insertAddress('street_address', 'RO');
        $streetBroken = $this->insertAddress('street_address', null);

        $this->artisan('addresses:backfill-pickup-country', ['code' => 'bg'])
            ->expectsOutputToContain('addresses: 2 pickup-point rows filled')
            ->assertExitCode(0);

        $this->assertSame('BG', $this->country('addresses', $nullPickup));
        $this->assertSame('BG', $this->country('addresses', $otherNullPickup));
        $this->assertSame('GR', $this->country('addresses', $hasCountry), 'a pickup point that already has a country keeps it');
        $this->assertSame('RO', $this->country('addresses', $street), 'a street address is never touched');
        $this->assertNull($this->country('addresses', $streetBroken), 'not even a street address with no country');
    }

    public function test_orders_are_untouched_without_the_orders_flag_and_filled_with_it(): void
    {
        $order = $this->insertOrder(OrderDeliveryType::PICKUP_POINT, null);
        $streetOrder = $this->insertOrder(OrderDeliveryType::STREET_ADDRESS, 'RO');
        $orderWithCountry = $this->insertOrder(OrderDeliveryType::PICKUP_POINT, 'GR');

        $this->artisan('addresses:backfill-pickup-country', ['code' => 'BG'])
            ->expectsOutputToContain('orders: not touched')
            ->assertExitCode(0);
        $this->assertNull($this->country('orders', $order), 'no --orders, no order is written');

        $this->artisan('addresses:backfill-pickup-country', ['code' => 'BG', '--orders' => true])
            ->expectsOutputToContain('orders: 1 pickup-point row filled')
            ->assertExitCode(0);

        $this->assertSame('BG', $this->country('orders', $order));
        $this->assertSame('RO', $this->country('orders', $streetOrder));
        $this->assertSame('GR', $this->country('orders', $orderWithCountry));
    }

    public function test_the_backfill_refuses_an_invalid_code_and_changes_nothing(): void
    {
        $pickup = $this->insertAddress('pickup_point', null);
        $order = $this->insertOrder(OrderDeliveryType::PICKUP_POINT, null);

        foreach (['ZZ', 'EU', 'XYZ', '1', 'ß'] as $bad) {
            $this->artisan('addresses:backfill-pickup-country', ['code' => $bad, '--orders' => true])
                ->expectsOutputToContain('Nothing was changed')
                ->assertExitCode(1);
        }

        $this->assertNull($this->country('addresses', $pickup));
        $this->assertNull($this->country('orders', $order));
    }

    public function test_the_backfill_accepts_xk_and_logs_what_it_changed(): void
    {
        $pickup = $this->insertAddress('pickup_point', null);
        \Illuminate\Support\Facades\Log::spy();

        $this->artisan('addresses:backfill-pickup-country', ['code' => 'XK'])->assertExitCode(0);

        $this->assertSame('XK', $this->country('addresses', $pickup));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'backfill-pickup-country')
                && $context['country'] === 'XK'
                && $context['rows']['addresses'] === [$pickup])
            ->once();
    }

    public function test_a_dry_run_writes_no_log_entry(): void
    {
        $this->insertAddress('pickup_point', null);
        \Illuminate\Support\Facades\Log::spy();

        $this->artisan('addresses:backfill-pickup-country', ['code' => 'BG', '--dry-run' => true])->assertExitCode(0);

        \Illuminate\Support\Facades\Log::shouldNotHaveReceived('info');
    }

    public function test_nothing_schedules_or_runs_the_backfill_automatically(): void
    {
        $scheduled = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($event): string => (string) $event->command)
            ->filter(fn (string $command): bool => str_contains($command, 'backfill-pickup-country'));

        $this->assertCount(0, $scheduled);
    }
}
