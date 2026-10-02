<?php

namespace App\Services;

use App\Services\Exceptions\CarrierCapabilityNotProvidedException;
use App\Services\Exceptions\InvalidCarrierResponseException;
use App\Services\Exceptions\UnknownShippingCarrierException;
use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\CarrierCallResult;
use EasyCo\Shipping\Carrier\CarrierCapability;
use EasyCo\Shipping\Carrier\CarrierUnavailableReason;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ONE way a rate or pickup-point call is made (shipping-domain-design.md §6,
 * failure tolerance): it never throws into checkout or the quote endpoint.
 *
 *  - a provider exception              -> unavailable(PROVIDER_ERROR)
 *  - an answer after the time budget   -> unavailable(TIMED_OUT), the answer discarded
 *  - an answer that is not valid       -> unavailable(INVALID_RESPONSE)
 *  - an unknown carrier / capability   -> unavailable(NOT_CONFIGURED)
 *
 * "No quotes" is an ANSWER (the carrier will not carry it), not a failure: it is
 * returned as an available result with no items.
 *
 * THE TIME BUDGET IS NOT ENFORCED HERE — PHP cannot interrupt arbitrary code. It
 * is handed to the provider, whose contract obliges it to honour it with its own
 * HTTP timeout; this class measures the elapsed time and logs an overrun.
 *
 * LOGGING CARRIES NO PERSONAL DATA: the carrier code (configuration), the
 * capability, the reason, the elapsed time and budget, and the exception CLASS.
 * Never the context (it holds the destination), never the exception message
 * (a provider may echo the address into it).
 *
 * LABEL CREATION IS DELIBERATELY NOT HERE: a failure there must surface to the
 * merchant, and the idempotency reference makes his retry safe.
 */
final class CarrierCallGuard
{
    public function __construct(private readonly ShippingProviderResolver $resolver)
    {
    }

    /** @return CarrierCallResult<ShippingQuote> */
    public function quotes(string $carrierCode, ShippingContext $context, CallBudget $budget): CarrierCallResult
    {
        return $this->guarded($carrierCode, CarrierCapability::RATE, $budget, function () use ($carrierCode, $context, $budget): array {
            $quotes = $this->resolver->rateProvider($carrierCode)->quote($context, $budget);

            foreach ($quotes as $quote) {
                if (! $quote instanceof ShippingQuote || $quote->currency !== $context->currency) {
                    throw new InvalidCarrierResponseException();
                }
            }

            return $quotes;
        });
    }

    /** @return CarrierCallResult<PickupPoint> */
    public function pickupPoints(string $carrierCode, string $countryCode, string $settlement, CallBudget $budget): CarrierCallResult
    {
        return $this->guarded($carrierCode, CarrierCapability::PICKUP, $budget, function () use ($carrierCode, $countryCode, $settlement, $budget): array {
            $points = $this->resolver->pickupPointProvider($carrierCode)->pickupPointsIn($countryCode, $settlement, $budget);

            foreach ($points as $point) {
                if (! $point instanceof PickupPoint || $point->countryCode !== $countryCode || $point->carrierCode !== $carrierCode) {
                    throw new InvalidCarrierResponseException();
                }
            }

            return $points;
        });
    }

    /**
     * @param  callable(): list<ShippingQuote|PickupPoint>  $call
     * @return CarrierCallResult<ShippingQuote|PickupPoint>
     */
    private function guarded(string $carrierCode, CarrierCapability $capability, CallBudget $budget, callable $call): CarrierCallResult
    {
        $started = hrtime(true);

        try {
            $items = $call();
        } catch (UnknownShippingCarrierException|CarrierCapabilityNotProvidedException $e) {
            return $this->fail($carrierCode, $capability, CarrierUnavailableReason::NOT_CONFIGURED, $started, $budget, $e);
        } catch (InvalidCarrierResponseException $e) {
            return $this->fail($carrierCode, $capability, CarrierUnavailableReason::INVALID_RESPONSE, $started, $budget, $e);
        } catch (Throwable $e) {
            return $this->fail($carrierCode, $capability, CarrierUnavailableReason::PROVIDER_ERROR, $started, $budget, $e);
        }

        $elapsedMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($elapsedMs > $budget->inMilliseconds()) {
            // Late: the customer has been waiting longer than we allowed, so the answer is dropped.
            return $this->fail($carrierCode, $capability, CarrierUnavailableReason::TIMED_OUT, $started, $budget, null);
        }

        return CarrierCallResult::answered($items);
    }

    private function fail(string $carrierCode, CarrierCapability $capability, CarrierUnavailableReason $reason, int $started, CallBudget $budget, ?Throwable $e): CarrierCallResult
    {
        Log::warning('Shipping carrier call unavailable.', [
            'carrier' => $carrierCode,
            'capability' => $capability->value,
            'reason' => $reason->value,
            'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'budget_ms' => $budget->inMilliseconds(),
            'exception' => $e !== null ? $e::class : null,
        ]);

        return CarrierCallResult::unavailable($reason);
    }
}
