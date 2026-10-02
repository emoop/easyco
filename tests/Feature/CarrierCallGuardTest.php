<?php

namespace Tests\Feature;

use App\Services\CarrierCallGuard;
use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Carrier\CarrierUnavailableReason;
use EasyCo\Shipping\Carrier\ShippingContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\Shipping\Fakes;
use Tests\Support\Shipping\FakeLabelProvider;
use Tests\Support\Shipping\FakePickupProvider;
use Tests\Support\Shipping\FakeRateProvider;
use Tests\TestCase;

require_once __DIR__.'/../Support/Shipping/FakeCarrier.php';

/**
 * Shipping stage 3c (shipping-domain-design.md §6, failure tolerance): a rate or
 * pickup-point call never throws into the caller; an exception, an overrun, an
 * invalid answer and a configuration fault each become an explicit "unavailable"
 * with a reason, logged WITHOUT personal data. Label creation is not wrapped.
 */
class CarrierCallGuardTest extends TestCase
{
    private function guard(): CarrierCallGuard
    {
        return $this->app->make(CarrierCallGuard::class);
    }

    private function carrier(string $code, ?FakeRateProvider $rate = null, ?FakePickupProvider $pickup = null): void
    {
        $caps = [];

        if ($rate !== null) {
            $this->app->instance(CarrierCapability::RATE->containerKey($code), $rate);
            $caps[] = CarrierCapability::RATE;
        }

        if ($pickup !== null) {
            $this->app->instance(CarrierCapability::PICKUP->containerKey($code), $pickup);
            $caps[] = CarrierCapability::PICKUP;
        }

        $this->app->make(CarrierRegistry::class)->register(new CarrierRegistration($code, ucfirst($code), $caps));
    }

    private function budget(int $ms = 2000): CallBudget
    {
        return CallBudget::milliseconds($ms);
    }

    public function test_an_answer_within_budget_is_returned_and_the_provider_was_handed_the_budget(): void
    {
        $provider = new FakeRateProvider(fn () => [Fakes::quote(700)]);
        $this->carrier('acme', $provider);

        $result = $this->guard()->quotes('acme', Fakes::context(), $this->budget(1234));

        $this->assertTrue($result->isAvailable());
        $this->assertSame(700, $result->items()[0]->amountMinor);
        $this->assertSame(1234, $provider->lastBudget->inMilliseconds(), 'the budget is passed to the provider, which must honour it');
    }

    public function test_no_quotes_is_an_answer_not_a_failure(): void
    {
        $this->carrier('acme', new FakeRateProvider(fn () => []));

        $result = $this->guard()->quotes('acme', Fakes::context(), $this->budget());

        $this->assertTrue($result->isAvailable());
        $this->assertSame([], $result->items());
        $this->assertNull($result->reason());
    }

    public function test_a_provider_exception_becomes_unavailable_and_never_escapes(): void
    {
        $this->carrier('acme', new FakeRateProvider(fn () => throw new RuntimeException('boom')));

        $result = $this->guard()->quotes('acme', Fakes::context(), $this->budget());

        $this->assertFalse($result->isAvailable());
        $this->assertSame(CarrierUnavailableReason::PROVIDER_ERROR, $result->reason());
        $this->assertSame([], $result->items());
    }

    public function test_a_php_error_in_a_provider_is_also_contained(): void
    {
        $this->carrier('acme', new FakeRateProvider(fn () => intdiv(1, 0)));

        $this->assertSame(CarrierUnavailableReason::PROVIDER_ERROR, $this->guard()->quotes('acme', Fakes::context(), $this->budget())->reason());
    }

    public function test_an_overrun_becomes_unavailable_and_the_late_answer_is_discarded(): void
    {
        $this->carrier('acme', new FakeRateProvider(function () {
            usleep(30_000); // 30 ms against a 1 ms budget: an overrun on any machine

            return [Fakes::quote(100)];
        }));

        $result = $this->guard()->quotes('acme', Fakes::context(), $this->budget(1));

        $this->assertFalse($result->isAvailable());
        $this->assertSame(CarrierUnavailableReason::TIMED_OUT, $result->reason());
        $this->assertSame([], $result->items(), 'a late answer is not used');
    }

    public function test_an_invalid_answer_becomes_unavailable(): void
    {
        $this->carrier('acme', new FakeRateProvider(fn () => [Fakes::quote(100, 'USD')]));
        $this->assertSame(CarrierUnavailableReason::INVALID_RESPONSE, $this->guard()->quotes('acme', Fakes::context(), $this->budget())->reason(), 'a quote in another currency');

        $this->carrier('beta', new FakeRateProvider(fn () => ['not a quote']));
        $this->assertSame(CarrierUnavailableReason::INVALID_RESPONSE, $this->guard()->quotes('beta', Fakes::context(), $this->budget())->reason());

        $this->carrier('gamma', pickup: new FakePickupProvider(fn () => [Fakes::point('gamma', 'RO')]));
        $this->assertSame(CarrierUnavailableReason::INVALID_RESPONSE, $this->guard()->pickupPoints('gamma', 'BG', 'Sofia', $this->budget())->reason(), 'a point of another country');
    }

