<?php

namespace App\Services;

use App\Services\Exceptions\ShippingMethodInUseException;
use App\Services\Exceptions\ShippingMethodInvalidException;
use App\Services\Exceptions\ShippingMethodNotFoundException;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use EasyCo\Shipping\Exceptions\UnknownShippingClassException;
use EasyCo\Shipping\ShippingCode;
use EasyCo\Shipping\ShippingCourier;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Create / update / delete / (de)activate a shipping METHOD (shipping-domain-design.md §12.3.3, §12.6) — the only
 * way the admin writes one; no Filament form or toggle writes a shipping row itself. The twin of ShippingZoneWriter.
 * Each call:
 *
 *  1. VALIDATES — the bounds the domain does not know (name length and plain single-line text, money within
 *     MoneyInput's digit limit and in the store currency, a known class, no duplicate class row, a signed class
 *     amount only in ADJUST), then THROUGH THE ENTITY (ShippingMethod::create / update), whose Invalid… exception is
 *     the last word. Every refusal is a ShippingMethodInvalidException of TRANSLATED messages keyed by the form's
 *     field names; a raw message or a SQL error never reaches a screen. Nothing is written.
 *  2. SAVES through the repository and writes EXACTLY ONE ActivityLogger entry (`shipping_method`) in the same
 *     transaction: create -> logCreated; delete -> logDeleted with a JSON snapshot; update -> ONE logFieldChanged
 *     with the fixed field `method` and compact JSON before/after (the real signature takes a non-null field, so
 *     the design's `field = null` is not used — same as the zones); (de)activate -> logFieldChanged `active`.
 *     Field changes are written only while `admin.activity_log_enabled` is on; a delete is always written.
 *  3. FIRES ONE HOOK after the commit, never inside it: `shipping.method.created (ShippingMethod, ?string
 *     $copiedFrom)`, `.updated (ShippingMethod, array $before)`, `.deleted (array $snapshot)`, `.activated` /
 *     `.deactivated (ShippingMethod)`. A refusal or a failed write fires nothing and logs nothing.
 *
 * FIELDS THAT DO NOT BELONG TO THE KIND ARE IGNORED AND CLEARED (a FREE method has no price, a FLAT one no class
 * amounts, only PER_CLASS has a mode, only CARRIER a carrier code): changing the kind never fails for what the old
 * kind left behind. The form says so in a fact line.
 *
 * A method is created at the END of its zone's order (sortOrder = highest + 1); an update NEVER changes sortOrder —
 * only ShippingMethodReorderer does. A method is deleted freely: nothing references it by foreign key (a placed order
 * keeps its own snapshot), so there is no history to protect; a database refusal is still translated
 * (ShippingMethodInUseException, "deactivate it instead").
 *
 * NO CACHE IS FLUSHED (§12.6): the quote recomputes zone, methods and amounts on every call; a handle issued earlier
 * fails on the amount by design. No permission check here, like every app service: the screen is what is
 * authorized (shipping_manage).
 */
final class ShippingMethodWriter
{
    public const ENTITY = 'shipping_method';

    /** Class-amount rows per method: there cannot be more distinct classes than this, so the table stays bounded. */
    public const MAX_CLASS_ROWS = 500;

    public function __construct(
        private readonly ShippingMethodRepository $methods,
        private readonly ShippingZoneRepository $zones,
        private readonly ShippingClassRepository $classes,
        private readonly ActivityLogger $audit,
    ) {
    }

    /**
     * @throws ShippingMethodInvalidException
     * @throws ShippingMethodNotFoundException the zone is gone
     */
    public function create(string $zoneId, ShippingMethodInput $input): ShippingMethod
    {
        $clean = $this->clean($input);

        $method = DB::transaction(function () use ($zoneId, $clean): ShippingMethod {
            if ($this->zones->findById($zoneId) === null) {
                throw new ShippingMethodNotFoundException();
            }

            $next = 0;

            foreach ($this->methods->forZone($zoneId) as $existing) {
                $next = max($next, $existing->sortOrder() + 1);
            }

            $method = $this->guarded(fn (): ShippingMethod => ShippingMethod::create(
                $zoneId, $clean['name'], $clean['kind'], $next, $clean['active'], $clean['price'], $clean['rates'],
                $clean['free_above'], $clean['carrier_code'], $clean['pickup'], $clean['mode'], $clean['courier'], $clean['delivery_type'],
                self::scopeFromToggle($clean['pickup'], $clean['delivery_type'], null),
            ));

            $this->saveGuarded($method);
            $this->audit->logCreated(self::ENTITY, (string) $method->id());

            return $method;
        });

        Hook::fire('shipping.method.created', $method, null);

        return $method;
    }

    /**
     * NEVER changes sortOrder or the zone. An update that changes nothing writes nothing: no save, no audit entry, no hook.
     *
     * @throws ShippingMethodInvalidException
     * @throws ShippingMethodNotFoundException
     */
    public function update(string $id, ShippingMethodInput $input): ShippingMethod
    {
        $clean = $this->clean($input);

        $result = DB::transaction(function () use ($id, $clean): array {
            $method = $this->methods->findById($id) ?? throw new ShippingMethodNotFoundException();
            $before = self::snapshot($method);

            $this->guarded(fn () => $method->update(
                $clean['name'], $clean['kind'], $method->sortOrder(), $clean['active'], $clean['price'], $clean['rates'],
                $clean['free_above'], $clean['carrier_code'], $clean['pickup'], $clean['mode'], $clean['courier'], $clean['delivery_type'],
                self::scopeFromToggle($clean['pickup'], $clean['delivery_type'], $method->destinationScope()),
            ));

            if (self::snapshot($method) === $before) {
                return ['method' => $method, 'before' => null];
            }

            $this->saveGuarded($method);
            $this->audit->logFieldChanged(self::ENTITY, (string) $method->id(), 'method', self::json($before), self::json(self::snapshot($method)));

            return ['method' => $method, 'before' => $before];
        });

        if ($result['before'] !== null) {
            Hook::fire('shipping.method.updated', $result['method'], $result['before']);
        }

        return $result['method'];
    }

    /**
     * Switches a method on or off — the one write behind the list's toggle. Already in that state: nothing is written.
     *
     * @return bool whether anything changed
     *
     * @throws ShippingMethodNotFoundException
     */
    public function setActive(string $id, bool $active): bool
    {
        $result = DB::transaction(function () use ($id, $active): ?ShippingMethod {
            $method = $this->methods->findById($id) ?? throw new ShippingMethodNotFoundException();

            if ($method->isActive() === $active) {
                return null;
            }

            $method->update(
                $method->name(), $method->kind(), $method->sortOrder(), $active, $method->amountMinor(), $method->classRates(),
                $method->freeAboveMinor(), $method->carrierCode(), $method->requiresPickupPoint(), $method->classMode(),
                $method->courier(), $method->deliveryType(), $method->destinationScope(),
            );

            $this->saveGuarded($method);
            $this->audit->logFieldChanged(self::ENTITY, (string) $method->id(), 'active', $active ? 'false' : 'true', $active ? 'true' : 'false');

            return $method;
        });

        if ($result === null) {
            return false;
        }

        Hook::fire($active ? 'shipping.method.activated' : 'shipping.method.deactivated', $result);

        return true;
    }

    /**
     * @throws ShippingMethodNotFoundException
     * @throws ShippingMethodInUseException a database refusal (nothing references a method today)
     */
    public function delete(string $id): void
    {
        $snapshot = DB::transaction(function () use ($id): array {
            $method = $this->methods->findById($id) ?? throw new ShippingMethodNotFoundException();
            $snapshot = self::snapshot($method);

            try {
                $this->methods->delete($id);
            } catch (QueryException $exception) {
                if (($exception->errorInfo[0] ?? null) === '23000') {
                    throw new ShippingMethodInUseException($method->name());
                }

                throw $exception;
            }

            $this->audit->logDeleted(self::ENTITY, $id, $snapshot);

            return $snapshot;
        });

        Hook::fire('shipping.method.deleted', $snapshot);
    }

    /**
     * The compact, stable picture of a method that the audit entries and the hooks carry.
     *
     * @return array{id: ?string, zone_id: string, name: string, kind: string, sort_order: int, active: bool, price_minor: ?int, free_above_minor: ?int, class_mode: string, class_rates: array<string, int>, requires_pickup_point: bool, destination_scope: string, carrier_code: ?string, courier: ?string, delivery_type: ?string}
     */
    public static function snapshot(ShippingMethod $method): array
    {
        return [
            'id' => $method->id(),
            'zone_id' => $method->zoneId(),
            'name' => $method->name(),
            'kind' => $method->kind()->value,
            'sort_order' => $method->sortOrder(),
            'active' => $method->isActive(),
            'price_minor' => $method->amountMinor(),
            'free_above_minor' => $method->freeAboveMinor(),
            'class_mode' => $method->classMode()->value,
            'class_rates' => array_map('intval', $method->classRates()),
            'requires_pickup_point' => $method->requiresPickupPoint(),
            'destination_scope' => $method->destinationScope()->value,
            'carrier_code' => $method->carrierCode(),
            'courier' => $method->courier(),
            'delivery_type' => $method->deliveryType()?->value,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ---- validation ----------------------------------------------------------------------------------------

    /**
     * @return array{name: string, kind: ShippingMethodKind, active: bool, price: ?int, free_above: ?int, mode: ShippingClassMode, rates: array<string, int>, pickup: bool, carrier_code: ?string, courier: ?string, delivery_type: ?ShippingDeliveryType}
     */
    private function clean(ShippingMethodInput $input): array
    {
        $errors = [];
        $label = static fn (string $field): string => __('shipping.methods.fields.'.$field);

        // name
        $name = trim($input->name);

        if ($name === '') {
            $errors['name'][] = __('shipping.methods.errors.name_required');
        } elseif (mb_strlen($name) > ShippingMethod::NAME_MAX_LENGTH) {
            $errors['name'][] = __('shipping.methods.errors.too_long', ['max' => ShippingMethod::NAME_MAX_LENGTH]);
        } elseif (! self::isPlain($name)) {
            $errors['name'][] = __('validation.plain_text', ['attribute' => $label('name')]);
        }

        // kind
        $kind = ShippingMethodKind::tryFrom($input->kind);

        if ($kind === null) {
            $errors['kind'][] = __('shipping.methods.errors.kind_unknown');
            $kind = ShippingMethodKind::FLAT; // only so the rest can be checked; nothing is written when there are errors
        }

        // price — FLAT and PER_CLASS; ignored and cleared for any other kind
        $price = null;

        if (in_array($kind, [ShippingMethodKind::FLAT, ShippingMethodKind::PER_CLASS], true)) {
            if ($input->price === null) {
                $errors['price'][] = __('shipping.methods.errors.price_required');
            } elseif (($problem = self::moneyProblem($input->price, signed: false)) !== null) {
                $errors['price'][] = $problem;
            } else {
                $price = $input->price->minorValue();
            }
        }

        // free above — FLAT and PER_CLASS
        $freeAbove = null;

        if ($input->freeAbove !== null && in_array($kind, [ShippingMethodKind::FLAT, ShippingMethodKind::PER_CLASS], true)) {
            if (($problem = self::moneyProblem($input->freeAbove, signed: false)) !== null) {
                $errors['free_above'][] = $problem;
            } else {
                $freeAbove = $input->freeAbove->minorValue();
            }
        }

        // class mode and class amounts — PER_CLASS only
        $mode = ShippingClassMode::REPLACE;
        $rates = [];

        if ($kind === ShippingMethodKind::PER_CLASS) {
            $mode = ShippingClassMode::tryFrom($input->classMode) ?? null;

            if ($mode === null) {
                $errors['class_mode'][] = __('shipping.methods.errors.mode_unknown');
                $mode = ShippingClassMode::REPLACE;
            }

            $rates = $this->cleanRates($input->classRates, $mode, $errors);
        }

        // courier and delivery type — optional, allowed for every kind (stage 5f)
        $courier = null;

        if ($input->courier !== null && trim($input->courier) !== '') {
            $trimmed = trim($input->courier);

            if (mb_strlen($trimmed) > ShippingCourier::MAX_LENGTH) {
                $errors['courier'][] = __('shipping.methods.errors.too_long', ['max' => ShippingCourier::MAX_LENGTH]);
            } elseif (! self::isPlain($trimmed)) {
                $errors['courier'][] = __('validation.plain_text', ['attribute' => $label('courier')]);
            } else {
                $courier = $trimmed;
            }
        }

        $deliveryType = null;

        if ($input->deliveryType !== null && trim($input->deliveryType) !== '') {
            $deliveryType = ShippingDeliveryType::tryFrom(trim($input->deliveryType));

            if ($deliveryType === null) {
                $errors['delivery_type'][] = __('shipping.methods.errors.delivery_type_unknown');
            }
        }

        // carrier code — CARRIER only
        $carrierCode = null;

        if ($kind === ShippingMethodKind::CARRIER) {
            $code = $input->carrierCode === null ? '' : trim($input->carrierCode);

            if ($code === '') {
                $errors['carrier_code'][] = __('shipping.methods.errors.carrier_required');
            } elseif (! ShippingCode::isValid($code)) {
                $errors['carrier_code'][] = __('shipping.methods.errors.carrier_invalid');
            } else {
                $carrierCode = $code;
            }
        }

        if ($errors !== []) {
            throw new ShippingMethodInvalidException($errors);
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'active' => $input->active,
            'price' => $price,
            'free_above' => $freeAbove,
            'mode' => $mode,
            'rates' => $rates,
            'pickup' => $input->requiresPickupPoint,
            'carrier_code' => $carrierCode,
            'courier' => $courier,
            'delivery_type' => $deliveryType,
        ];
    }

    /**
     * @param  array<int, array{class: mixed, amount: mixed}>  $rows
     * @param  array<string, list<string>>  $errors
     * @return array<string, int> class code => minor units
     */
    private function cleanRates(array $rows, ShippingClassMode $mode, array &$errors): array
    {
        if (count($rows) > self::MAX_CLASS_ROWS) {
            $errors['class_rates'][] = __('shipping.methods.errors.too_many', ['max' => self::MAX_CLASS_ROWS]);

            return [];
        }

        $known = null;
        $rates = [];

        foreach (array_values($rows) as $row) {
            $code = is_array($row) ? ($row['class'] ?? null) : null;
            $amount = is_array($row) ? ($row['amount'] ?? null) : null;

            if (! is_string($code) || ! ShippingCode::isValid($code)) {
                $errors['class_rates'][] = __('shipping.methods.errors.class_unknown', ['class' => self::shown($code)]);

                continue;
            }

            $known ??= array_map(static fn ($class): string => (string) $class->code(), $this->classes->all());

            if (! in_array($code, $known, true)) {
                $errors['class_rates'][] = __('shipping.methods.errors.class_unknown', ['class' => self::shown($code)]);

                continue;
            }

            if (array_key_exists($code, $rates)) {
                $errors['class_rates'][] = __('shipping.methods.errors.class_duplicate', ['class' => $code]);

                continue;
            }

            if (! $amount instanceof Money) {
                $errors['class_rates'][] = __('shipping.methods.errors.amount_invalid');

                continue;
            }

            $problem = self::moneyProblem($amount, signed: $mode === ShippingClassMode::ADJUST);

            if ($problem !== null) {
                $errors['class_rates'][] = $problem;

                continue;
            }

            $rates[$code] = $amount->minorValue();
        }

        return $rates;
    }

    /** null when the amount is fine: the store currency, within MoneyInput's digit limit, non-negative unless signed. */
    private static function moneyProblem(Money $money, bool $signed): ?string
    {
        if (! $money->currency()->equals(DefaultCurrency::get())) {
            return __('shipping.methods.errors.currency', ['currency' => DefaultCurrency::get()->code()]);
        }

        if (abs($money->minorValue()) >= 10 ** (MoneyInput::MAX_INTEGER_DIGITS + $money->currency()->decimalPlaces())) {
            return __('shipping.methods.errors.amount_too_large', ['digits' => MoneyInput::MAX_INTEGER_DIGITS]);
        }

        if (! $signed && $money->isNegative()) {
            return __('shipping.methods.errors.amount_negative');
        }

        return null;
    }

    /**
     * The entity's Invalid… exception becomes a translated field error (its English message is never shown).
     * The pre-checks above make this a backstop.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T
     */
    private function guarded(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (InvalidShippingMethodException $exception) {
            $message = $exception->getMessage();

            $field = match (true) {
                str_contains($message, 'class') && str_contains($message, 'mode') => 'class_mode',
                str_contains($message, 'class') => 'class_rates',
                str_contains($message, 'destination scope') => 'delivery_type',
                str_contains($message, 'courier') => 'courier',
                str_contains($message, 'carrierCode') => 'carrier_code',
                str_contains($message, 'freeAbove') => 'free_above',
                str_contains($message, 'amount') => 'price',
                default => 'name',
            };

            throw new ShippingMethodInvalidException([$field => [__('shipping.methods.errors.invalid')]]);
        }
    }

    /**
     * The scope the OLD form's single toggle means (shipping stage 6a; the form offers only that toggle until stage 6d), chosen so
     * that saving a form can never SILENTLY narrow a method:
     *  - toggle ON -> PICKUP (the admin explicitly asked for pickup-only);
     *  - toggle OFF with the delivery type "address" -> ADDRESS (the label itself says address-only);
     *  - toggle OFF on an existing method whose scope is not pickup -> its own scope is kept, so an `any` method saved from this
     *    form without touching the toggle stays `any` (the form shows the toggle off for both address and any, so "off" cannot
     *    tell them apart);
     *  - otherwise (a new method) -> ADDRESS, exactly what an unticked box has always meant.
     * An office or locker label with the toggle off is left as ADDRESS on purpose: the entity refuses that pair loudly instead of
     * the writer guessing which of the two the admin meant.
     */
    private static function scopeFromToggle(bool $pickup, ?ShippingDeliveryType $deliveryType, ?ShippingDestinationScope $existing): ShippingDestinationScope
    {
        if ($pickup) {
            return ShippingDestinationScope::PICKUP;
        }

        if ($deliveryType === ShippingDeliveryType::ADDRESS) {
            return ShippingDestinationScope::ADDRESS;
        }

        return $existing !== null && $existing !== ShippingDestinationScope::PICKUP ? $existing : ShippingDestinationScope::ADDRESS;
    }

    /** Saves through the repository; a class removed meanwhile is the same translated error as an unknown one. */
    private function saveGuarded(ShippingMethod $method): void
    {
        try {
            $this->methods->save($method);
        } catch (UnknownShippingClassException) {
            throw new ShippingMethodInvalidException(['class_rates' => [__('shipping.methods.errors.class_unknown', ['class' => ''])]]);
        }
    }

    /** A single line of plain text: no control characters, no bidirectional override (the project's PlainText rule). */
    private static function isPlain(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) === 0;
    }

    private static function shown(mixed $value): string
    {
        return is_string($value) ? mb_strimwidth($value, 0, 40, '…') : get_debug_type($value);
    }
}
