<?php

namespace Tests\Feature;

use App\Services\ShippingMethodSummaryReader;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
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

    public function test_a_flat_method_is_just_its_price_in_both_languages(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FLAT);

        App::setLocale('en');
        $this->assertSame('5.00 €', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €', $this->reader()->summary($method));
    }

    public function test_a_flat_method_with_a_threshold_appends_the_free_from_clause(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FLAT, freeAbove: 10000);

        App::setLocale('en');
        $this->assertSame('5.00 €; free from 100.00 €', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; безплатна над 100.00 €', $this->reader()->summary($method));
    }

    public function test_a_free_method_reads_free_in_both_languages(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FREE, amount: null);

        App::setLocale('en');
        $this->assertSame('Free', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('Безплатна', $this->reader()->summary($method));
    }

    public function test_a_per_class_method_shows_the_base_then_the_class_names_then_the_threshold(): void
    {
        $this->classNamed('heavy', 'Heavy');
        $method = $this->method($this->zoneId(), ShippingMethodKind::PER_CLASS, rates: ['heavy' => 3000], freeAbove: 10000);

        App::setLocale('en');
        $this->assertSame('5.00 €; Heavy 30.00 €; free from 100.00 €', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; Heavy 30.00 €; безплатна над 100.00 €', $this->reader()->summary($method));
    }

    public function test_a_per_class_rate_for_an_unknown_code_falls_back_to_the_code(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::PER_CLASS, rates: ['ghost' => 700]);

        App::setLocale('en');
        $this->assertSame('5.00 €; ghost 7.00 €', $this->reader()->summary($method));
    }

    public function test_a_carrier_method_names_the_carrier_as_not_configured(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::CARRIER, amount: null, carrier: 'econt');

        App::setLocale('en');
        $this->assertSame('Carrier: econt · not configured', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('Куриер: econt · не е настроен', $this->reader()->summary($method));
    }

    public function test_a_pickup_point_and_an_inactive_method_append_their_facts(): void
    {
        $method = $this->method($this->zoneId(), ShippingMethodKind::FLAT, pickup: true, active: false);

        App::setLocale('en');
        $this->assertSame('5.00 €; pickup point; inactive', $this->reader()->summary($method));

        App::setLocale('bg');
        $this->assertSame('5.00 €; до офис/автомат; неактивен', $this->reader()->summary($method));
    }

    public function test_the_sentence_changes_when_the_data_changes(): void
    {
        $zone = $this->zoneId();
        $method = $this->method($zone, ShippingMethodKind::FLAT);

        App::setLocale('en');
        $this->assertSame('5.00 €', $this->reader()->summary($method));

        $changed = $this->method($zone, ShippingMethodKind::FLAT, amount: 1200);

        $this->assertSame('12.00 €', $this->reader()->summary($changed));
    }
}