    public function test_an_unknown_carrier_or_a_missing_capability_is_unavailable_not_an_exception(): void
    {
        $this->assertSame(CarrierUnavailableReason::NOT_CONFIGURED, $this->guard()->quotes('nobody', Fakes::context(), $this->budget())->reason());

        $this->carrier('acme', new FakeRateProvider(fn () => []));
        $this->assertSame(CarrierUnavailableReason::NOT_CONFIGURED, $this->guard()->pickupPoints('acme', 'BG', 'Sofia', $this->budget())->reason());
    }

    public function test_pickup_points_are_guarded_the_same_way(): void
    {
        $this->carrier('acme', pickup: new FakePickupProvider(fn () => [Fakes::point('acme')]));
        $ok = $this->guard()->pickupPoints('acme', 'BG', 'Sofia', $this->budget());
        $this->assertTrue($ok->isAvailable());
        $this->assertSame('P-1', $ok->items()[0]->reference);

        $this->carrier('beta', pickup: new FakePickupProvider(fn () => throw new RuntimeException('down')));
        $this->assertSame(CarrierUnavailableReason::PROVIDER_ERROR, $this->guard()->pickupPoints('beta', 'BG', 'Sofia', $this->budget())->reason());
    }

    public function test_the_log_has_the_reason_and_no_personal_data(): void
    {
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
            $logged[] = [$message, $context];
        });

        // The settlement is in the context; the exception message echoes a name and an address, as a careless provider might.
        $this->carrier('acme', new FakeRateProvider(fn () => throw new RuntimeException('Cannot ship to Ivan Petrov, ul. Vitosha 1, Plovdiv')));

        $this->guard()->quotes('acme', Fakes::context('Plovdiv'), $this->budget());

        $this->assertCount(1, $logged);
        [$message, $context] = $logged[0];
        $this->assertSame('acme', $context['carrier']);
        $this->assertSame('rate', $context['capability']);
        $this->assertSame('provider_error', $context['reason']);
        $this->assertSame(RuntimeException::class, $context['exception']);

        $everything = $message.json_encode($context);
        foreach (['Ivan', 'Petrov', 'Vitosha', 'Plovdiv', 'Cannot ship'] as $personal) {
            $this->assertStringNotContainsString($personal, $everything, "the log must not contain \"{$personal}\"");
        }
    }

    public function test_an_overrun_is_logged_with_elapsed_and_budget(): void
    {
        $logged = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged): void {
            $logged[] = $context;
        });

        $this->carrier('acme', new FakeRateProvider(function () {
            usleep(30_000);

            return [];
        }));

        $this->guard()->quotes('acme', Fakes::context(), $this->budget(1));

        $this->assertSame('timed_out', $logged[0]['reason']);
        $this->assertSame(1, $logged[0]['budget_ms']);
        $this->assertGreaterThan($logged[0]['budget_ms'], $logged[0]['elapsed_ms'], 'the logged elapsed time shows the overrun');
    }

    public function test_label_creation_is_not_wrapped_and_a_retry_with_the_same_reference_creates_nothing_new(): void
    {
        // The guard has no label method at all: a failure there must reach the merchant.
        $this->assertFalse(method_exists(CarrierCallGuard::class, 'createLabel'));
        $this->assertFalse(method_exists(CarrierCallGuard::class, 'labels'));

        // And the contract's idempotency obligation, as a conforming fake keeps it.
        $provider = new FakeLabelProvider();
        $request = new \EasyCo\Shipping\Carrier\ShipmentRequest('42', 1, 'acme', 'office', 'A B', '+359888000000', 'BG', 'Sofia', 'EUR', 100, 'Street 1');

        $first = $provider->createLabel($request);
        $retry = $provider->createLabel($request);

        $this->assertSame($first, $retry);
        $this->assertSame(1, $provider->shipmentsCreated, 'a retried request never creates a second shipment');

        $second = $provider->createLabel(new \EasyCo\Shipping\Carrier\ShipmentRequest('42', 2, 'acme', 'office', 'A B', '+359888000000', 'BG', 'Sofia', 'EUR', 100, 'Street 1'));
        $this->assertNotSame($first->trackingNumber, $second->trackingNumber, 'a new attempt number is a deliberate new shipment');
    }
}
