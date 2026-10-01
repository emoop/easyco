<?php

namespace App\Services;

use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * order-editing-design.md §8 (D7) — turns the order edit dialog's submitted
 * form state into OrderEditor::apply()'s own parameter shapes. Pure
 * functions, no Filament class and no I/O, so each mapping rule is tested
 * directly instead of only through a mounted action.
 *
 * TWO HALVES, ONE DISCIPLINE (stage 4b-ii): lineChanges() reads the current
 * lines' own table, and addLineRequest() reads the "add a product" section
 * below it. Neither trusts anything about a line that the line itself
 * knows — a submission is only ever read for WHAT IT ASKS FOR (an id and a
 * number), never for what a row claims a line currently is. The add
 * section's own pricing happens outside this class, in
 * OrderAddLinePricer, for the reason that class's docblock gives.
 *
 * THE DIALOG'S LINE TABLE IS A Filament Repeater, WHOSE ITEM STATE IS KEYED BY
 * AN AUTO-GENERATED ITEM KEY, NOT BY THE LINE'S ID — so each row carries the
 * line's real id in a hidden `line_id` field, and lineChanges() reads THAT,
 * never the item key. Everything else a row says about a line (its current
 * quantity and discount) is re-read from the line itself, never trusted from
 * the submission: only `line_id`, `quantity` and `discount` are ever taken.
 */
final class OrderEditFormMapper
{
    /** The eleven delivery fields, in Order::reviseDelivery()'s own order. */
    public const DELIVERY_FIELDS = [
        'delivery_type', 'recipient_name', 'phone', 'country', 'city', 'postal_code',
        'address_line_1', 'address_line_2', 'carrier_code', 'pickup_point_reference', 'settlement',
    ];

    /**
     * The promotion field's three states (never a bare nullable string):
     *
     *  - the removal toggle checked -> removed(), whatever the text says;
     *  - a blank text, or text equal to the order's current code (compared
     *    case-insensitively, codes being stored normalised) -> unchanged().
     *    A BLANK FIELD NEVER REMOVES THE CODE — clearing the text is too easy
     *    to do by accident; removal is the toggle's explicit job;
     *  - any other text -> set(text), trimmed.
     */
    public static function promotionCodeChange(?string $currentCode, ?string $typedCode, bool $remove): OrderPromotionCodeChange
    {
        if ($remove) {
            return OrderPromotionCodeChange::removed();
        }

        $typed = trim((string) $typedCode);

        if ($typed === '') {
            return OrderPromotionCodeChange::unchanged();
        }

        if ($currentCode !== null && strcasecmp($typed, trim($currentCode)) === 0) {
            return OrderPromotionCodeChange::unchanged();
        }

        return OrderPromotionCodeChange::set($typed);
    }

    /**
     * Null when the submitted delivery snapshot is identical to the current
     * one (blank and null are the same fact, so a blank optional field does
     * not read as a change) — "delivery unchanged by this edit". Otherwise
     * the whole replacement snapshot: reviseDelivery() always replaces it
     * atomically and re-validates it, so nothing partial is ever built.
     *
     * A field absent from $submitted (a hidden, type-irrelevant one) is null.
     *
     * @param  array<string, ?string>  $current  keyed by DELIVERY_FIELDS
     * @param  array<string, mixed>  $submitted
     */
    public static function deliveryChange(array $current, array $submitted): ?OrderDeliveryChange
    {
        $now = self::normalise($current);
        $next = self::normalise($submitted);

        $next['delivery_type'] ??= $now['delivery_type'];

        if ($now === $next) {
            return null;
        }

        return new OrderDeliveryChange(
            deliveryType: OrderDeliveryType::from((string) $next['delivery_type']),
            recipientName: (string) $next['recipient_name'],
            phone: (string) $next['phone'],
            country: $next['country'],
            city: $next['city'],
            postalCode: $next['postal_code'],
            addressLine1: $next['address_line_1'],
            addressLine2: $next['address_line_2'],
            carrierCode: $next['carrier_code'],
            pickupPointReference: $next['pickup_point_reference'],
            settlement: $next['settlement'],
        );
    }

