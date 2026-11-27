<?php

namespace App\NeedsAttention;

use App\Couriers\CourierDirectory;
use App\Couriers\CourierSettings;
use App\Couriers\CourierSyncRun;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use DateTimeImmutable;
use DateTimeZone;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * "Courier lists that are out of date" (courier-integration-design.md §3.4, §6.8): ONE ROW PER ENABLED COURIER whose points
 * list has stopped refreshing, never one per night. A courier is listed when
 *  - its last TWO runs both failed (outcome `failed` or `refused_shrink`: neither changed the list), or
 *  - its last successful run (`ok` / `partial`) finished more than 48 hours ago, or it has never had one and has been enabled
 *    (or has been running) for more than 48 hours.
 * A disabled courier is never listed: it is not synced, so it cannot be stale. The checkout keeps working on the old list.
 *
 * NOT ABOUT AN ORDER: the rows carry no order and no amount (NeedsAttentionItem::$subject names the courier). Age = whole days
 * since the last good run (or since the courier was enabled), in the store's timezone.
 *
 * ONE READ of the run table per call (the latest 30 days of the enabled couriers' runs), plus the enabled-at settings read in one
 * `getMany`; the rest is arithmetic on a handful of rows. count() and page() derive the same list.
 */
final class CourierSyncStaleSource implements NeedsAttentionSource
{
    public const KEY = 'courier_sync_stale';

    public const STALE_AFTER_HOURS = 48;

    public const FAILED_RUNS = 2;

    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly CourierDirectory $couriers,
        private readonly CarrierRegistry $registry,
        private readonly CourierSettings $courierSettings,
        private readonly SiteSettingsRepository $settings,
        private readonly StoreTimezone $timezone,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('needs_attention.sources.courier_sync_stale.label');
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function page(int $page, int $perPage): array
    {
        return array_slice($this->items(), max(0, $page - 1) * $perPage, $perPage);
    }

    /** @return list<NeedsAttentionItem> oldest first, then by courier code */
    private function items(): array
    {
        $codes = array_map(static fn ($descriptor): string => $descriptor->code(), $this->couriers->enabled());

        if ($codes === []) {
            return [];
        }

        $now = Carbon::now('UTC')->toDateTimeImmutable();
        $enabledAt = $this->settings->getMany(array_map(fn (string $code): string => $this->courierSettings->key($code, CourierSettings::ENABLED_AT), $codes));

        $runs = CourierSyncRun::query()
            ->whereIn('carrier_code', $codes)
            ->where('started_at', '>=', $now->modify('-'.self::WINDOW_DAYS.' days')->format('Y-m-d H:i:s'))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('carrier_code');

        $items = [];

        foreach ($codes as $code) {
            $item = $this->itemFor($code, $runs->get($code)?->all() ?? [], $enabledAt[$this->courierSettings->key($code, CourierSettings::ENABLED_AT)] ?? null, $now);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        usort($items, static fn (NeedsAttentionItem $a, NeedsAttentionItem $b): int => [$a->startedOn, $a->subject] <=> [$b->startedOn, $b->subject]);

        return $items;
    }

    /** @param list<CourierSyncRun> $runs newest first */
    private function itemFor(string $code, array $runs, ?string $enabledAt, DateTimeImmutable $now): ?NeedsAttentionItem
    {
        $lastSuccess = null;

        foreach ($runs as $run) {
            if ($run->succeeded()) {
                $lastSuccess = $run;

                break;
            }
        }

        $lastTwo = array_slice($runs, 0, self::FAILED_RUNS);
        $failedTwice = count($lastTwo) === self::FAILED_RUNS && ! $lastTwo[0]->succeeded() && ! $lastTwo[1]->succeeded();

        $since = $lastSuccess !== null
            ? $this->instant($lastSuccess->finished_at?->format('Y-m-d H:i:s') ?? $lastSuccess->started_at->format('Y-m-d H:i:s'))
            : $this->firstKnown($runs, $enabledAt);

        $tooOld = $since !== null && ($now->getTimestamp() - $since->getTimestamp()) > self::STALE_AFTER_HOURS * 3600;

        if (! $failedTwice && ! $tooOld) {
            return null;
        }

        // A courier that has only ever failed has no good run to count from: count from the first thing we know about it.
        $from = $since ?? $this->firstKnown($runs, $enabledAt) ?? $now;
        $startedOn = $this->timezone->dayOf($from);
        $name = $this->registry->find($code)?->name ?? $code;
        $failure = $runs[0] ?? null;

        return new NeedsAttentionItem(
            sourceKey: self::KEY,
            orderId: null,
            fact: $failedTwice
                ? __('needs_attention.sources.courier_sync_stale.fact_failed', ['courier' => $name, 'code' => (string) ($failure?->error_code ?? $failure?->outcome)])
                : __('needs_attention.sources.courier_sync_stale.fact_old', ['courier' => $name, 'hours' => self::STALE_AFTER_HOURS]),
            amount: null,
            ageDays: StoreTimezone::daysBetween($startedOn, $this->timezone->dayOf($now)), // the same instant as every other comparison here (Carbon::now), not the native clock
            startedOn: $startedOn,
            subject: $name,
        );
    }

    /** @param list<CourierSyncRun> $runs newest first */
    private function firstKnown(array $runs, ?string $enabledAt): ?DateTimeImmutable
    {
        $candidates = [];

        if ($enabledAt !== null && $enabledAt !== '') {
            $candidates[] = $this->instant($enabledAt);
        }

        if ($runs !== []) {
            $candidates[] = $this->instant($runs[count($runs) - 1]->started_at->format('Y-m-d H:i:s'));
        }

        $candidates = array_filter($candidates);

        return $candidates === [] ? null : min($candidates);
    }

    private function instant(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
