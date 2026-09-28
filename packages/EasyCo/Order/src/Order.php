<?php

namespace EasyCo\Order;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\InvalidOrderTransitionException;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use LogicException;

/**
 * A thin envelope over OperationalSales.Transaction — see
 * checkout-domain-design.md §2/§3 for the full field list and reasoning.
 * Order does NOT own line items; Transaction/SaleLine remain the ledger
 * (§2 — "the obvious shortcut... was considered and rejected"). Mirrors
 * EasyCo\Address\Address's shape: private constructor, named assertion
 * methods, a public create() factory, reconstituteFromStorage() for the
 * future persistence layer, and a one-time assignId().
 *
 * clientId/accountId/transactionId/addressId ARE ALL CROSS-DOMAIN BY
 * PLAIN ID ONLY (§4) — this package never depends on OperationalSales,
 * Account, or Address at the code level.
 *
 * THE ADDRESS SNAPSHOT FIELDS ARE DUPLICATED FROM Address, NOT SHARED —
 * both OrderDeliveryType (see that enum's own docblock) and the
 * STREET_ADDRESS/PICKUP_POINT exclusivity validation logic below are
 * deliberately re-implemented here rather than imported, for the exact
 * reason §4 states: the embedded snapshot fields are Order's own
 * columns, not a foreign read into Address.
 *
 * TOTAL IS ALWAYS COMPUTED BY create(), NEVER TRUSTED AS A RAW INPUT —
 * create() only accepts subtotal/discount and derives total itself
 * (subtotal->subtract(discount)), so it can never mathematically
 * disagree with them. reconstituteFromStorage() is the one exception:
 * it accepts an explicit total because it is reading back already-
 * computed, already-validated data from storage and must not recompute
 * anything (same "trusts the caller" posture Address::
 * reconstituteFromStorage() already documents) — it still runs the same
 * currency/non-negative assertions as create(), as a real integrity
 * check on what came out of the database, not a rubber stamp.
 *
 * MOSTLY IMMUTABLE — only two fields ever change after construction: `id`,
 * once, through the one-time assignId(); and `status`, through the five
 * named mutators below (confirm/ship/deliver/cancel/refund — one per legal
 * move), each guarded by OrderStatus's own transition matrix and nothing
 * else. Every other field is frozen: no setter, no reconstitute-and-resave.
 * Why status is the one mutable business fact, what a transition
 * deliberately does NOT write, and what the app layer wraps around it are
 * order-lifecycle-design.md §3 and §5.1.
 */
final class Order
{
    private function __construct(
        private ?string $id,
        private readonly string $clientId,
        private readonly ?string $accountId,
        private readonly string $transactionId,
        private readonly string $email,
        private readonly Currency $currency,
        private readonly Money $subtotal,
        private readonly Money $discount,
        private readonly Money $total,
        private readonly ?string $appliedPromotionCode,
        // NOT readonly — the one field the mutators below change (see this
        // class's own "MOSTLY IMMUTABLE" paragraph and §5.1).
        private OrderStatus $status,
        private readonly DateTimeImmutable $placedAt,
        private readonly ?string $addressId,
        private readonly OrderDeliveryType $deliveryType,
        private readonly string $recipientName,
        private readonly string $phone,
        private readonly ?string $country,
        private readonly ?string $city,
        private readonly ?string $postalCode,
        private readonly ?string $addressLine1,
        private readonly ?string $addressLine2,
        private readonly ?string $carrierCode,
        private readonly ?string $pickupPointReference,
        private readonly ?string $settlement,
    ) {
        self::assertNotEmpty('email', $email);
        self::assertNotEmpty('recipientName', $recipientName);
        self::assertNotEmpty('phone', $phone);
        self::assertNotEmpty('clientId', $clientId);
        self::assertNotEmpty('transactionId', $transactionId);
        self::assertAccountIdNotEmptyString($accountId);
        self::assertSameCurrency($currency, $subtotal, $discount, $total);
        self::assertDiscountDoesNotExceedSubtotal($total);
        self::assertFieldsMatchDeliveryType(
            deliveryType: $deliveryType,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );
    }

