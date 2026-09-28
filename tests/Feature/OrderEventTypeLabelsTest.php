<?php

namespace Tests\Feature;

use App\Enums\OrderEventType;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * order-lifecycle-design.md §10 stage 2 (the event-type half), in the app layer —
 * the only layer that sees both App\Enums\OrderEventType and lang/*\/orders.php.
 *
 * WHY THIS FILE EXISTS: the same reason OrderStatusLabelsTest exists. Every
 * label lookup for these values goes through
 * `OrderResource::optionLabel('event_type', …)` (`:448-457`), which prints the
 * raw snake_case value rather than failing when a key is missing — so a seventh
 * event type, a rename, or a one-language-only label would silently ship as
 * `payment_voided` on a merchant's timeline. This pins all three.
 */
class OrderEventTypeLabelsTest extends TestCase
{
    private const LOCALES = ['en', 'bg'];

    private const GROUP = 'orders.event_type_options';

    public function test_every_event_type_has_a_non_empty_label_in_every_language(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach (OrderEventType::cases() as $type) {
                $key = self::GROUP.".{$type->value}";

                $this->assertTrue(
                    Lang::has($key, $locale, false),
                    "lang/{$locale}/orders.php must carry an event_type_options.{$type->value} entry."
                );

                $label = Lang::get($key, [], $locale, false);

                $this->assertIsString($label, "lang/{$locale}/orders.php's {$key} must be a plain string.");
                $this->assertNotSame('', trim($label), "lang/{$locale}/orders.php's {$key} must not be blank.");
            }
        }
    }

    /**
     * The exact key set, in enum order, per language — the group holds one label
     * per type and nothing else, so it cannot drift from the enum in either
     * direction. `$fallback = false` again: a key present only in `lang/en` is
     * NOT a translated label.
     */
    public function test_the_event_type_option_groups_hold_exactly_the_enum_values(): void
    {
        $expected = array_map(static fn (OrderEventType $type): string => $type->value, OrderEventType::cases());

        foreach (self::LOCALES as $locale) {
            $this->assertSame(
                $expected,
                array_keys(Lang::get(self::GROUP, [], $locale, false)),
                "lang/{$locale}/orders.php's event_type_options must list exactly OrderEventType's values, in enum order."
            );
        }
    }
}
