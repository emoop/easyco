<?php

namespace App\Storefront\Orders;

use App\Mail\BankTransferDetails;
use App\Mail\Iban;
use App\Services\PriceDisplayFormatter;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CountryNames;
use App\Settings\StoreLocale;
use App\Settings\StoreTimezone;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Pricing\Money;

/**
 * What the order-received page shows, read through the repositories and the shared formatters; it writes nothing and changes
 * no domain. The order number IS the order id (the confirmation email calls it that too). Every text is RAW plain text (the
 * view escapes), every amount is already formatted by PriceDisplayFormatter, and the dates are in the store's time zone.
 *
 * Returns null for anything that is not an order id of an existing order: the controller turns that into the storefront 404.
 *
 * The bank block comes from `payment.bank_transfer.*` through BankTransferDetails: the SAME source and wording as the order
 * confirmation email (mail-design.md §6.1.1), omitted while those settings are empty and for a payment that "needs
 * attention" (PENDING with no attempt date: the neutral page makes no payment claim, as the email does).
 *
 * Query cost: the order, its transaction with the sale lines, its payment, the store settings read once (locale, time zone,
 * currency position, bank block) - fixed, independent of the number of lines.
 */
final class OrderReceivedReader
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly TransactionRepository $transactions,
        private readonly PaymentRepository $payments,
        private readonly PriceDisplayFormatter $prices,
        private readonly StoreTimezone $timezone,
        private readonly StoreLocale $locale,
        private readonly SiteSettingsRepository $settings,
    ) {
    }

    /**
     * @return array{
     *   number: string, email: string, placed_at: string,
     *   lines: list<array{name: string, label: string, quantity: int, total: string}>,
     *   shipping: ?array{name: string, courier: ?string, price: string, free: bool},
     *   subtotal: string, discount: ?array{code: ?string, amount: string}, total: string,
     *   destination: array{kind: string, pickup_kind: ?string, lines: list<string>},
     *   note: ?string,
     *   payment: array{method: string, bank: ?array{rows: list<array{0: string, 1: string}>, deadline_days: ?int, instructions: ?string}, cash_on_delivery_due: ?string}
     * }|null
     */
    public function find(string $orderNumber): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $orderNumber) !== 1) {
            return null;
        }

        $order = $this->orders->findById($orderNumber);

        if ($order === null) {
            return null;
        }

        $locale = $this->locale->current();
        $payment = $this->payments->findByOrderId((string) $order->id())[0] ?? null;
        $needsAttention = $payment !== null && $payment->status() === PaymentStatus::PENDING && $payment->attemptedAt() === null;
        $method = $payment?->method() ?? '';

        return [
            'number' => (string) $order->id(),
            'email' => $order->email(),
            'placed_at' => $order->placedAt()->setTimezone($this->timezone->zone())->format($locale === 'bg' ? 'd.m.Y H:i' : 'Y-m-d H:i'),
            'lines' => $this->lines($order),
            'shipping' => $order->shippingMethodName() === null ? null : [
                'name' => $order->shippingMethodName(),
                'courier' => $order->shippingCourier() === '' ? null : $order->shippingCourier(),
                'price' => $this->money($order->shipping()),
                'free' => $order->shipping()->isZero(),
            ],
            'subtotal' => $this->money($order->subtotal()),
            'discount' => $order->discount()->isPositive() ? ['code' => $order->appliedPromotionCode(), 'amount' => $this->money($order->discount())] : null,
            'total' => $this->money($order->total()),
            'destination' => $this->destination($order, $locale),
            'note' => $this->customerNote($order),
            'payment' => [
                'method' => $method,
                'bank' => $method === 'bank_transfer' && ! $needsAttention ? $this->bank($order) : null,
                'cash_on_delivery_due' => $method === 'cash_on_delivery' && ! $needsAttention ? $this->money($order->total()) : null,
            ],
        ];
    }

    private function money(Money $money): string
    {
        return $this->prices->format($money->decimalValue(), $money->currency());
    }

    /**
     * The customer's own note (checkout RN-1) as one text, one line per line they typed (each line trimmed, blank ones dropped), for the
     * page's own note block; null when there is none. RAW text: the page escapes it and keeps the line breaks with CSS, never markup.
     */
    private function customerNote(Order $order): ?string
    {
        if ($order->customerNote() === null) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $order->customerNote()) ?: []), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** @return list<array{name: string, label: string, quantity: int, total: string}> */
    private function lines(Order $order): array
    {
        $transaction = $this->transactions->findByIdWithSaleLines($order->transactionId());
        $lines = [];

        foreach ($transaction?->saleLines() ?? [] as $line) {
            if ($line->type() !== SaleLineType::SALE || $line->status() === SaleLineStatus::CANCELLED) {
                continue;
            }

            $unit = $line->finalUnitPrice();

            $lines[] = [
                'name' => (string) $line->productName(),
                'label' => $this->label($line),
                'quantity' => $line->quantity(),
                'total' => $this->money($unit === null ? $line->amount() : $unit->multiply($line->quantity())),
            ];
        }

        return $lines;
    }

    private function label(SaleLine $line): string
    {
        $values = [];

        foreach ($line->soldAttributes() ?? [] as $attribute) {
            $values[] = $attribute['value'];
        }

        return implode(' / ', $values);
    }

    /** @return array{kind: string, pickup_kind: ?string, lines: list<string>} */
    private function destination(Order $order, string $locale): array
    {
        if ($order->deliveryType() === OrderDeliveryType::PICKUP_POINT) {
            // The kind the customer chose (office | locker; null for an order placed before it was asked, and for any address).
            return ['kind' => 'pickup_point', 'pickup_kind' => $order->pickupPointKind(), 'lines' => array_values(array_filter([
                $order->pickupPointName(),
                $order->pickupPointAddress(),
                $order->settlement(),
            ], static fn (?string $part): bool => $part !== null && trim($part) !== ''))];
        }

        $country = $order->country();
        $countryName = $country === null ? null : (CountryNames::forLocale($locale)[$country] ?? $country);

        return ['kind' => 'address', 'pickup_kind' => null, 'lines' => array_values(array_filter([
            $order->addressLine1(),
            $order->addressLine2(),
            trim(($order->postalCode() ?? '').' '.($order->city() ?? '')),
            // The region (checkout RN-1), after the postal code and city; nothing at all when the order has none.
            $order->region(),
            $countryName,
        ], static fn (?string $part): bool => $part !== null && trim($part) !== ''))];
    }

    /** @return array{rows: list<array{0: string, 1: string}>, deadline_days: ?int, instructions: ?string}|null */
    private function bank(Order $order): ?array
    {
        $bank = BankTransferDetails::fromSettings($this->settings);

        if ($bank->isEmpty()) {
            return null;
        }

        $locale = $this->locale->current();
        $t = static fn (string $key, array $replace = []): string => (string) __('mail.bank_transfer.'.$key, $replace, $locale);
        $rows = [];

        foreach ([['holder', $bank->accountHolder], ['bank', $bank->bankName], ['iban', $bank->iban === null ? null : Iban::format($bank->iban)], ['bic', $bank->bic]] as [$label, $value]) {
            if ($value !== null) {
                $rows[] = [$t($label), $value];
            }
        }

        $rows[] = [$t('reason'), $t('reason_value', ['number' => (string) $order->id()])];
        $rows[] = [$t('amount'), $this->money($order->total())];

        return ['rows' => $rows, 'deadline_days' => $bank->deadlineDays, 'instructions' => $bank->instructions];
    }
}
