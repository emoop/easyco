<?php

namespace App\Couriers;

use App\Couriers\Events\PickupPointsSynced;
use App\Couriers\Settlements\SettlementLinker;
use App\Couriers\Settlements\SettlementSyncer;
use App\Services\ShippingProviderResolver;
use DateTimeImmutable;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Contracts\PickupPointRepository;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly points sync of ONE courier (courier-integration-design.md §3.4 steps 1-5):
 *
 *  1. fetch the whole list through the courier's PickupPointCatalogue (which can only reach the network through CourierHttp);
 *  2. validate: an item the catalogue could not build, a reference the table cannot hold (> 64 characters) or a repeated
 *     reference is skipped and COUNTED, and never stops the run;
 *  3. CIRCUIT BREAKER: fewer valid points than 80 % of the currently active count means the run is `refused_shrink`, nothing is
 *     changed and nothing deactivated (a truncated or empty answer must never empty the checkout). A first sync into an empty
 *     table has nothing to compare with and is allowed;
 *  4. upsert in chunks of 500, each its own short transaction, then deactivate what this run did not see;
 *  5. record the run, and announce it (PickupPointsSynced: the hook point of the cache purge, COUR-2a).
 *
 * A DISABLED COURIER IS NEVER CALLED: run() returns null without touching the network or writing a run.
 * Failure keeps the last good data (the table is only changed by a run that got to step 4). Every failure is recorded with a
 * closed code; no response content, URL or credential reaches the run, the log or an exception message.
 */
final class PickupPointSyncer
{
    public const COUNTRY = 'BG';

    public const CHUNK = 500;

    /** The valid count must reach this share of the active count. */
    public const MIN_SHARE_PERCENT = 80;

    public function __construct(
        private readonly CourierDirectory $couriers,
        private readonly CourierSettings $settings,
        private readonly ShippingProviderResolver $resolver,
        private readonly PickupPointRepository $points,
        private readonly SettlementSyncer $settlements,
        private readonly SettlementLinker $linker,
    ) {}

    /**
     * @param  bool  $withSettlements  rebuild the courier's settlements first (the weekly run; also done once, whenever none was ever built)
     * @return CourierSyncRun|null null when the courier is not enabled, or a run of it is already going
     */
    public function run(string $carrierCode, string $trigger, bool $withSettlements = false): ?CourierSyncRun
    {
        $descriptor = $this->couriers->descriptor($carrierCode);

        if ($descriptor === null || ! $this->couriers->isEnabled($carrierCode)) {
            return null;
        }

        $lock = Cache::lock('couriers:sync:'.$carrierCode, 900);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->sync($carrierCode, $trigger, $withSettlements);
        } finally {
            $lock->release();
        }
    }

    private function sync(string $carrierCode, string $trigger, bool $withSettlements): CourierSyncRun
    {
        $started = new DateTimeImmutable;
        $counts = ['seen' => 0, 'new_count' => 0, 'changed' => 0, 'deactivated' => 0, 'skipped' => 0, 'unmapped' => 0];

        $this->buildSettlements($carrierCode, $withSettlements);

        try {
            $descriptor = $this->couriers->descriptor($carrierCode);
            // The lists are public for BOX NOW: a secret that cannot be read must not stop the download (an adapter that needs it says so itself).
            $credentials = $this->settings->credentials($descriptor, tolerateUnreadable: true);
            $batch = $this->resolver->pickupPointCatalogue($carrierCode)->allPoints($credentials, self::COUNTRY);

            [$valid, $skipped] = $this->validated($carrierCode, $batch->points);
            $counts['skipped'] = $batch->malformed + $skipped;
            $counts['seen'] = count($valid);

            $active = $this->points->activeCount($carrierCode);

            if ($active > 0 && count($valid) * 100 < $active * self::MIN_SHARE_PERCENT) {
                return $this->record($carrierCode, $trigger, $started, CourierSyncRun::REFUSED_SHRINK, $counts, 'shrink');
            }

            foreach (array_chunk($valid, self::CHUNK) as $chunk) {
                $result = $this->points->upsertChunk($carrierCode, $chunk, $started);
                $counts['new_count'] += $result['new'];
                $counts['changed'] += $result['changed'];
            }

            $counts['deactivated'] = $this->points->deactivateUnseen($carrierCode, $started);
            // §3.3 item 5: through the courier's own settlement id (BOX NOW: postcode + name); what maps to nothing is counted.
            $counts['unmapped'] = $this->linker->link($carrierCode);

            $run = $this->record($carrierCode, $trigger, $started, $counts['skipped'] > 0 ? CourierSyncRun::PARTIAL : CourierSyncRun::OK, $counts, null);

            event(new PickupPointsSynced($carrierCode));

            return $run;
        } catch (CourierHttpException $e) {
            return $this->record($carrierCode, $trigger, $started, CourierSyncRun::FAILED, $counts, $e->storedCode());
        } catch (Throwable $e) {
            // The class only: a message may echo what the courier sent.
            Log::error('Courier points sync failed unexpectedly.', ['carrier' => $carrierCode, 'exception' => $e::class]);

            return $this->record($carrierCode, $trigger, $started, CourierSyncRun::FAILED, $counts, 'unexpected_error');
        }
    }

    /**
     * The settlements come BEFORE the points, so the points can be linked in the same run. A failure here never fails the points
     * sync (the points are still worth having): it is logged by class and the points are simply left unmapped until the next run.
     */
    private function buildSettlements(string $carrierCode, bool $force): void
    {
        if (! $force && $this->settlements->hasRun($carrierCode)) {
            return;
        }

        try {
            $result = $this->settlements->run($carrierCode);
            Log::info('Courier settlements built.', ['carrier' => $carrierCode, 'created' => $result->created, 'updated' => $result->updated]);
        } catch (Throwable $e) {
            Log::warning('Courier settlements could not be built.', ['carrier' => $carrierCode, 'exception' => $e::class]);
        }
    }

    /**
     * @param  list<PickupPoint>  $points
     * @return array{0: list<PickupPoint>, 1: int} the usable points and how many were left out
     */
    private function validated(string $carrierCode, array $points): array
    {
        $valid = [];
        $skipped = 0;

        foreach ($points as $point) {
            if (! $point instanceof PickupPoint
                || $point->carrierCode !== $carrierCode
                || $point->countryCode !== self::COUNTRY
                || strlen($point->reference) > 64
                || isset($valid[$point->reference])
                || mb_strlen($point->name) > 255
                || mb_strlen($point->settlement) > 120
                || mb_strlen($point->addressLine) > 255
                || ($point->postcode !== null && mb_strlen($point->postcode) > 12)) {
                $skipped++;

                continue;
            }

            $valid[$point->reference] = $point;
        }

        return [array_values($valid), $skipped];
    }

    /** @param array<string, int> $counts */
    private function record(string $carrierCode, string $trigger, DateTimeImmutable $started, string $outcome, array $counts, ?string $error): CourierSyncRun
    {
        return CourierSyncRun::create($counts + [
            'carrier_code' => $carrierCode,
            'trigger' => $trigger,
            'started_at' => $started->format('Y-m-d H:i:s.u'),
            'finished_at' => (new DateTimeImmutable)->format('Y-m-d H:i:s.u'),
            'outcome' => $outcome,
            'error_code' => $error,
        ]);
    }
}
