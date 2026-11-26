<?php

namespace App\Couriers\Settlements;

/** What a settlement build did, in counts only (no names, no courier text). Per-courier maps are keyed by carrier code. */
final class SettlementBuildResult
{
    /** Backbone rows (region + name), whatever their part. */
    public int $backbone = 0;

    public int $created = 0;

    public int $updated = 0;

    /** @var array<string, int> matched by rule (a), postcode + name */
    public array $byPostcode = [];

    /** @var array<string, int> matched by rule (b), name + exactly one region */
    public array $byRegion = [];

    /** @var array<string, int> matched nothing: a `source = courier` row each (rule c) */
    public array $unmatched = [];

    /** @var array<string, int> rows that two ids of the courier matched and that were therefore split */
    public array $splits = [];

    /** @var array<string, int> courier settlements left out: a non-numeric or repeated id */
    public array $skipped = [];
}
