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
     * Several keys with ONE query (checkout RN-1b), through the SAME memo as get(): a key already known is served from it, the rest
     * are read together with a single `whereIn`, and EVERY one of them is memoized afterwards — its stored value, or null when the
     * row does not exist (exactly what get() memoizes). With everything memoized it costs no query, and an empty list costs none.
     *
     * @param  list<string>  $keys
     * @return array<string, ?string>
     */
    public function getMany(array $keys): array
    {
        $missing = array_values(array_unique(array_filter($keys, fn (string $key): bool => ! array_key_exists($key, $this->memo))));

        if ($missing !== []) {
            $found = SiteSettingModel::whereIn('key', $missing)->pluck('value', 'key')->all();

            foreach ($missing as $key) {
                $this->memo[$key] = isset($found[$key]) ? (string) $found[$key] : null;
            }
        }

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $this->memo[$key];
        }

        return $result;
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

        self::purge($key);
    }

    public function forget(string $key): void
    {
        SiteSettingModel::where('key', $key)->delete();

        // Cleared rather than memoized as null: the row is gone, and one
        // re-read on the next get() is both simpler to reason about and the
        // honest description of what just happened.
        unset($this->memo[$key]);

        self::purge($key);
    }

    /**
     * P8b: KEYS (or key prefixes) a visitor never sees on a cacheable page: writing them purges NOTHING. Every other key purges `all`. A short list on purpose: a new
     * key is purged until someone proves it invisible. (The call sites outside the admin screens: `media.html_block_import` by the `html-blocks:import` command,
     * `content.drafts_seeded` by ContentDraftsSeeder; no order, job, price refresh, counter or scheduled command writes this table.)
     *
     * @var array<string, string> prefix or fragment => why it is invisible
     */
    public const INVISIBLE = [
        'mail.' => 'the mail transport / sender: only the mails use it',
        '.draft' => 'a draft layout: only the admin preview reads it',
        'media.html_block_import' => 'the import command bookkeeping map',
        'content.drafts_seeded' => 'the seeder marker "already seeded"',
        'invoice.' => 'invoice rules: the checkout and the admin only',
        'privacy.' => 'order-context privacy (consent mode, IP retention): never printed on a page',
        'admin.' => 'the activity-log switches: admin only',
        'payment.bank_transfer.' => 'bank details: printed on the order-received page and in mails, which are never cached',
    ];

    /**
     * A storefront-visible setting changed (catalog settings, the collections switch, locale / country, the free-shipping inputs, every design key): the page cache of
     * the whole shop. The write already happened; the collector only collects (flushed once per request).
     */
    private static function purge(string $key): void
    {
        foreach (array_keys(self::INVISIBLE) as $invisible) {
            if (str_starts_with($key, $invisible) || ($invisible[0] === '.' && str_contains($key, $invisible))) {
                return;
            }
        }

        app(\App\Storefront\Cache\CachePurgeCollector::class)->add('all');
    }
}
