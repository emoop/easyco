<?php

namespace EasyCo\Order;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\InvalidOrderTransitionException;
use EasyCo\Order\Exceptions\OrderNotEditableException;
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
 * create() only accepts subtotal/discount/shipping and derives total itself
 * (subtotal - discount + shipping), so it can never mathematically
 * disagree with them. SHIPPING (shipping-domain-design.md §7) is an
 * order-level amount only: it is not a SaleLine and never part of the
 * goods ledger, so `subtotal - discount` stays the sum of the lines'
 * netPaidAmount and `shipping` rides on top. It is fixed at construction
 * and never revised by an edit (an edit carries it through unchanged); the
 * method name and code are plain-string snapshots — this package does not
 * import the Shipping package. reconstituteFromStorage() is the one exception:
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
        // NOT readonly (stage 2) — reviseTotals() changes these four
        // together, one-shot, never partially (order-editing-design.md §2).
        private Money $subtotal,
        private Money $discount,
        // Shipping (shipping stage 2): order-level, snapshotted at placement,
        // never revised by reviseTotals() — see this class's docblock.
        private readonly Money $shipping,
        private readonly ?string $shippingMethodName,
        private readonly ?string $shippingMethodCode,
        private Money $total,
        private ?string $appliedPromotionCode,
        // NOT readonly — the one field the mutators below change (see this
        // class's own "MOSTLY IMMUTABLE" paragraph and §5.1).
        private OrderStatus $status,
        private readonly DateTimeImmutable $placedAt,
        // STAYS readonly (stage 2, D2) — provenance ("which saved address
        // this order started from"), never a live pointer reviseDelivery()
        // re-targets.
        private readonly ?string $addressId,
        // NOT readonly (stage 2) — reviseDelivery() replaces the whole
        // delivery snapshot below atomically, one-shot.
        private OrderDeliveryType $deliveryType,
        private string $recipientName,
        private string $phone,
        private ?string $country,
        private ?string $city,
        private ?string $postalCode,
        private ?string $addressLine1,
        private ?string $addressLine2,
        private ?string $carrierCode,
        private ?string $pickupPointReference,
        private ?string $settlement,
        // NOT readonly — bumpEditRevision() increments it by exactly 1 per
        // successful edit (order-editing-design.md §2.2). Last and
        // defaulted so every existing call site keeps compiling unchanged.
        private int $editRevision = 0,
        // Shipping facts beyond the name/code (shipping stage 4a, §9.1.4): the courier, the delivery type and the
        // carrier service the customer chose, snapshotted at placement. Trailing and nullable like the pre-4a
        // shape, so an order placed before (or with no shipping method) simply has none.
        private readonly ?string $shippingCourier = null,
        private readonly ?string $shippingDeliveryType = null,
        private readonly ?string $shippingServiceCode = null,
        // Display snapshot of the chosen pickup point (stage 4f).
        private ?string $pickupPointName = null,
        private ?string $pickupPointAddress = null,
    ) {
        self::assertPickupDisplay($deliveryType, $pickupPointName, $pickupPointAddress);

        if ($editRevision < 0) {
            throw new InvalidArgumentException('Order editRevision must not be negative.');
        }

        self::assertNotEmpty('email', $email);
        self::assertNotEmpty('recipientName', $recipientName);
        self::assertNotEmpty('phone', $phone);
        self::assertNotEmpty('clientId', $clientId);
        self::assertNotEmpty('transactionId', $transactionId);
        self::assertAccountIdNotEmptyString($accountId);
        self::assertSameCurrency($currency, $subtotal, $discount, $shipping, $total);
        self::assertDiscountDoesNotExceedSubtotal($subtotal, $discount, $total);
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
    private static function assertSameCurrency(Currency $currency, Money $subtotal, Money $discount, Money $shipping, Money $total): void
    {
        self::assertMoneyCurrency('subtotal', $subtotal, $currency);
        self::assertMoneyCurrency('discount', $discount, $currency);
        self::assertMoneyCurrency('shipping', $shipping, $currency);
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

    /**
     * Checked on the GOODS (subtotal - discount) AND on the total: with a
     * shipping charge on top, a discount larger than the subtotal could
     * otherwise hide behind it, and the original "total must not be
     * negative" check (which also guards an explicit total read back from
     * storage) is kept. For a zero-shipping order the two coincide.
     */
    private static function assertDiscountDoesNotExceedSubtotal(Money $subtotal, Money $discount, Money $total): void
    {
        if ($total->isNegative() || $subtotal->subtract($discount)->isNegative()) {
            throw new InvalidArgumentException('Order discount must not exceed subtotal; total would be negative.');
        }
    }

    /**
     * Shipping's create()-time rules (shipping-domain-design.md §7.1): never
     * negative; a name, when given, is trimmed, non-empty and at most 255
     * characters; a code, when given, is trimmed, at most 64 characters and
     * requires a name; a positive shipping amount requires a name. Returns
     * the normalized [name, code]. Deliberately NOT run on the read path
     * (reconstituteFromStorage() adds no new throw) — the database holds what
     * create() once validated.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function normalizeShipping(Money $shipping, ?string $methodName, ?string $methodCode): array
    {
        if ($shipping->isNegative()) {
            throw new InvalidArgumentException('Order shipping must not be negative.');
        }

        $name = $methodName === null ? null : trim($methodName);
        $code = $methodCode === null ? null : trim($methodCode);

        if ($name !== null && $name === '') {
            throw new InvalidArgumentException('Order shippingMethodName must not be empty when given; use null for no shipping method.');
        }

        if ($name !== null && mb_strlen($name) > 255) {
            throw new InvalidArgumentException('Order shippingMethodName must not be longer than 255 characters.');
        }

        if ($code !== null && ($code === '' || mb_strlen($code) > 64)) {
            throw new InvalidArgumentException('Order shippingMethodCode must be 1 to 64 characters when given; use null for none.');
        }

        if ($code !== null && $name === null) {
            throw new InvalidArgumentException('Order shippingMethodCode requires a shippingMethodName.');
        }

        if ($shipping->isPositive() && $name === null) {
            throw new InvalidArgumentException('Order shipping greater than zero requires a shippingMethodName.');
        }

        return [$name, $code];
    }

    /**
     * The delivery types a shipping method can carry (the same list as shipping_methods.delivery_type and the CHECK on
     * orders / order_placement_snapshots). A plain list here, not the Shipping package's enum: the Order domain does not
     * import another domain (CLAUDE.md rule 9).
     */
    public const SHIPPING_DELIVERY_TYPES = ['address', 'office', 'locker', 'other'];

    /**
     * Stage 4a: courier (1-100), delivery type (one of SHIPPING_DELIVERY_TYPES) and carrier service code (1-64) are each
     * optional, trimmed, and only allowed when the order names a shipping method (they describe it).
     *
     * @return array{0: ?string, 1: ?string, 2: ?string}
     */
    private static function normalizeShippingFacts(?string $methodName, ?string $courier, ?string $deliveryType, ?string $serviceCode): array
    {
        $courier = $courier === null ? null : trim($courier);
        $deliveryType = $deliveryType === null ? null : trim($deliveryType);
        $serviceCode = $serviceCode === null ? null : trim($serviceCode);

        if ($courier !== null && ($courier === '' || mb_strlen($courier) > 100)) {
            throw new InvalidArgumentException('Order shippingCourier must be 1 to 100 characters when given; use null for none.');
        }

        if ($deliveryType !== null && ! in_array($deliveryType, self::SHIPPING_DELIVERY_TYPES, true)) {
            throw new InvalidArgumentException('Order shippingDeliveryType must be one of: '.implode(', ', self::SHIPPING_DELIVERY_TYPES).'; use null for none.');
        }

        if ($serviceCode !== null && ($serviceCode === '' || mb_strlen($serviceCode) > 64)) {
            throw new InvalidArgumentException('Order shippingServiceCode must be 1 to 64 characters when given; use null for none.');
        }

        if ($methodName === null && ($courier !== null || $deliveryType !== null || $serviceCode !== null)) {
            throw new InvalidArgumentException('Order shippingCourier, shippingDeliveryType and shippingServiceCode require a shippingMethodName.');
        }

        return [$courier, $deliveryType, $serviceCode];
    }


    /**
     * The pickup point's DISPLAY SNAPSHOT (shipping stage 4f), copied from the address at placement: allowed only for a
     * PICKUP_POINT, null for a STREET_ADDRESS, 1 to 255 characters when given. Null on a pickup order is legal (an order from a
     * historical saved pickup address). The reference stays the identifier; these are text for people.
     */
    private static function assertPickupDisplay(OrderDeliveryType $deliveryType, ?string $pickupPointName, ?string $pickupPointAddress): void
    {
        foreach (['pickupPointName' => $pickupPointName, 'pickupPointAddress' => $pickupPointAddress] as $name => $value) {
            if ($value === null) {
                continue;
            }

            if ($deliveryType === OrderDeliveryType::STREET_ADDRESS) {
                throw new InvalidArgumentException("Order {$name} must be null when deliveryType is STREET_ADDRESS, got a non-null value.");
            }

            if (trim($value) === '' || mb_strlen($value) > 255) {
                throw new InvalidArgumentException("Order {$name} must be 1 to 255 characters when given; use null for none.");
            }
        }
    }

    private static function trimmedOrNull(?string $value): ?string
    {
        return $value === null ? null : trim($value);
    }

    /**
     * Enforces exclusivity between STREET_ADDRESS fields
     * (city/postalCode/addressLine1/addressLine2) and
     * PICKUP_POINT fields (pickupPointReference/settlement) in BOTH
     * directions — byte-for-byte the same rule
     * EasyCo\Address\Address::assertFieldsMatchDeliveryType() already
     * enforces, deliberately duplicated rather than shared (see this
     * class's own docblock).
     *
     * order-editing-design.md §3 (D4, stage 2) — ONE NARROWING, STATED
     * EXPLICITLY: carrierCode is no longer forbidden for STREET_ADDRESS.
     * E2 asks for "set/change the courier" on any order, including a
     * street-address one — a real, new fact this class could not represent
     * before. pickupPointReference/settlement stay exactly as forbidden as
     * before (a location identifier is still meaningless for a home
     * delivery). This method is called by BOTH create() and
     * reviseDelivery() (stage 2's own new mutator), so the relaxation
     * applies to FRESH orders too, not only edited ones — an intentional
     * consequence of there being one implementation, not a side effect.
     *
     * COUNTRY BELONGS TO BOTH TYPES (owner decision D1, shipping stage 3.0b),
     * so it is no longer in the "must be null for a pickup point" list. Its
     * SHAPE (required, exactly two uppercase ASCII letters, never normalized) is
     * checked by assertCountryShape(), called only from create() and
     * reviseDelivery() — NOT from here, because this method also runs from the
     * constructor, which reconstituteFromStorage() uses: a historical order whose
     * pickup point has a NULL country must still load. (A STREET_ADDRESS keeps
     * its old rule here: a non-empty country.)
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

            foreach (['pickupPointReference' => $pickupPointReference, 'settlement' => $settlement] as $name => $value) {
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
     * The delivery country, for EITHER type: required, exactly two uppercase
     * ASCII letters. Shape only — whether the code is a real country is the
     * HTTP layer's list (a domain package must not import app code) — and never
     * normalized: a lowercase "bg" is refused, not fixed.
     */
    private static function assertCountryShape(?string $country): void
    {
        if ($country === null || trim($country) === '') {
            throw new InvalidArgumentException('Order country must not be empty; it is required for every delivery type.');
        }

        if (preg_match('/^[A-Z]{2}$/D', $country) !== 1) {
            throw new InvalidArgumentException("Order country must be an uppercase ISO 3166-1 alpha-2 code (two capital letters), got \"{$country}\".");
        }
    }

    /**
     * total is ALWAYS subtotal - discount + shipping, computed here, never
     * accepted as a parameter — see this class's own docblock. $shipping
     * defaults to zero in the order's currency, so an order with no shipping
     * gets exactly the pre-shipping total. Note
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
        int $editRevision = 0,
        ?Money $shipping = null,
        ?string $shippingMethodName = null,
        ?string $shippingMethodCode = null,
        ?string $shippingCourier = null,
        ?string $shippingDeliveryType = null,
        ?string $shippingServiceCode = null,
        ?string $pickupPointName = null,
        ?string $pickupPointAddress = null,
    ): self {
        self::assertCountryShape($country);

        $normalizedCurrency = Currency::from($currency);

        // Validated explicitly here (with a clear, field-naming message)
        // before subtract() ever runs — Money::subtract() would also
        // reject a subtotal/discount currency mismatch, but only with
        // its own generic message; this keeps Order's error messages
        // consistent regardless of entry path.
        self::assertMoneyCurrency('subtotal', $subtotal, $normalizedCurrency);
        self::assertMoneyCurrency('discount', $discount, $normalizedCurrency);

        $shipping ??= Money::zero($normalizedCurrency);
        self::assertMoneyCurrency('shipping', $shipping, $normalizedCurrency);
        [$shippingMethodName, $shippingMethodCode] = self::normalizeShipping($shipping, $shippingMethodName, $shippingMethodCode);
        [$shippingCourier, $shippingDeliveryType, $shippingServiceCode] = self::normalizeShippingFacts($shippingMethodName, $shippingCourier, $shippingDeliveryType, $shippingServiceCode);

        $total = $subtotal->subtract($discount)->add($shipping);

        return new self(
            id: null,
            clientId: $clientId,
            accountId: $accountId,
            transactionId: $transactionId,
            email: $email,
            currency: $normalizedCurrency,
            subtotal: $subtotal,
            discount: $discount,
            shipping: $shipping,
            shippingMethodName: $shippingMethodName,
            shippingMethodCode: $shippingMethodCode,
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
            editRevision: $editRevision,
            shippingCourier: $shippingCourier,
            shippingDeliveryType: $shippingDeliveryType,
            shippingServiceCode: $shippingServiceCode,
            pickupPointName: self::trimmedOrNull($pickupPointName),
            pickupPointAddress: self::trimmedOrNull($pickupPointAddress),
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
        Money $shipping,
        ?string $shippingMethodName,
        ?string $shippingMethodCode,
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
        int $editRevision = 0,
        ?string $shippingCourier = null,
        ?string $shippingDeliveryType = null,
        ?string $shippingServiceCode = null,
        ?string $pickupPointName = null,
        ?string $pickupPointAddress = null,
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
            shipping: $shipping,
            shippingMethodName: $shippingMethodName,
            shippingMethodCode: $shippingMethodCode,
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
            editRevision: $editRevision,
            shippingCourier: $shippingCourier,
            shippingDeliveryType: $shippingDeliveryType,
            shippingServiceCode: $shippingServiceCode,
            pickupPointName: $pickupPointName,
            pickupPointAddress: $pickupPointAddress,
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

    /** Order-level shipping charge, snapshotted at placement; zero for an order with no shipping. */
    public function shipping(): Money
    {
        return $this->shipping;
    }

    public function shippingMethodName(): ?string
    {
        return $this->shippingMethodName;
    }

    public function shippingMethodCode(): ?string
    {
        return $this->shippingMethodCode;
    }

    /** The courier of the chosen method, snapshotted at placement (stage 4a); null when none. */
    public function shippingCourier(): ?string
    {
        return $this->shippingCourier;
    }

    /** address | office | locker | other, snapshotted at placement (stage 4a); null when none. */
    public function shippingDeliveryType(): ?string
    {
        return $this->shippingDeliveryType;
    }

    /** The carrier service the price was quoted for, snapshotted at placement (stage 4a); null for a local method. */
    public function shippingServiceCode(): ?string
    {
        return $this->shippingServiceCode;
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

    /**
     * order-editing-design.md §1 (E1) / §2, stage 2 — the shared guard
     * both edit mutators below run first. Not part of transitionTo()'s own
     * matrix: an edit is not a transition (§2's own "status itself is
     * untouched by either mutator"), so it lives here instead, as its own
     * narrow check.
     */
    private function assertEditable(): void
    {
        if (! in_array($this->status, [OrderStatus::PLACED, OrderStatus::CONFIRMED], true)) {
            throw OrderNotEditableException::because($this->status);
        }
    }

    /**
     * order-editing-design.md §2, stage 2 (D1) — one-shot, never partial:
     * subtotal, discount and the promo code move together, because there is
     * no such thing as editing just discount. It NO LONGER ACCEPTS A TOTAL
     * (shipping stage 2, shipping-domain-design.md §7.1): the total is
     * computed here as subtotal - discount + the order's STORED shipping, so
     * an edit can neither drop nor re-price shipping, and the caller can never
     * supply a total that disagrees with its parts — the same rule create()
     * has always applied. Trusts its caller (stage 3's OrderEditor) to have
     * computed subtotal and discount correctly from the order's real,
     * current lines.
     */
    public function reviseTotals(Money $subtotal, Money $discount, ?string $appliedPromotionCode): void
    {
        $this->assertEditable();

        $this->subtotal = $subtotal;
        $this->discount = $discount;
        $this->total = $subtotal->subtract($discount)->add($this->shipping);
        $this->appliedPromotionCode = $appliedPromotionCode;
    }

    /**
     * order-editing-design.md §2/§3, stage 2 (D2) — one-shot, atomic
     * replacement of the whole delivery snapshot, running the exact same
     * assertFieldsMatchDeliveryType() create() already runs (one
     * implementation, called from two places now). addressId is
     * DELIBERATELY NOT a parameter and is NEVER touched by this method —
     * it stays exactly what it was at placement: provenance ("which saved
     * address this order started from"), not a live pointer this method
     * re-targets on every edit.
     */
    public function reviseDelivery(
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
        ?string $pickupPointName = null,
        ?string $pickupPointAddress = null,
    ): void {
        $this->assertEditable();

        // The display snapshot (stage 4f) is not part of what an edit form carries. Replacing the delivery must not silently
        // wipe it, and must not leave it describing an office the order no longer goes to: it is KEPT while the order still
        // goes to the SAME office (a pickup point with the same carrier and reference) unless the caller supplies new text, and
        // it is cleared otherwise.
        $sameOffice = $deliveryType === OrderDeliveryType::PICKUP_POINT
            && $this->deliveryType === OrderDeliveryType::PICKUP_POINT
            && $carrierCode === $this->carrierCode
            && $pickupPointReference === $this->pickupPointReference;
        $pickupPointName = self::trimmedOrNull($pickupPointName) ?? ($sameOffice ? $this->pickupPointName : null);
        $pickupPointAddress = self::trimmedOrNull($pickupPointAddress) ?? ($sameOffice ? $this->pickupPointAddress : null);
        self::assertPickupDisplay($deliveryType, $pickupPointName, $pickupPointAddress);
        self::assertNotEmpty('recipientName', $recipientName);
        self::assertNotEmpty('phone', $phone);
        self::assertCountryShape($country);
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

        $this->deliveryType = $deliveryType;
        $this->recipientName = $recipientName;
        $this->phone = $phone;
        $this->country = $country;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->addressLine1 = $addressLine1;
        $this->addressLine2 = $addressLine2;
        $this->carrierCode = $carrierCode;
        $this->pickupPointReference = $pickupPointReference;
        $this->settlement = $settlement;
        $this->pickupPointName = $pickupPointName;
        $this->pickupPointAddress = $pickupPointAddress;
    }

    /**
     * order-editing-design.md §2.2 — how many successful edits this order
     * has had; 0 means never edited. The optimistic-concurrency token an
     * edit form carries (§5 step 3).
     */
    public function editRevision(): int
    {
        return $this->editRevision;
    }

    /**
     * Increments editRevision by exactly 1 — one call per successful edit.
     *
     * CARRIES NO STATUS GUARD OF ITS OWN, deliberately, rather than running
     * assertEditable() a third time: its only caller (stage 3b's
     * OrderEditor) calls it strictly AFTER reviseTotals()/reviseDelivery()
     * have already succeeded — both of which run assertEditable() — inside
     * the same locked transaction, so the guard has already been applied to
     * this very edit. Calling it on its own, for an order that was not just
     * edited, would let the counter drift from the truth; that ordering is
     * the caller's contract, stated here so it is not silently assumed.
     */
    public function bumpEditRevision(): void
    {
        $this->editRevision++;
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

    /** The office's name as the customer saw it at placement (stage 4f display snapshot); null for a street order or a historical one. */
    public function pickupPointName(): ?string
    {
        return $this->pickupPointName;
    }

    /** The office's address line as the customer saw it at placement (stage 4f display snapshot); null for a street order or a historical one. */
    public function pickupPointAddress(): ?string
    {
        return $this->pickupPointAddress;
    }
}
