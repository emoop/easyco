<?php

namespace Tests\Feature;

use App\Services\FreeShippingHint;
use App\Services\FreeShippingHintReader;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Rating\MethodRate;
use EasyCo\Shipping\ShippingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The FreeShippingHintReader (shipping stage 3e, §5.1): pick the ONE method the
 * sentence talks about, and write it — smallest remaining first, then the lowest
 * threshold among already-unlocked methods, ties broken by sortOrder then id.
 * The reader only reads the methods, rates, formatter and store locale it is
 * given; these tests hand it exactly those.
 */
final class FreeShippingHintReaderTest extends TestCase
{
    use RefreshDatabase;

    private function method(string $id, int $sortOrder, int $freeAbove, bool $active = true, string $name = 'Method', ShippingMethodKind $kind = ShippingMethodKind::FLAT): ShippingMethod
    {
        $amount = $kind === ShippingMethodKind::CARRIER ? null : 500;
        $carrier = $kind === ShippingMethodKind::CARRIER ? 'econt' : null;

        return ShippingMethod::reconstituteFromStorage(
            $id, '1', $name, $kind, $sortOrder, $active, $amount, [], $freeAbove, $carrier, false,
        );
    }

    private function flatRate(string $methodId, int $freeAbove, int $remaining): MethodRate
    {
        return MethodRate::priced($methodId, 'EUR', 500, $freeAbove, $remaining);
    }

    /** @param list<ShippingMethod> $methods @param list<MethodRate> $rates */
    private function read(array $methods, array $rates, string $locale = 'en'): ?FreeShippingHint
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);

        return app(FreeShippingHintReader::class)->read($methods, $rates, 'EUR');
    }

    public function test_the_smallest_remaining_wins(): void
    {
        $hint = $this->read(
            [$this->method('1', 0, 20000, name: 'Slow'), $this->method('2', 1, 10000, name: 'Fast')],
            [$this->flatRate('1', 20000, 3000), $this->flatRate('2', 10000, 5000)],
        );

        $this->assertNotNull($hint);
        $this->assertSame(FreeShippingHint::REMAINING, $hint->state);
        $this->assertSame('1', $hint->methodId, '3000 remaining beats 5000');
        $this->assertSame('Slow', $hint->methodName);
        $this->assertSame(3000, $hint->remainingMinor);
    }

    public function test_a_tie_is_broken_by_sort_order_then_id_numerically(): void
    {
        // Equal remaining: the lower sortOrder wins.
        $bySort = $this->read(
            [$this->method('10', 5, 10000, name: 'Later'), $this->method('2', 1, 10000, name: 'Earlier')],
            [$this->flatRate('10', 10000, 4000), $this->flatRate('2', 10000, 4000)],
        );
        $this->assertSame('2', $bySort->methodId, 'sortOrder 1 before 5');

        // Equal remaining AND sortOrder: 2 wins over 10 (numeric, not string).
        $byId = $this->read(
            [$this->method('10', 0, 10000, name: 'Ten'), $this->method('2', 0, 10000, name: 'Two')],
            [$this->flatRate('10', 10000, 4000), $this->flatRate('2', 10000, 4000)],
        );
        $this->assertSame('2', $byId->methodId, '2 before 10');
    }

    public function test_an_unlocked_method_is_chosen_only_when_none_is_still_positive(): void
    {
        $hint = $this->read(
            [$this->method('1', 0, 20000, name: 'High'), $this->method('2', 1, 10000, name: 'Low')],
            [$this->flatRate('1', 20000, 0), $this->flatRate('2', 10000, 0)],
        );

        $this->assertNotNull($hint);
        $this->assertSame(FreeShippingHint::UNLOCKED, $hint->state);
        $this->assertSame('2', $hint->methodId, 'the lowest threshold');
        $this->assertSame(10000, $hint->freeAboveMinor);
        $this->assertSame(0, $hint->remainingMinor);
    }

    public function test_a_positive_remaining_beats_an_unlocked_method(): void
    {
        $hint = $this->read(
            [$this->method('1', 0, 10000, name: 'Unlocked'), $this->method('2', 1, 20000, name: 'Short')],
            [$this->flatRate('1', 10000, 0), $this->flatRate('2', 20000, 500)],
        );

        $this->assertSame(FreeShippingHint::REMAINING, $hint->state);
        $this->assertSame('2', $hint->methodId);
    }

    public function test_inactive_and_carrier_methods_are_ignored(): void
    {
        $hint = $this->read(
            [
                $this->method('1', 0, 10000, active: false, name: 'Off'),
                $this->method('2', 1, 10000, name: 'Real'),
                $this->method('3', 2, 10000, name: 'Courier', kind: ShippingMethodKind::CARRIER),
            ],
            [
                $this->flatRate('1', 10000, 100),
                $this->flatRate('2', 10000, 4000),
                MethodRate::needsCarrierQuote('3', 'EUR', 'econt'),
            ],
        );

        $this->assertNotNull($hint);
        $this->assertSame('2', $hint->methodId, 'inactive and carrier are skipped');
        $this->assertSame(4000, $hint->remainingMinor);
    }

    public function test_nothing_qualifies_yields_null(): void
    {
        $this->assertNull($this->read([], []));
        $this->assertNull($this->read(
            [$this->method('1', 0, 10000)],
            [MethodRate::priced('1', 'EUR', 500)], // no threshold facts
        ));
        $this->assertNull($this->read(
            [$this->method('1', 0, 10000, kind: ShippingMethodKind::CARRIER)],
            [MethodRate::needsCarrierQuote('1', 'EUR', 'econt')],
        ));
    }

    public function test_the_sentence_is_translated_in_the_store_locale_with_the_amount_formatted(): void
    {
        $methods = [$this->method('1', 0, 10000, name: 'Econt office')];
        $rates = [$this->flatRate('1', 10000, 2350)];

        $en = $this->read($methods, $rates, 'en');
        $this->assertNotNull($en);
        $this->assertSame('Add 23.50 € more for free shipping with “Econt office”.', $en->text);

        $bg = $this->read($methods, $rates, 'bg');
        $this->assertNotNull($bg);
        $this->assertSame('Добавете още 23.50 € за безплатна доставка с „Econt office“.', $bg->text);
    }

    public function test_the_unlocked_sentence_names_the_method_without_an_amount(): void
    {
        $hint = $this->read([$this->method('1', 0, 10000, name: 'Econt office')], [$this->flatRate('1', 10000, 0)]);

        $this->assertSame('Shipping with “Econt office” is free.', $hint->text);
    }

    public function test_the_two_locales_carry_the_same_placeholders(): void
    {
        foreach (['en', 'bg'] as $locale) {
            $lines = require base_path("lang/{$locale}/shipping_hint.php");

            $this->assertStringContainsString(':amount', $lines['remaining'], "{$locale}.remaining must carry :amount");
            $this->assertStringContainsString(':method', $lines['remaining'], "{$locale}.remaining must carry :method");
            $this->assertStringContainsString(':method', $lines['unlocked'], "{$locale}.unlocked must carry :method");
            $this->assertStringNotContainsString(':amount', $lines['unlocked'], "{$locale}.unlocked takes no amount");
        }
    }
}
