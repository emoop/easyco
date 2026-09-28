<?php

namespace Tests\Feature;

use EasyCo\Order\Enums\OrderStatus;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §10 stage 2, in the app layer — this is the only
 * layer that can see both EasyCo\Order's enum and lang/*\/orders.php at once.
 *
 * WHY THIS FILE EXISTS: `OrderResource::optionLabel('status', …)` (`:448-457`)
 * prints a value's raw snake_case when its key is missing and the View page's
 * own badge does the same through `__("orders.status_options.{$state}")`
 * (`:253`) — Laravel returns the translation KEY when no entry exists. A status
 * whose label is missing therefore renders as `refunded` on a merchant's screen
 * and NOTHING goes red. This test is the pin that makes three separate failures
 * impossible: a new enum case without a label, a renamed case whose label stayed
 * behind, and a label dropped from one language only.
 *
 * `Lang::has()`/`get()` are called with `$fallback = false` throughout (third/
 * fourth argument — confirmed against the installed
 * Illuminate\Translation\Translator: with fallback enabled a key missing from
 * `lang/bg` would be "found" in `lang/en`) — "translated in both languages" is
 * only true with the fallback off.
 */
class OrderStatusLabelsTest extends TestCase
{
    private const LOCALES = ['en', 'bg'];

    public function test_every_order_status_has_a_non_empty_label_in_every_language(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (OrderStatus::cases() as $status) {
                $key = "orders.status_options.{$status->value}";

                $this->assertTrue(
                    Lang::has($key, $locale, false),
                    "lang/{$locale}/orders.php must carry a status_options.{$status->value} entry."
                );

                $label = Lang::get($key, [], $locale, false);

                $this->assertIsString($label, "lang/{$locale}/orders.php's {$key} must be a plain string.");
                $this->assertNotSame('', trim($label), "lang/{$locale}/orders.php's {$key} must not be blank.");
            }
        }
    }

    /**
     * The exact key set, in enum order, per language: no value missing, no value
     * added without an enum case, and no retired value left behind as a dead key
     * (the enum cannot produce `fulfilled` any more — §10 stage 1).
     */
    public function test_the_status_option_groups_hold_exactly_the_enum_values(): void
    {
        $expected = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());

        foreach (self::LOCALES as $locale) {
            $this->assertSame(
                $expected,
                array_keys(Lang::get('orders.status_options', [], $locale, false)),
                "lang/{$locale}/orders.php's status_options must list exactly OrderStatus's values, in enum order."
            );
        }
    }

    public function test_the_retired_fulfilled_key_is_gone_from_both_languages(): void
    {
        foreach (self::LOCALES as $locale) {
            $this->assertFalse(
                Lang::has('orders.status_options.fulfilled', $locale, false),
                "lang/{$locale}/orders.php must not keep a status_options.fulfilled key — no OrderStatus case produces that value any more."
            );
        }
    }

    /**
     * The inverse of the parity rule, and the reason it is worth a test of its
     * own: every key in both languages must be one the enum can actually produce,
     * so a typo (`shipping`) or a stale copy of the old file fails here instead of
     * rendering as an unexplained missing badge.
     */
    public function test_neither_status_option_group_carries_a_key_no_enum_case_produces(): void
    {
        $values = array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::cases());

        foreach (self::LOCALES as $locale) {
            foreach (array_keys(Lang::get('orders.status_options', [], $locale, false)) as $key) {
                $this->assertContains(
                    $key,
                    $values,
                    "lang/{$locale}/orders.php's status_options.{$key} has no matching OrderStatus case."
                );
            }
        }
    }
}