    private static function assertNotEmpty(string $fieldName, string $value): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("Order {$fieldName} must not be empty.");
        }
    }

    private static function assertAccountIdNotEmptyString(?string $accountId): void
    {
        if ($accountId === '') {
            throw new InvalidArgumentException('Order accountId must not be an empty string; use null for a guest order.');
        }
    }

    /**
     * Money itself only guards pairwise add()/subtract() — Order needs
     * its own three-way check across subtotal/discount/total, plus the
     * separately-stored top-level currency field they must all agree
     * with. Names exactly which field mismatched rather than a generic
     * "currency mismatch."
     */
    private static function assertSameCurrency(Currency $currency, Money $subtotal, Money $discount, Money $total): void
    {
        self::assertMoneyCurrency('subtotal', $subtotal, $currency);
        self::assertMoneyCurrency('discount', $discount, $currency);
        self::assertMoneyCurrency('total', $total, $currency);
    }

    private static function assertMoneyCurrency(string $fieldName, Money $money, Currency $currency): void
    {
        if (! $money->currency()->equals($currency)) {
            throw new InvalidArgumentException(
                "Order {$fieldName} currency \"{$money->currency()->code()}\" does not match Order currency \"{$currency->code()}\"."
            );
        }
    }

    private static function assertDiscountDoesNotExceedSubtotal(Money $total): void
    {
        if ($total->isNegative()) {
            throw new InvalidArgumentException('Order discount must not exceed subtotal; total would be negative.');
        }
    }

    /**
     * Enforces exclusivity between STREET_ADDRESS fields
     * (country/city/postalCode/addressLine1/addressLine2) and
     * PICKUP_POINT fields (carrierCode/pickupPointReference/settlement)
     * in BOTH directions — byte-for-byte the same rule
     * EasyCo\Address\Address::assertFieldsMatchDeliveryType() already
     * enforces, deliberately duplicated rather than shared (see this
     * class's own docblock).
     */
    private static function assertFieldsMatchDeliveryType(
        OrderDeliveryType $deliveryType,
        ?string $country,
        ?string $city,
        ?string $postalCode,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $carrierCode,
        ?string $pickupPointReference,
        ?string $settlement,
    ): void {
        if ($deliveryType === OrderDeliveryType::STREET_ADDRESS) {
            foreach (['country' => $country, 'city' => $city, 'addressLine1' => $addressLine1] as $name => $value) {
                if ($value === null || trim($value) === '') {
                    throw new InvalidArgumentException("Order {$name} must not be empty when deliveryType is STREET_ADDRESS.");
                }
            }

            foreach (['carrierCode' => $carrierCode, 'pickupPointReference' => $pickupPointReference, 'settlement' => $settlement] as $name => $value) {
                if ($value !== null) {
                    throw new InvalidArgumentException("Order {$name} must be null when deliveryType is STREET_ADDRESS, got a non-null value.");
                }
            }

            return;
        }

        foreach (['carrierCode' => $carrierCode, 'pickupPointReference' => $pickupPointReference, 'settlement' => $settlement] as $name => $value) {
            if ($value === null || trim($value) === '') {
                throw new InvalidArgumentException("Order {$name} must not be empty when deliveryType is PICKUP_POINT.");
            }
        }

        foreach ([
            'country' => $country,
            'city' => $city,
            'postalCode' => $postalCode,
            'addressLine1' => $addressLine1,
            'addressLine2' => $addressLine2,
        ] as $name => $value) {
            if ($value !== null) {
                throw new InvalidArgumentException("Order {$name} must be null when deliveryType is PICKUP_POINT, got a non-null value.");
            }
        }
    }

    /**
     * total is ALWAYS subtotal->subtract(discount), computed here, never
     * accepted as a parameter — see this class's own docblock. Note
     * placedAt has NO hidden now() default: the caller (eventually
     * Checkout) must supply it explicitly, keeping this entity trivially
     * testable with a fixed instant rather than a hidden wall-clock read.
     */
    public static function create(
        string $clientId,
        string $transactionId,
        string $email,
        Currency|string $currency,
        Money $subtotal,
        Money $discount,
        OrderDeliveryType $deliveryType,
        string $recipientName,
        string $phone,
        DateTimeImmutable $placedAt,
        ?string $accountId = null,
        ?string $appliedPromotionCode = null,
        OrderStatus $status = OrderStatus::PLACED,
        ?string $addressId = null,
        ?string $country = null,
        ?string $city = null,
        ?string $postalCode = null,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $carrierCode = null,
        ?string $pickupPointReference = null,
        ?string $settlement = null,
    ): self {
        $normalizedCurrency = Currency::from($currency);

        // Validated explicitly here (with a clear, field-naming message)
        // before subtract() ever runs — Money::subtract() would also
        // reject a subtotal/discount currency mismatch, but only with
        // its own generic message; this keeps Order's error messages
        // consistent regardless of entry path.
        self::assertMoneyCurrency('subtotal', $subtotal, $normalizedCurrency);
        self::assertMoneyCurrency('discount', $discount, $normalizedCurrency);

        $total = $subtotal->subtract($discount);

        return new self(
            id: null,
            clientId: $clientId,
            accountId: $accountId,
            transactionId: $transactionId,
            email: $email,
            currency: $normalizedCurrency,
            subtotal: $subtotal,
            discount: $discount,
            total: $total,
            appliedPromotionCode: $appliedPromotionCode,
            status: $status,
            placedAt: $placedAt,
            addressId: $addressId,
            deliveryType: $deliveryType,
            recipientName: $recipientName,
            phone: $phone,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );
    }

    /**
     * Reconstitutes an Order exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * this entity from an already-validated row should call it.
     *
     * Nothing calls this yet — the persistence layer is Step 1b, a
     * separate later prompt — but it is written now, ready for that step
     * to use unchanged.
     */
    public static function reconstituteFromStorage(
        string $id,
        string $clientId,
        ?string $accountId,
        string $transactionId,
        string $email,
        Currency|string $currency,
        Money $subtotal,
        Money $discount,
        Money $total,
        ?string $appliedPromotionCode,
        OrderStatus $status,
        DateTimeImmutable $placedAt,
        ?string $addressId,
        OrderDeliveryType $deliveryType,
        string $recipientName,
        string $phone,
        ?string $country,
        ?string $city,
        ?string $postalCode,
        ?string $addressLine1,
        ?string $addressLine2,
        ?string $carrierCode,
        ?string $pickupPointReference,
        ?string $settlement,
    ): self {
        return new self(
            id: $id,
            clientId: $clientId,
            accountId: $accountId,
            transactionId: $transactionId,
            email: $email,
            currency: Currency::from($currency),
            subtotal: $subtotal,
            discount: $discount,
            total: $total,
            appliedPromotionCode: $appliedPromotionCode,
            status: $status,
            placedAt: $placedAt,
            addressId: $addressId,
            deliveryType: $deliveryType,
            recipientName: $recipientName,
            phone: $phone,
            country: $country,
            city: $city,
            postalCode: $postalCode,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            carrierCode: $carrierCode,
            pickupPointReference: $pickupPointReference,
            settlement: $settlement,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Order already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function transactionId(): string
    {
        return $this->transactionId;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function appliedPromotionCode(): ?string
    {
        return $this->appliedPromotionCode;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    /**
     * placed -> confirmed (§2.1). Carries no guard of its own: §5.1's own
     * "what the domain half deliberately does not guard" list applies to
     * all five mutators.
     */
    public function confirm(): void
    {
        $this->transitionTo(OrderStatus::CONFIRMED);
    }

    /**
     * confirmed -> shipped (§2.1). R9's "the bank transfer must have
     * arrived" is a read the aggregate does not own, so it lives in the
     * service that wraps this call.
     */
    public function ship(): void
    {
        $this->transitionTo(OrderStatus::SHIPPED);
    }

    /**
     * shipped -> delivered (§2.1). R10's cash-on-delivery confirmation is
     * likewise the service's, not this class's.
     */
    public function deliver(): void
    {
        $this->transitionTo(OrderStatus::DELIVERED);
    }

    /**
     * placed|confirmed|shipped -> cancelled (§2.1) — the three ways an
     * order ends before the customer has it. Stock, money and the reason
     * recorded for the cancellation belong to the operation around this
     * call, never to the aggregate (§5.1).
     */
    public function cancel(): void
    {
        $this->transitionTo(OrderStatus::CANCELLED);
    }

    /**
     * delivered -> refunded (§2.1, §2.3) — the system-only move that closes
     * an order whose goods came back. Nothing but the status moves here:
     * the return that emptied the order wrote its own goods and money in
     * its own transaction.
     */
    public function refund(): void
    {
        $this->transitionTo(OrderStatus::REFUNDED);
    }

    /**
     * The one place a status changes, so both refusals live in exactly one
     * place: (1) the order is already in $to — OrderStatus's matrix answers
     * false for every same-status pair too, but the caller that made an
     * idempotent retry deserves the reason it actually hit; (2) the current
     * status may not move to $to at all. Nothing else is touched, ever: no
     * timestamp, no payment, no stock, no refund, no event row (§5.1's own
     * closing list).
     */
    private function transitionTo(OrderStatus $to): void
    {
        if ($this->status === $to) {
            throw InvalidOrderTransitionException::becauseAlreadyInStatus($to);
        }

        if (! $this->status->canTransitionTo($to)) {
            throw InvalidOrderTransitionException::becauseCannotTransition($this->status, $to);
        }

        $this->status = $to;
    }

    public function placedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function addressId(): ?string
    {
        return $this->addressId;
    }

    public function deliveryType(): OrderDeliveryType
    {
        return $this->deliveryType;
    }

    public function recipientName(): string
    {
        return $this->recipientName;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function city(): ?string
    {
        return $this->city;
    }

    public function postalCode(): ?string
    {
        return $this->postalCode;
    }

    public function addressLine1(): ?string
    {
        return $this->addressLine1;
    }

    public function addressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function carrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function pickupPointReference(): ?string
    {
        return $this->pickupPointReference;
    }

    public function settlement(): ?string
    {
        return $this->settlement;
    }
}
