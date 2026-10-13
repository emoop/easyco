<?php

namespace Tests\Feature;

use App\Services\ShippingMethodSummaryReader;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingDestinationScope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingClass;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Shipping\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * The one-sentence method summary (shipping-domain-design.md §12.4): built from the
 * domain getters and the class NAMES, money formatted by the project's formatter.
 * The ADJUST class mode does not exist yet (stage 5d) and is not represented.
 */
class ShippingMethodSummaryReaderTest extends TestCase
{
    use RefreshDatabase;

    private function reader(): ShippingMethodSummaryReader
    {
        return app(ShippingMethodSummaryReader::class);
    }

    private function zoneId(): string
    {
        $zone = ShippingZone::create('Zone', 0, ['BG']);
        app(ShippingZoneRepository::class)->save($zone);

        return (string) $zone->id();
    }

    private function classNamed(string $code, string $name): void
    {
        app(ShippingClassRepository::class)->save(ShippingClass::create($name, $code));
    }

    /** @param array<string, int> $rates */
    private function method(string $zoneId, ShippingMethodKind $kind, string $name = 'M', ?int $amount = 500, array $rates = [], ?int $freeAbove = null, ?string $carrier = null, bool $pickup = false, bool $active = true): ShippingMethod
    {
        return ShippingMethod::create($zoneId, $name, $kind, 0, $active, $amount, $rates, $freeAbove, $carrier, $pickup);
    }

    public function test_a_flat_method_is_just_its_price_and_its_scope_in_both_languages(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FLAT);

        App::setLocale('en');
        $this->assertSame('5.00 €; Address only', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; Само адрес', $this->reader()->summary($method));
    }

    public function test_a_flat_method_with_a_threshold_appends_the_free_from_clause(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FLAT, freeAbove: 10000);

        App::setLocale('en');
        $this->assertSame('5.00 €; free from 100.00 €; Address only', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; безплатна над 100.00 €; Само адрес', $this->reader()->summary($method));
    }

    public function test_a_free_method_reads_free_in_both_languages(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FREE, amount: null);

        App::setLocale('en');
        $this->assertSame('Free; Address only', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('Безплатна; Само адрес', $this->reader()->summary($method));
    }

    public function test_a_per_class_method_shows_the_base_then_the_class_names_then_the_threshold(): void
    {
        $this->classNamed('heavy', 'Heavy');
        $method = $this->method($this->zoneId(), ShippingMethodKind::PER_CLASS, rates: ['heavy' => 3000], freeAbove: 10000);

        App::setLocale('en');
        $this->assertSame('5.00 €; Heavy 30.00 €; free from 100.00 €; Address only', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; Heavy 30.00 €; безплатна над 100.00 €; Само адрес', $this->reader()->summary($method));
    }

    public function test_a_per_class_rate_for_an_unknown_code_falls_back_to_the_code(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::PER_CLASS, rates: ['ghost' => 700]);

        App::setLocale('en');
        $this->assertSame('5.00 €; ghost 7.00 €; Address only', $this->reader()->summary($method));
    }

    public function test_a_carrier_method_names_the_carrier_as_not_configured(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::CARRIER, amount: null, carrier: 'econt');

        App::setLocale('en');
        $this->assertSame('Carrier: econt · not configured; Address only', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('Куриер: econt · не е настроен; Само адрес', $this->reader()->summary($method));
    }

    public function test_the_scope_and_an_inactive_method_append_their_facts(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FLAT, pickup: true, active: false);

        App::setLocale('en');
        $this->assertSame('5.00 €; Pickup point only; inactive', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; Само офис или автомат; неактивен', $this->reader()->summary($method));
    }

    public function test_the_sentence_changes_when_the_data_changes(): void
    {
        $zone = $this->zoneId();
        $method = $this->method($zone, ShippingMethodKind::FLAT);

        App::setLocale('en');
        $this->assertSame('5.00 €; Address only', $this->reader()->summary($method));

        $changed = $this->method($zone, ShippingMethodKind::FLAT, amount: 1200);

        $this->assertSame('12.00 €; Address only', $this->reader()->summary($changed));
    }

    // ---- the free set of the threshold (stage 5g-3, design 9.3.8) -------------------------------------------

    /** A FLAT method with an explicit scope, label and free set. */
    private function freeSetMethod(string $zoneId, ?int $freeAbove, array $free, ?ShippingDeliveryType $label = null, ShippingDestinationScope $scope = ShippingDestinationScope::ANY): ShippingMethod
    {
        return ShippingMethod::create($zoneId, 'M', ShippingMethodKind::FLAT, 0, true, 500, [], $freeAbove, null, false, ShippingClassMode::REPLACE, null, $label, $scope, $free);
    }

    public function test_the_threshold_names_the_kinds_it_covers_when_it_does_not_cover_every_served_kind(): void
    {
        $zone = $this->zoneId();
        $partial = $this->freeSetMethod($zone, 10000, [ShippingDeliveryType::OFFICE, ShippingDeliveryType::LOCKER]);

        App::setLocale('en');
        $this->assertSame('5.00 €; free from 100.00 € (office, locker); Serves any destination', $this->reader()->summary($partial));

        App::setLocale('bg');
        $this->assertSame('5.00 €; безплатна над 100.00 € (офис, автомат); Обслужва и адрес, и офис/автомат', $this->reader()->summary($partial));
    }

    public function test_the_threshold_sentence_is_exactly_as_before_when_every_served_kind_is_free(): void
    {
        $zone = $this->zoneId();

        // `any` with all three kinds free...
        $all = $this->freeSetMethod($zone, 10000, ShippingDeliveryType::destinationKinds());

        App::setLocale('en');
        $this->assertSame('5.00 €; free from 100.00 €; Serves any destination', $this->reader()->summary($all));

        App::setLocale('bg');
        $this->assertSame('5.00 €; безплатна над 100.00 €; Обслужва и адрес, и офис/автомат', $this->reader()->summary($all));

        // ...and a one-kind method whose only kind is free: "every served kind" again, so no kinds are named.
        $officeOnly = $this->freeSetMethod($zone, 10000, [ShippingDeliveryType::OFFICE], ShippingDeliveryType::OFFICE, ShippingDestinationScope::PICKUP);

        App::setLocale('en');
        $this->assertSame('to office; 5.00 €; free from 100.00 €; Pickup point only', $this->reader()->summary($officeOnly));
    }

    public function test_the_kind_words_are_the_three_lowercase_words_in_both_languages(): void
    {
        $reader = $this->reader();

        App::setLocale('en');
        $this->assertSame('address, office, locker', $reader->freeKindWords(ShippingDeliveryType::destinationKinds()));
        $this->assertSame('locker', $reader->freeKindWords([ShippingDeliveryType::LOCKER]));

        App::setLocale('bg');
        $this->assertSame('адрес, офис, автомат', $reader->freeKindWords(ShippingDeliveryType::destinationKinds()));
        $this->assertSame('офис, автомат', $reader->freeKindWords([ShippingDeliveryType::OFFICE, ShippingDeliveryType::LOCKER]));
    }
}
