<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Site settings are read at most once per key per request
 * (EloquentSiteSettingsRepository's own memo + the scoped() binding).
 *
 * WHY THIS EXISTS: the products list alone re-read four keys up to 19 times in
 * one render — 51 of its 69 queries. The memo is per REQUEST, so the two
 * properties that matter are: repeated reads inside one request are free, and
 * the NEXT request still sees current data (never a stale value from a previous
 * request).
 *
 * "THE NEXT REQUEST" is simulated with Container::forgetScopedInstances() — the
 * mechanism Laravel itself uses to end a scoped binding's lifetime between
 * requests in a long-running worker (already the established convention in this
 * suite, see ProductResourcePriceColumnTest's own memoization test).
 */
class SiteSettingsMemoizationTest extends TestCase
{
    use RefreshDatabase;

    private function settings(): SiteSettingsRepository
    {
        return app(SiteSettingsRepository::class);
    }

    /**
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $callback();

        return $queries;
    }

    /**
     * @param list<array{sql: string, bindings: array<int, mixed>}> $queries
     * @return list<string> the site_settings keys those queries ASKED FOR, in order
     *
     * SELECTs only: set()/forget() are writes this test's assertions are not
     * counting (and a write's own site_settings query would otherwise be
     * mistaken for a read).
     */
    private function settingsKeysRead(array $queries): array
    {
        $keys = [];

        foreach ($queries as $query) {
            if (! str_starts_with(strtolower(trim($query['sql'])), 'select')) {
                continue;
            }

            if (str_contains($query['sql'], 'site_settings')) {
                $keys[] = (string) ($query['bindings'][0] ?? '');
            }
        }

        return $keys;
    }

    public function test_repeated_reads_of_one_key_in_a_request_cost_exactly_one_query(): void
    {
        $this->settings()->set('test.memoized_key', 'first');

        // A cold memo, so the reads below measure the read path itself (set()
        // writes through, so without this the first read would be a memo hit and
        // the test would prove nothing about the query).
        $this->app->forgetScopedInstances();

        $queries = $this->captureQueries(function (): void {
            for ($read = 0; $read < 5; $read++) {
                $this->assertSame('first', $this->settings()->get('test.memoized_key'));
            }
        });

        $this->assertCount(1, $this->settingsKeysRead($queries), 'five reads of the same key must be one query');
    }

    public function test_repeated_reads_of_a_key_that_is_not_set_also_cost_one_query(): void
    {
        // "Not set" is memoized too — otherwise the unset case would be the one
        // shape that still costs a query per check (19 checks, 19 queries).
        $queries = $this->captureQueries(function (): void {
            for ($read = 0; $read < 5; $read++) {
                $this->assertNull($this->settings()->get('test.never_set_key'));
            }
        });

        $this->assertCount(1, $this->settingsKeysRead($queries), 'five reads of an unset key must be one query');
    }

    public function test_a_write_is_visible_to_later_reads_in_the_same_request(): void
    {
        $this->settings()->set('test.visible_key', 'first');
        $this->assertSame('first', $this->settings()->get('test.visible_key'));

        // The write itself (outside the capture — updateOrCreate is a write, and
        // its own internal lookup is not the read this test is about).
        $this->settings()->set('test.visible_key', 'second');

        $readQueries = $this->captureQueries(function (): void {
            // The read must need no query at all, and must NOT return the
            // memoized 'first'.
            $this->assertSame('second', $this->settings()->get('test.visible_key'));
        });

        $this->assertCount(0, $this->settingsKeysRead($readQueries), 'the read after a set() needs no query at all');

        // forget() clears the memo for that key, so the next read re-reads and
        // correctly finds nothing.
        $this->settings()->forget('test.visible_key');
        $this->assertNull($this->settings()->get('test.visible_key'));

        $afterForget = $this->captureQueries(function (): void {
            $this->assertNull($this->settings()->get('test.visible_key'));
        });

        $this->assertCount(0, $this->settingsKeysRead($afterForget), 'the null answer is memoized too');
    }

    public function test_the_next_request_re_reads_and_sees_what_was_stored_in_the_meantime(): void
    {
        $this->settings()->set('test.freshness_key', 'first');
        $this->assertSame('first', $this->settings()->get('test.freshness_key'));

        // A value committed by ANOTHER request (written behind this request's
        // memo — the same shape as any other process saving a setting while this
        // one is in flight).
        DB::table('site_settings')->updateOrInsert(
            ['key' => 'test.freshness_key'],
            ['value' => 'written_elsewhere'],
        );

        // Mid-request the memo still wins: it is only as fresh as the last write
        // IT saw, and every writer in this codebase goes through the repository.
        $this->assertSame('first', $this->settings()->get('test.freshness_key'));

        // The NEXT request starts clean, reads the current value — one query, and
        // the new value.
        $this->app->forgetScopedInstances();

        $queries = $this->captureQueries(function (): void {
            $this->assertSame('written_elsewhere', $this->settings()->get('test.freshness_key'));
        });

        $this->assertCount(1, $this->settingsKeysRead($queries), 'the next request must actually re-read');
    }

    public function test_the_repository_is_one_instance_per_request(): void
    {
        $this->assertSame($this->settings(), $this->settings(), 'scoped(): one instance within a request');

        $before = $this->settings();

        $this->app->forgetScopedInstances();

        $this->assertNotSame($before, $this->settings(), 'a new request gets a new instance, and a clean memo');
    }

    public function test_the_products_list_reads_each_settings_key_at_most_once_per_render(): void
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('memo.admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        $queries = $this->captureQueries(function (): void {
            Livewire::test(ListProducts::class);
        });

        $keys = $this->settingsKeysRead($queries);
        $distinctKeys = array_values(array_unique($keys));

        fwrite(STDERR, sprintf(
            "\n[query-count] products list render: %d site_settings queries for %d distinct key(s): %s\n",
            count($keys),
            count($distinctKeys),
            implode(', ', $distinctKeys),
        ));

        // THE INVARIANT: no key is read twice in one render. Before the memo, the
        // same four keys were read 51 times — one table column, the matching
        // filter and the form field each asking independently.
        $this->assertSame($distinctKeys, $keys, 'no settings key may be read more than once in a single render');
        $this->assertLessThanOrEqual(5, count($keys), 'the products list reads a known, small set of keys');
    }
}
