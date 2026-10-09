<?php

namespace App\Mail;

use App\Services\PriceDisplayFormatter;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\CountryNames;
use App\Settings\StoreTimezone;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use stdClass;

/**
 * Builds the order-confirmation mail (mail-design.md §6.1) from the ORDER and its sale-line snapshot —
 * the same sources the checkout response is built from (CheckoutController::orderToArray(), shippingToArray()
 * and saleLinesToArray()) — never from live product data, so a product renamed after the sale does not
 * change what the customer is told. Nothing is re-priced: every amount is read from the order or a sale line.
 * Cost and profit columns are never read.
 *
 * Returns null when the order no longer exists (the caller marks the mail skipped).
 */
final class OrderConfirmationContent
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly TransactionRepository $transactions,
        private readonly PaymentRepository $payments,
        private readonly PriceDisplayFormatter $prices,
        private readonly StoreTimezone $timezone,
        private readonly SiteSettingsRepository $settings,
        private readonly MailTemplates $templates,
        private readonly TemplateRenderer $renderer,
    ) {
    }

    public function build(stdClass $logRow): ?RenderedMail
    {
        $order = $this->orders->findById((string) $logRow->related_id);

        if ($order === null) {
            return null;
        }

        $locale = (string) $logRow->locale;
        $payment = $this->payments->findByOrderId((string) $order->id())[0] ?? null;

        $scalars = [
            'shop_name' => $this->shopName(),
            'customer_name' => MailHeader::clean($order->recipientName(), 120),
            'order_number' => (string) $order->id(),
            'order_date' => $order->placedAt()->setTimezone($this->timezone->zone())->format($locale === 'bg' ? 'd.m.Y H:i' : 'Y-m-d H:i'),
            'order_total' => $this->money($order->total()),
            'payment_method_label' => $this->paymentLabel($payment, $locale),
            'support_email' => $this->supportEmail(),
        ];

        $blocks = [
            'order_lines' => MailBlocks::orderLines($this->lines($order), $locale),
            'order_totals' => $this->totals($order, $locale),
            'delivery_summary' => $this->delivery($order, $locale),
            'payment_instructions' => $this->paymentBlock($order, $payment, $locale),
        ];

        return $this->renderer->render($this->templates->get(MailTemplates::ORDER_CONFIRMATION), $locale, $scalars, $blocks);
    }

    private function shopName(): string
    {
        return MailHeader::clean((string) config('app.name'), 80);
    }

    private function supportEmail(): string
    {
        $configured = MailHeader::address((string) $this->settings->get('mail.reply_to'));

        return $configured ?? (string) config('mail.from.address');
    }

    private function money(Money $money): string
    {
        return $this->prices->format($money->decimalValue(), $money->currency());
    }

    /**
     * The order's sale lines as the checkout response shows them: the SALE lines that still stand.
     *
     * @return list<array{name: string, sku: ?string, attributes: string, quantity: int, unit: ?string, total: string}>
     */
    private function lines(Order $order): array
    {
        $transaction = $this->transactions->findByIdWithSaleLines($order->transactionId());
        $lines = [];

        foreach ($transaction?->saleLines() ?? [] as $line) {
            /** @var SaleLine $line */
            if ($line->type() !== SaleLineType::SALE || $line->status() === SaleLineStatus::CANCELLED) {
                continue;
            }

            $unit = $line->finalUnitPrice();

            $lines[] = [
                'name' => MailHeader::clean((string) $line->productName(), 255),
                'sku' => $line->sku() === null ? null : MailHeader::clean($line->sku(), 80),
                'attributes' => $this->attributes($line),
                'quantity' => $line->quantity(),
                'unit' => $unit === null ? null : $this->money($unit),
                'total' => $this->money($unit === null ? $line->amount() : $unit->multiply($line->quantity())),
            ];
        }

        return $lines;
    }

    private function attributes(SaleLine $line): string
    {
        $parts = [];

        foreach ($line->soldAttributes() ?? [] as $attribute) {
            $parts[] = MailHeader::clean($attribute['definitionName'], 60).': '.MailHeader::clean($attribute['value'], 60);
        }

        return implode(', ', $parts);
    }

    private function totals(Order $order, string $locale): string
    {
        $rows = [[(string) __('mail.order.totals.subtotal', [], $locale), $this->money($order->subtotal())]];

        if ($order->discount()->isPositive()) {
            $label = $order->appliedPromotionCode() === null
                ? (string) __('mail.order.totals.discount', [], $locale)
                : (string) __('mail.order.totals.discount_with_code', ['code' => MailHeader::clean($order->appliedPromotionCode(), 40)], $locale);
            $rows[] = [$label, '-'.$this->money($order->discount())];
        }

        if ($order->shippingMethodName() !== null) {
            $rows[] = [
                (string) __('mail.order.totals.shipping_named', ['method' => MailHeader::clean($order->shippingMethodName(), 80)], $locale),
                $order->shipping()->isZero() ? (string) __('mail.order.totals.shipping_free', [], $locale) : $this->money($order->shipping()),
            ];
        }

        $rows[] = [(string) __('mail.order.totals.total', [], $locale), $this->money($order->total())];

        return MailBlocks::pairs($rows);
    }

    /** Delivery facts exactly as the order stored them at placement (4e/4f): street fields, or the pickup point's display snapshot. */
    private function delivery(Order $order, string $locale): string
    {
        $t = static fn (string $key): string => (string) __('mail.order.delivery.'.$key, [], $locale);
        $rows = [];

        if ($order->shippingMethodName() !== null) {
            $method = MailHeader::clean($order->shippingMethodName(), 80);
            $courier = $order->shippingCourier();
            $rows[] = [$t('method'), $courier === null || $courier === '' ? $method : $method.' / '.MailHeader::clean($courier, 40)];
        }

        $rows[] = [$t('recipient'), MailHeader::clean($order->recipientName(), 120)];

        if ($order->deliveryType() === OrderDeliveryType::PICKUP_POINT) {
            $point = array_filter([
                $order->pickupPointName(),
                $order->pickupPointAddress(),
                $order->settlement(),
            ], static fn (?string $part): bool => $part !== null && $part !== '');
            $rows[] = [$t('pickup_point'), implode("\n", array_map(static fn (string $part): string => MailHeader::clean($part, 200), $point))];

            if ($order->carrierCode() !== null && $order->carrierCode() !== '') {
                $rows[] = [$t('courier'), MailHeader::clean($order->carrierCode(), 40)];
            }

            if ($order->pickupPointReference() !== null && $order->pickupPointReference() !== '') {
                $rows[] = [$t('reference'), MailHeader::clean($order->pickupPointReference(), 80)];
            }
        } else {
            $country = $order->country();
            $countryName = $country === null ? null : (CountryNames::forLocale($locale)[$country] ?? $country);
            $address = array_filter([
                $order->addressLine1(),
                $order->addressLine2(),
                trim(($order->postalCode() ?? '').' '.($order->city() ?? '')),
                $countryName,
            ], static fn (?string $part): bool => $part !== null && trim($part) !== '');
            $rows[] = [$t('address'), implode("\n", array_map(static fn (string $part): string => MailHeader::clean($part, 200), $address))];
        }

        return MailBlocks::pairs($rows, $t('heading'));
    }

    private function paymentLabel(?Payment $payment, string $locale): string
    {
        if ($payment === null) {
            return (string) __('mail.payment.unknown', [], $locale);
        }

        $key = 'mail.payment.methods.'.$payment->method();
        $label = (string) __($key, [], $locale);

        return $label === $key ? MailHeader::clean($payment->method(), 60) : $label;
    }

    /**
     * Bank transfer: the instructions block from `payment.bank_transfer.*`, omitted while those settings are empty.
     * Cash on delivery: the amount to collect. A payment step that needs attention (4c: PENDING, no attempt date)
     * gets neither — the neutral confirmation makes no payment claim.
     */
    private function paymentBlock(Order $order, ?Payment $payment, string $locale): string
    {
        if ($payment === null || ($payment->status() === PaymentStatus::PENDING && $payment->attemptedAt() === null)) {
            return '';
        }

        if ($payment->method() === 'cash_on_delivery') {
            return MailBlocks::paragraphs((string) __('mail.payment.cash_on_delivery_due', ['amount' => $this->money($order->total())], $locale));
        }

        if ($payment->method() !== 'bank_transfer') {
            return '';
        }

        $bank = BankTransferDetails::fromSettings($this->settings);

        if ($bank->isEmpty()) {
            return '';
        }

        $t = static fn (string $key, array $replace = []): string => (string) __('mail.bank_transfer.'.$key, $replace, $locale);
        $rows = [];

        foreach ([['holder', $bank->accountHolder], ['bank', $bank->bankName], ['iban', $bank->iban === null ? null : Iban::format($bank->iban)], ['bic', $bank->bic]] as [$label, $value]) {
            if ($value !== null) {
                $rows[] = [$t($label), $value];
            }
        }

        $rows[] = [$t('reason'), $t('reason_value', ['number' => (string) $order->id()])];
        $rows[] = [$t('amount'), $this->money($order->total())];

        $html = MailBlocks::pairs($rows, $t('heading'));

        if ($bank->deadlineDays !== null) {
            $html .= MailBlocks::paragraphs($t('deadline', ['days' => (string) $bank->deadlineDays]));
        }

        if ($bank->instructions !== null) {
            $html .= MailBlocks::paragraphs($bank->instructions);
        }

        return $html;
    }
}
