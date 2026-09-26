<?php

namespace App\Settings\Persistence\Eloquent;

use App\Settings\Contracts\SiteSettingsRepository;
use InvalidArgumentException;

/**
 * Maps the site_settings table directly onto get/set/forget — no
 * domain entity round-tripping (site-settings-design.md §6): `key` is
 * the natural, unique identity, so set() is a plain upsert-by-key
 * rather than the insert-vs-update-by-surrogate-id pattern every other
 * repository in this project follows.
 *
 * EVERY KEY IS READ AT MOST ONCE PER REQUEST. That is the fix for a real,
 * measured hot spot: the products list re-read the same four keys up to 19
 * times in ONE render (51 of its 69 queries; admin-panel-design.md §13.9).
 * The cause was that this repository had no memory at all — `get()` was one
 * uncached SELECT per call, and its callers legitimately ask repeatedly
 * (ProductResource::brandFieldEnabled() and its three siblings are consulted
 * by a table column's ->visible(), by the matching filter's ->visible() and
 * ->options(), and by the form fields).
 *
 * THE MEMO LIVES HERE, NOT IN THE CALLERS, deliberately: memoizing at each
 * call site would mean every current caller remembering to do it and every
 * future one knowing it must. One array on one object, kept alive for exactly
 * one request by the scoped() binding in SiteSettingsServiceProvider, fixes
 * all of them at once — including callers that do not exist yet.
 *
 * `array_key_exists()`, NOT isset(): "this key is not set" is a real answer
 * worth memoizing too, or a page asking for an unset setting 19 times pays 19
 * queries for the same nothing.
 *
 * WRITES ARE VISIBLE TO LATER READS WITHIN THE SAME REQUEST — the point of
 * the memo, and a deliberate change from this class's previous behaviour:
 * set() writes the row AND updates the memo; forget() deletes the row and
 * clears the memo, so the next get() re-reads and correctly finds nothing.
 * Nothing else in this codebase writes this table (checked: the only writers
 * are the three methods below), so the one documented limitation of a
 * per-request memo — a raw SQL write from somewhere else would not be seen
 * until the next request — has no real path to it today.
 */
final class EloquentSiteSettingsRepository implements SiteSettingsRepository
{
    /**
     * Key => value, for this request only. A null value is a memoized "not
     * set", which is why reads test the KEY's presence, never the value.
     *
     * @var array<string, string|null>
     */
    private array $memo = [];

    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $value = SiteSettingModel::where('key', $key)->value('value');

        return $this->memo[$key] = $value === null ? null : (string) $value;
    }

    /**
     * "key is a non-empty string" is the only invariant this concept
     * has (§6) — enforced trivially here rather than in a dedicated
     * domain entity, per the design doc's own reasoning.
     */
    public function set(string $key, string $value): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Site setting key must not be empty.');
        }

        SiteSettingModel::updateOrCreate(['key' => $key], ['value' => $value]);

        // Written through, not invalidated: the new value IS the truth for the
        // rest of this request, so a later get() needs no query at all.
        $this->memo[$key] = $value;
    }

    public function forget(string $key): void
    {
        SiteSettingModel::where('key', $key)->delete();

        // Cleared rather than memoized as null: the row is gone, and one
        // re-read on the next get() is both simpler to reason about and the
        // honest description of what just happened.
        unset($this->memo[$key]);
    }
}
