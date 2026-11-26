<?php

namespace App\Couriers\Settlements;

use App\Couriers\CourierDirectory;
use App\Couriers\CourierSettings;
use App\Couriers\PickupPointSyncer;
use App\Services\ShippingProviderResolver;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the settlements for ONE enabled courier (courier-integration-design.md §3.4): fetches that courier's settlements through
 * its catalogue (only ever through CourierHttp), then SettlementBuilder joins them onto the backbone. A courier with no settlement
 * list of its own (BOX NOW) still makes sure the backbone exists, because its lockers are mapped through it.
 *
 * Remembers WHEN it last succeeded (`couriers.<code>.settlements_synced_at`): a sync run that finds none builds the settlements
 * first, which is how «once when a courier is first enabled» happens. A failure throws and records nothing.
 */
final class SettlementSyncer
{
    public const SYNCED_AT = 'settlements_synced_at';

    /** ONE settlement build at a time, for every courier: the build rewrites the table from a snapshot (see run()). */
    public const LOCK = 'couriers:settlements-build';

    public const LOCK_WAIT_SECONDS = 120;

    /** The same share the points sync demands (PickupPointSyncer::MIN_SHARE_PERCENT): a list under it is not a real answer. */
    public const MIN_SHARE_PERCENT = PickupPointSyncer::MIN_SHARE_PERCENT;

    public function __construct(
        private readonly CourierDirectory $couriers,
        private readonly CourierSettings $settings,
        private readonly ShippingProviderResolver $resolver,
        private readonly SettlementBuilder $builder,
        private readonly SiteSettingsRepository $store,
        private readonly int $lockWaitSeconds = self::LOCK_WAIT_SECONDS,
    ) {}

    /**
     * Fetch, check, build, remember. THE WHOLE THING RUNS UNDER ONE GLOBAL LOCK: SettlementBuilder loads the table, changes it in
     * memory and writes back every column of the rows that changed, so two builds at once (Econt and Speedy on two queue workers)
     * would let the second write back the first one's id column from a stale snapshot. The second build WAITS (up to
     * $lockWaitSeconds); when the wait runs out it throws and the next run tries again.
     *
     * CIRCUIT BREAKER (§3.4, the points sync's 80 %): a list that, after the builder's own filter, holds fewer than 80 % of the ids
     * the courier currently has on the table is refused with SETTLEMENTS_SHRANK before anything is written, so a truncated answer
     * never clears ids and the points keep their links. An empty list is refused always, the first build included.
     *
     * @throws CourierHttpException
     * @throws LockTimeoutException
     */
    public function run(string $carrierCode): SettlementBuildResult
    {
        $lock = Cache::lock(self::LOCK, 600);
        $lock->block($this->lockWaitSeconds);

        try {
            return $this->runLocked($carrierCode);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(string $carrierCode): SettlementBuildResult
    {
        $descriptor = $this->couriers->descriptor($carrierCode);

        if ($descriptor === null || ! $this->couriers->isEnabled($carrierCode)) {
            throw new \LogicException('Settlements are built only for an installed, enabled courier.');
        }

        $lists = [];

        if (isset(SettlementBuilder::COLUMNS[$carrierCode])) {
            $credentials = $this->settings->credentials($descriptor, tolerateUnreadable: true);
            $list = $this->resolver->pickupPointCatalogue($carrierCode)->settlements($credentials, SettlementBuilder::COUNTRY);

            $usable = SettlementBuilder::usableCount($list);
            $current = DB::table('shipping_settlements')->whereNotNull(SettlementBuilder::COLUMNS[$carrierCode])->count();

            if ($usable === 0 || ($current > 0 && $usable * 100 < $current * self::MIN_SHARE_PERCENT)) {
                throw new CourierHttpException(CourierHttpException::SETTLEMENTS_SHRANK);
            }

            $lists[$carrierCode] = $list;
        }

        $result = $this->builder->build($lists);
        $this->store->set($this->settings->key($carrierCode, self::SYNCED_AT), (new \DateTimeImmutable)->format('Y-m-d H:i:s'));

        return $result;
    }

    public function hasRun(string $carrierCode): bool
    {
        return $this->store->get($this->settings->key($carrierCode, self::SYNCED_AT)) !== null;
    }
}
