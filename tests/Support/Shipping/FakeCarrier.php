<?php

namespace Tests\Support\Shipping;

use Closure;
use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CreatedShipment;
use EasyCo\Shipping\Carrier\LabelDocument;
use EasyCo\Shipping\Carrier\LabelFormat;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\PickupPointBatch;
use EasyCo\Shipping\Carrier\PickupPointKind;
use EasyCo\Shipping\Carrier\ShipmentRequest;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;
use EasyCo\Shipping\Contracts\PickupPointCatalogue;
use EasyCo\Shipping\Contracts\ShipmentGateway;
use EasyCo\Shipping\Contracts\ShippingRateProvider;

/** Fake providers for the resolver and guard tests: each runs the closure it was given, and records the budget it was handed. */
final class FakeRateProvider implements ShippingRateProvider
{
    public ?CallBudget $lastBudget = null;

    /** @param Closure(ShippingContext): array<int, mixed> $answer */
    public function __construct(private readonly Closure $answer) {}

    public function quote(ShippingContext $context, CallBudget $budget): array
    {
        $this->lastBudget = $budget;

        return ($this->answer)($context);
    }
}

final class FakePickupProvider implements PickupPointCatalogue
{
    /** @param Closure(string): array<int, mixed> $answer the points for a country */
    public function __construct(private readonly Closure $answer) {}

    public function allPoints(CourierCredentials $credentials, string $countryCode): PickupPointBatch
    {
        return new PickupPointBatch(($this->answer)($countryCode));
    }

    public function settlements(CourierCredentials $credentials, string $countryCode): array
    {
        return [];
    }
}

/** An in-memory gateway that honours the idempotency obligation: the same reference returns the same shipment. */
final class FakeShipmentGateway implements ShipmentGateway
{
    /** @var array<string, CreatedShipment> */
    private array $created = [];

    public int $shipmentsCreated = 0;

    public function create(ShipmentRequest $request, CourierCredentials $credentials): CreatedShipment
    {
        return $this->created[$request->idempotencyReference] ??= $this->make($request);
    }

    private function make(ShipmentRequest $request): CreatedShipment
    {
        $this->shipmentsCreated++;

        return new CreatedShipment($request->carrierCode, $request->idempotencyReference, 'EXT-'.$this->shipmentsCreated, 'TRK-'.$this->shipmentsCreated);
    }

    public function cancel(string $externalId, CourierCredentials $credentials): void {}

    public function labels(array $externalIds, LabelFormat $format, CourierCredentials $credentials): LabelDocument
    {
        return new LabelDocument('%PDF-fake');
    }

    public function track(array $externalIds, CourierCredentials $credentials): array
    {
        return [];
    }

    public function trackingUrl(string $trackingNumber): ?string
    {
        return null;
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
        return new PickupPoint($carrier, 'P-1', 'Acme office', $country, 'Sofia', 'Main st 1', PickupPointKind::OFFICE);
    }

    public static function context(string $settlement = 'Sofia'): ShippingContext
    {
        return new ShippingContext('BG', $settlement, false, 'EUR', 5000);
    }
}
