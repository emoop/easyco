<?php

namespace Tests\Concerns;

use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentRefund;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;

/**
 * Real, persisted orders with real sale lines and a settled payment, for the
 * refund tests (R1b). Each line: quantity, unit price, and an optional
 * promotion-discount share (a share equal to the whole line makes it a free
 * line — net paid 0).
 */
trait BuildsRefundableOrders
{
    private static int $refundFixtureCounter = 0;

    private function eur(int $minor): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-28 12:00:00');
    }

    private function variationWithStock(int $stock = 10): string
    {
        self::$refundFixtureCounter++;
        $n = self::$refundFixtureCounter;
        $product = Product::createSimple('Refund Fixture '.$n, 'RFX-'.$n, 'refund-fixture-'.$n);
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        return $variationId;
    }

    private function stockOf(string $variationId): int
    {
        return app(StockLevelRepository::class)->findByVariationId($variationId)->quantity();
    }

    /**
     * @param  list<array{quantity: int, unit: int, discount?: int}>  $lines
     * @param  int|null  $paymentMinor  what the settled payment holds; null = the order total (null payment: pass settle false)
     * @return array{orderId: string, saleLineIds: list<string>, variationIds: list<string>, payment: ?Payment}
     */
    private function refundableOrder(
        array $lines,
        OrderStatus $status = OrderStatus::SHIPPED,
        int $shippingMinor = 0,
        string $method = 'cash_on_delivery',
        ?int $paymentMinor = null,
        bool $settle = true,
        bool $restockable = true,
    ): array {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $variationIds = [];
        $subtotal = 0;
        $discount = 0;

        foreach ($lines as $line) {
            $variationId = $restockable ? $this->variationWithStock(10) : 'virtual-'.(++self::$refundFixtureCounter);
            $variationIds[] = $variationId;
            $gross = $line['quantity'] * $line['unit'];
            $share = $line['discount'] ?? 0;
            $subtotal += $gross;
            $discount += $share;

            $transaction->addSaleLine(SaleLine::create(
                transactionId: '',
                clientId: $client->id(),
                priceableId: $variationId,
                status: SaleLineStatus::COMPLETED,
                quantity: $line['quantity'],
                amount: $this->eur($gross),
                profit: $this->eur(0),
                recordedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
                effectiveAt: new DateTimeImmutable('2026-09-28 09:00:00'),
                productName: 'Product',
                sku: 'SKU-'.self::$refundFixtureCounter,
                regularUnitPrice: $this->eur($line['unit']),
                finalUnitPrice: $this->eur($line['unit']),
                promotionDiscountShare: $this->eur($share),
                discretionaryDiscount: $this->eur(0),
                netPaidAmount: $this->eur($gross - $share),
                soldAttributes: [],
            ));
        }

        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: $this->eur($subtotal),
            discount: $this->eur($discount),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-09-28 09:00:00'),
            status: $status,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shipping: $this->eur($shippingMinor),
            shippingMethodName: $shippingMinor > 0 ? 'Test courier' : null,
        );
        app(OrderRepository::class)->save($order);

        $payment = null;

        if ($settle) {
            $payment = Payment::create($order->id(), $method, $this->eur($paymentMinor ?? ($subtotal - $discount + $shippingMinor)), PaymentStatus::PENDING);
            $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
            $payment->confirm(new DateTimeImmutable('2026-09-28 09:30:00'));
            app(PaymentRepository::class)->save($payment);
        }

        return [
            'orderId' => (string) $order->id(),
            'saleLineIds' => array_map(static fn (SaleLine $l): string => (string) $l->id(), $transaction->saleLines()),
            'variationIds' => $variationIds,
            'payment' => $payment,
        ];
    }

    /** @return list<PaymentRefund> */
    private function refundsOf(Payment $payment): array
    {
        return app(PaymentRefundRepository::class)->findByPaymentId((string) $payment->id());
    }

    private function eventTypesOf(string $orderId): array
    {
        return DB::table('order_events')->where('order_id', $orderId)->orderBy('id')->pluck('type')->all();
    }
}
