<?php

namespace Tests\Feature\Account\Concerns;

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
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;

/** ACC-2 fixtures: real orders (client, transaction with sale lines, order row with its identity keys) written through the repositories, with an email, an owner and a date of our choosing. */
trait MakesAccountOrders
{
    private static int $accountOrderCounter = 0;

    private function eurMoney(int $minor): Money
    {
        return Money::fromMinorUnits($minor, 'EUR');
    }

    /**
     * @param  list<array{quantity: int, unit: int}>  $lines
     * @return string the order id (= the order number)
     */
    private function makeOrder(string $email = 'buyer@example.com', ?string $accountId = null, string $placedAt = '2026-09-28 09:00:00', array $lines = [['quantity' => 2, 'unit' => 1500]], ?string $note = null, OrderStatus $status = OrderStatus::PLACED, ?string $city = 'Sofia', ?string $postcode = null, ?string $region = null, ?string $pickupSettlement = null): string
    {
        self::$accountOrderCounter++;
        $n = self::$accountOrderCounter;

        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $subtotal = 0;

        foreach ($lines as $i => $line) {
            $gross = $line['quantity'] * $line['unit'];
            $subtotal += $gross;

            $transaction->addSaleLine(SaleLine::create(
                transactionId: '',
                clientId: $client->id(),
                priceableId: 'virtual-'.$n.'-'.$i,
                status: SaleLineStatus::COMPLETED,
                quantity: $line['quantity'],
                amount: $this->eurMoney($gross),
                profit: $this->eurMoney(777),                       // a number that must never reach a customer
                recordedAt: new DateTimeImmutable($placedAt),
                effectiveAt: new DateTimeImmutable($placedAt),
                productName: 'Product '.$i,
                sku: 'SKU-'.$n.'-'.$i,
                regularUnitPrice: $this->eurMoney($line['unit']),
                finalUnitPrice: $this->eurMoney($line['unit']),
                promotionDiscountShare: $this->eurMoney(0),
                discretionaryDiscount: $this->eurMoney(0),
                netPaidAmount: $this->eurMoney($gross),
                soldAttributes: [['definitionId' => '1', 'definitionCode' => 'size', 'definitionName' => 'Size', 'valueId' => '1', 'value' => 'M']],
            ));
        }

        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: $email,
            currency: 'EUR',
            subtotal: $this->eurMoney($subtotal),
            discount: $this->eurMoney(0),
            deliveryType: $pickupSettlement === null ? OrderDeliveryType::STREET_ADDRESS : OrderDeliveryType::PICKUP_POINT,
            recipientFirstName: 'Ivan', recipientLastName: 'Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable($placedAt),
            accountId: $accountId,
            status: $status,
            country: 'BG',
            // ORD-PC: a street address by default; with $pickupSettlement an OFFICE order (no city, no postcode, no street: the domain refuses them for a pickup point)
            city: $pickupSettlement === null ? $city : null,
            postalCode: $pickupSettlement === null ? $postcode : null,
            addressLine1: $pickupSettlement === null ? 'Vitosha Blvd 1' : null,
            shipping: $this->eurMoney(0),
            shippingMethodName: 'Free delivery',
            customerNote: $note,
            region: $pickupSettlement === null ? $region : null,
            carrierCode: $pickupSettlement === null ? null : 'speedy',
            pickupPointReference: $pickupSettlement === null ? null : 'OFF-1',
            pickupPointName: $pickupSettlement === null ? null : 'Speedy office',
            pickupPointAddress: $pickupSettlement === null ? null : 'Vasil Levski 52',
            settlement: $pickupSettlement,
            pickupPointKind: $pickupSettlement === null ? null : 'office',
        );
        app(OrderRepository::class)->save($order);

        return (string) $order->id();
    }

    private function orderAccountId(string $orderId): ?string
    {
        $value = DB::table('orders')->where('id', $orderId)->value('account_id');

        return $value === null ? null : (string) $value;
    }
}
