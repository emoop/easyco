<?php

namespace Tests\Support\Shipping;

use Closure;
use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\ShipmentLabel;
use EasyCo\Shipping\Carrier\ShipmentRequest;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;
use EasyCo\Shipping\Contracts\PickupPointProvider;
use EasyCo\Shipping\Contracts\ShipmentLabelProvider;
use EasyCo\Shipping\Contracts\ShippingRateProvider;

/** Fake providers for the resolver and guard tests: each runs the closure it was given, and records the budget it was handed. */
final class FakeRateProvider implements ShippingRateProvider
{
    public ?CallBudget $lastBudget = null;

    /** @param Closure(ShippingContext): array<int, mixed> $answer */
    public function __construct(private readonly Closure $answer)
    {
    }

    public function quote(ShippingContext $context, CallBudget $budget): array
    {
        $this->lastBudget = $budget;

        return ($this->answer)($context);
    }
}

final class FakePickupProvider implements PickupPointProvider
{
    public ?CallBudget $lastBudget = null;

    /** @param Closure(string, string): array<int, mixed> $answer */
    public function __construct(private readonly Closure $answer)
    {
    }

    public function pickupPointsIn(string $countryCode, string $settlement, CallBudget $budget): array
    {
        $this->lastBudget = $budget;

        return ($this->answer)($countryCode, $settlement);
    }
}

/** An in-memory label provider that honours the idempotency obligation: the same reference returns the same label. */
final class FakeLabelProvider implements ShipmentLabelProvider
{
    /** @var array<string, ShipmentLabel> */
    private array $created = [];

    public int $shipmentsCreated = 0;

    public function createLabel(ShipmentRequest $request): ShipmentLabel
    {
        return $this->created[$request->idempotencyReference] ??= $this->create($request);
    }

    private function create(ShipmentRequest $request): ShipmentLabel
    {
        $this->shipmentsCreated++;

        return new ShipmentLabel($request->carrierCode, $request->idempotencyReference, 'TRK-'.$this->shipmentsCreated);
    }
}

/** Helpers to build the value objects the fakes return. */
final class Fakes
{
    public static function quote(int $amount = 500, string $currency = 'EUR'): ShippingQuote
    {
        return new ShippingQuote('office', 'To office', $amount, $currency);
    }

    public static function point(string $carrier = 'acme', string $country = 'BG'): PickupPoint
    {
        return new PickupPoint($carrier, 'P-1', 'Acme office', $country, 'Sofia', 'Main st 1');
    }

    public static function context(string $settlement = 'Sofia'): ShippingContext
    {
        return new ShippingContext('BG', $settlement, false, 'EUR', 5000);
    }
}