    /**
     * OrderLineEditor's change entries for the submitted rows. A row with
     * nothing changed contributes nothing.
     *
     *  - quantity 0 -> one "remove" (a discount on the same row is moot);
     *  - quantity below the current one -> one "change_quantity";
     *  - quantity ABOVE the current one is refused: raising a quantity is the
     *    add-a-line dialog's business, never this table's (the field's own
     *    maxValue refuses it first; this is the same rule enforced where the
     *    submission is read, since a form value is only a request);
     *  - a discretionary discount that differs from the line's own -> one
     *    "discount". ONE ENTRY CANNOT CARRY BOTH A QUANTITY AND A DISCOUNT —
     *    OrderLineEditor's entries are per-intent, and it legally accepts
     *    change_quantity + discount for one line as TWO entries which it
     *    merges into a single reversal + replacement (its plan() says so) —
     *    so a row that changes both yields exactly those two entries.
     *  - $mayDiscount false (no ORDER_DISCOUNT): the discount is never read,
     *    whatever the submission carries.
     *
     * @param  array<int|string, mixed>  $rows
     * @param  array<string, SaleLine>  $currentLinesById
     * @return array<int, array<string, mixed>>
     */
    public static function lineChanges(array $rows, array $currentLinesById, bool $mayDiscount, Currency|string $currency): array
    {
        $changes = [];
        $seen = [];

        foreach ($rows as $row) {
            $id = (string) ($row['line_id'] ?? '');

            if (! isset($currentLinesById[$id])) {
                throw new InvalidArgumentException("OrderEditFormMapper: row names line \"{$id}\", which is not a current line of the order.");
            }

            if (isset($seen[$id])) {
                throw new InvalidArgumentException("OrderEditFormMapper: line \"{$id}\" appears in more than one row.");
            }

            $seen[$id] = true;
            $line = $currentLinesById[$id];
            $quantity = self::integerOrNull($row['quantity'] ?? null, "line \"{$id}\"") ?? $line->quantity();

            if ($quantity < 0) {
                throw new InvalidArgumentException("OrderEditFormMapper: line \"{$id}\" has a negative quantity.");
            }

            if ($quantity > $line->quantity()) {
                throw new InvalidArgumentException("OrderEditFormMapper: line \"{$id}\" cannot be raised from {$line->quantity()} to {$quantity} here.");
            }

            if ($quantity === 0) {
                $changes[] = ['change' => 'remove', 'originatingLine' => $line];

                continue;
            }

            if ($quantity < $line->quantity()) {
                $changes[] = ['change' => 'change_quantity', 'originatingLine' => $line, 'quantity' => $quantity];
            }

            if ($mayDiscount && trim((string) ($row['discount'] ?? '')) !== '') {
                $discount = Money::fromDecimal((string) $row['discount'], $currency);
                $current = $line->discretionaryDiscount() ?? Money::zero($currency);

                if (! $discount->equals($current)) {
                    $changes[] = ['change' => 'discount', 'originatingLine' => $line, 'discretionaryDiscount' => $discount];
                }
            }
        }

        return $changes;
    }

    /**
     * The dialog's own "add a product" section, mapped exactly as
     * rigorously as the line table above (stage 4b-ii, D2/D4) — the
     * submitted (variation, quantity) pair as an ADD REQUEST, or null when
     * the section was left alone.
     *
     * NOT A CHANGE ENTRY YET, deliberately: an OrderLineEditor "add" entry
     * also carries the new line's own §3.13 snapshot (regular/final unit
     * price, cost, product name, sku), which is priced LIVE by
     * OrderAddLinePricer — I/O this class has none of and wants none of.
     * What is decided here is only what the submission itself SAYS.
     *
     *  - no variation id — the section untouched, or cleared — is null:
     *    nothing picked is nothing to do, never a refusal. The quantity
     *    field is seeded with 1 (so the form can submit it at all), which
     *    is exactly why the VARIATION is the signal, not the quantity;
     *  - a variation id with a whole quantity of at least 1 is that
     *    request. A missing, blank, fractional, zero or negative quantity
     *    is refused: the form's own minValue(1) refuses it first, and
     *    OrderLineEditor::apply() refuses a sub-1 "add" quantity as well,
     *    but a form value is only a request — the same posture
     *    lineChanges() takes for a raised quantity.
     *
     * @param  array<string, mixed>  $submitted  the 'add' section's own state
     * @return array{variationId: string, quantity: int}|null
     */
    public static function addLineRequest(array $submitted): ?array
    {
        $variationId = $submitted['variation_id'] ?? null;

        // A Select's own state can arrive as a string OR an int — Filament's
        // option state cast, Livewire's own dehydration and PHP's numeric
        // array keys all get a say — so the id is normalised rather than
        // tested for one shape.
        if (is_int($variationId)) {
            $variationId = (string) $variationId;
        }

        if (! is_string($variationId) || trim($variationId) === '') {
            return null;
        }

        $quantity = self::integerOrNull($submitted['quantity'] ?? null, 'the add-a-product section');

        if ($quantity === null || $quantity < 1) {
            throw new InvalidArgumentException(
                'OrderEditFormMapper: the add-a-product section names variation "'.trim($variationId).
                '" with a quantity of '.var_export($submitted['quantity'] ?? null, true).
                ' — adding a line takes a whole quantity of at least 1.'
            );
        }

        return ['variationId' => trim($variationId), 'quantity' => $quantity];
    }

    /** @param array<string, mixed> $values */
    private static function normalise(array $values): array
    {
        $out = [];

        foreach (self::DELIVERY_FIELDS as $field) {
            $value = $values[$field] ?? null;
            $value = $value === null ? null : trim((string) $value);
            $out[$field] = $value === '' ? null : $value;
        }

        return $out;
    }

    /**
     * @param  string  $label  what the caller calls the thing whose quantity
     *                         this is — a line id, or the add section itself — so every refusal
     *                         names the right subject.
     */
    private static function integerOrNull(mixed $value, string $label): ?int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new InvalidArgumentException("OrderEditFormMapper: {$label} has a quantity that is not a whole number.");
    }
}
