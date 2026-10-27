<?php

namespace Tests\Feature;

use App\Enums\OrderBlockType;
use App\Models\OrderBlockModel;
use App\Services\OrderBlockMasker;
use App\Services\OrderBlockService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Log\Events\MessageLogged;
use InvalidArgumentException;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B (cod-protection-design.md section 3): the one writer of `order_blocks` — block, extend by re-blocking
 * (never a duplicate), revoke, "active", the 30-day prune and its command, the masks, the activity log (masked, never a full IP), and the table.
 */
class OrderBlockServiceTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        // BEFORE the fixtures below: what they write and a DDL later commits is a difference to restore, not part of the baseline.
        $this->captureDatabaseFingerprint();

        // PH-1: a phone typed by hand is read with the STORE's country (a configured shop always has one).
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\StoreCountry::KEY, 'BG');

        $this->now = Carbon::parse('2026-10-19 12:00:00');
        Carbon::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->restoreFingerprintedState();
        $this->databaseMatchesFingerprint();
        parent::tearDown();
        $this->skipRemigrationIfClean();
    }

    private function service(): OrderBlockService
    {
        return app(OrderBlockService::class);
    }

    // ---- blocking ---------------------------------------------------------------------------------------------------------------------

    public function test_a_block_stores_the_key_what_staff_saw_the_reason_and_the_end(): void
    {
        $block = $this->service()->block(OrderBlockType::PHONE, '0888 123 456', 180, 'never collected', '77');

        $this->assertSame('phone', $block->type);
        $this->assertSame('+359888123456', $block->value_key); // PH-1: the exact E.164 form (was the last nine digits)
        $this->assertSame('0888 123 456', $block->value_display);
        $this->assertSame('never collected', $block->reason);
        $this->assertSame(77, (int) $block->source_order_id);
        $this->assertEquals($this->now->copy()->addDays(180), $block->expires_at);
        $this->assertNull($block->revoked_at);
        $this->assertTrue($block->isActive($this->now));
    }

    public function test_the_key_is_the_normalised_one_for_every_type(): void
    {
        $this->service()->block(OrderBlockType::EMAIL, 'Maria@Example.COM', null);
        $this->service()->block(OrderBlockType::IP, '2001:db8:1:2:3:4:5:6', null);
        $this->service()->block(OrderBlockType::IP, '203.0.113.5', null);

        $this->assertSame(['maria@example.com', '2001:db8:1:2::', '203.0.113.5'], OrderBlockModel::query()->orderBy('id')->pluck('value_key')->all());
    }

    public function test_a_value_with_no_key_cannot_be_blocked(): void
    {
        foreach ([[OrderBlockType::PHONE, '12345'], [OrderBlockType::EMAIL, '  '], [OrderBlockType::IP, 'not-an-ip']] as [$type, $value]) {
            try {
                $this->service()->block($type, $value, 180);
                $this->fail('a value with no key was blocked');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, OrderBlockModel::query()->count());
    }

    public function test_the_duration_is_one_to_3650_days_or_forever(): void
    {
        $this->assertNull($this->service()->block(OrderBlockType::EMAIL, 'a@b.bg', null)->expires_at, 'forever');

        foreach ([0, -5, 3651] as $days) {
            try {
                $this->service()->block(OrderBlockType::EMAIL, 'c@d.bg', $days);
                $this->fail("{$days} days was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_blocking_an_identifier_that_is_already_blocked_extends_it_and_never_adds_a_row(): void
    {
        $first = $this->service()->block(OrderBlockType::PHONE, '0888 123 456', 30, 'first reason');
        Carbon::setTestNow($this->now->copy()->addDays(10));

        // Typed differently, same key: the same block.
        $again = $this->service()->block(OrderBlockType::PHONE, '+359 888 123 456', 180);

        $this->assertSame(1, OrderBlockModel::query()->count(), 'no duplicate row');
        $this->assertSame($first->id, $again->id);
        $this->assertEquals($this->now->copy()->addDays(10 + 180), $again->fresh()->expires_at, 'the new duration wins');
        $this->assertSame('first reason', $again->fresh()->reason, 'the reason stays unless a new one is given');

        $this->service()->block(OrderBlockType::PHONE, '0888123456', null, 'now permanent');
        $this->assertSame(1, OrderBlockModel::query()->count());
        $this->assertNull($again->fresh()->expires_at);
        $this->assertSame('now permanent', $again->fresh()->reason);
    }

    public function test_an_expired_or_revoked_block_does_not_stop_a_new_block_from_being_made(): void
    {
        $old = $this->service()->block(OrderBlockType::EMAIL, 'a@b.bg', 5);
        Carbon::setTestNow($this->now->copy()->addDays(6));

        $new = $this->service()->block(OrderBlockType::EMAIL, 'a@b.bg', 5);

        $this->assertNotSame($old->id, $new->id, 'the old one ended: this is a new block');
        $this->assertSame(2, OrderBlockModel::query()->count());
        $this->assertFalse($old->fresh()->isActive(Carbon::now()));
        $this->assertTrue($new->isActive(Carbon::now()));
    }

    public function test_revoking_ends_a_block_at_once_and_is_idempotent(): void
    {
        $block = $this->service()->block(OrderBlockType::PHONE, '0888 123 456', null);

        $this->service()->revoke($block);
        $stamp = $block->fresh()->revoked_at;

        $this->assertNotNull($stamp);
        $this->assertFalse($block->fresh()->isActive(Carbon::now()));
        $this->assertSame('revoked', $block->fresh()->state(Carbon::now()));

        Carbon::setTestNow($this->now->copy()->addDay());
        $this->service()->revoke($block->fresh());
        $this->assertEquals($stamp, $block->fresh()->revoked_at, 'a second revoke moves nothing');
    }

    public function test_extending_replaces_the_end_and_only_an_active_block_can_be_extended(): void
    {
        $block = $this->service()->block(OrderBlockType::EMAIL, 'a@b.bg', 5);

        $this->service()->extend($block, 90);
        $this->assertEquals($this->now->copy()->addDays(90), $block->fresh()->expires_at);

        $this->service()->extend($block->fresh(), null);
        $this->assertNull($block->fresh()->expires_at);

        $this->service()->revoke($block->fresh());
        $this->expectException(InvalidArgumentException::class);
        $this->service()->extend($block->fresh(), 5);
    }

    // ---- "active" ----------------------------------------------------------------------------------------------------------------------

    public function test_an_expired_or_revoked_block_is_never_active_and_a_permanent_one_always_is(): void
    {
        $expired = $this->service()->block(OrderBlockType::PHONE, '0888 111 111', 1);
        $revoked = $this->service()->block(OrderBlockType::PHONE, '0888 222 222', 100);
        $this->service()->revoke($revoked);
        $this->service()->block(OrderBlockType::PHONE, '0888 333 333', null);
        $live = $this->service()->block(OrderBlockType::PHONE, '0888 444 444', 100);
        Carbon::setTestNow($this->now->copy()->addDays(2));

        $active = $this->service()->activeFor(null, null, null);
        $this->assertCount(0, $active, 'no key to ask about: no result and no query');

        $found = $this->service()->activeFor(null, '+359888111111', null)->count()
            + $this->service()->activeFor(null, '+359888222222', null)->count();
        $this->assertSame(0, $found, 'expired and revoked refuse nothing');
        $this->assertSame(1, $this->service()->activeFor(null, '+359888333333', null)->count(), 'permanent');
        $this->assertSame(1, $this->service()->activeFor(null, '+359888444444', null)->count());
        $this->assertFalse($expired->fresh()->isActive(Carbon::now()));
        $this->assertTrue($live->fresh()->isActive(Carbon::now()));
    }

    public function test_one_query_finds_a_block_by_any_of_the_three_keys_and_none_without_a_key(): void
    {
        $this->service()->block(OrderBlockType::IP, '203.0.113.5', null);
        $this->service()->block(OrderBlockType::PHONE, '0888 123 456', null);
        $this->service()->block(OrderBlockType::EMAIL, 'a@b.bg', null);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(['phone'], $this->service()->activeFor('198.51.100.1', '+359888123456', 'other@x.bg')->pluck('type')->all());
        $this->assertEqualsCanonicalizing(['ip', 'phone', 'email'], $this->service()->activeFor('203.0.113.5', '+359888123456', 'a@b.bg')->pluck('type')->all());
        $this->assertCount(2, $queries, 'one query per call');

        $queries = [];
        $this->assertCount(0, $this->service()->activeFor(null, null, null));
        $this->assertSame([], $queries, 'nothing asked, nothing read');
    }

    // ---- the prune ---------------------------------------------------------------------------------------------------------------------

    public function test_prune_deletes_only_rows_that_ended_more_than_thirty_days_ago(): void
    {
        $at = fn (string $ago): Carbon => $this->now->copy()->sub($ago);
        $make = function (string $name, ?Carbon $expires, ?Carbon $revoked) {
            return OrderBlockModel::query()->create(['type' => 'email', 'value_key' => $name.'@x.bg', 'value_display' => $name.'@x.bg', 'expires_at' => $expires, 'revoked_at' => $revoked]);
        };

        $make('expired-long-ago', $at('31 days'), null);
        $make('expired-just-now', $at('29 days'), null);
        $make('revoked-long-ago', $this->now->copy()->addDays(100), $at('31 days'));
        $make('revoked-recently', $this->now->copy()->addDays(100), $at('29 days'));
        $make('permanent', null, null);
        $make('permanent-revoked-long-ago', null, $at('45 days'));
        $make('active', $this->now->copy()->addDays(5), null);

        $deleted = $this->service()->prune();

        $this->assertSame(3, $deleted);
        $this->assertEqualsCanonicalizing(['expired-just-now@x.bg', 'revoked-recently@x.bg', 'permanent@x.bg', 'active@x.bg'], OrderBlockModel::query()->pluck('value_key')->all());
    }

    public function test_the_prune_command_reports_and_logs_counts_only_and_is_scheduled_daily(): void
    {
        OrderBlockModel::query()->create(['type' => 'phone', 'value_key' => '888123456', 'value_display' => '0888 123 456', 'expires_at' => $this->now->copy()->subDays(40)]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->artisan('order-blocks:prune')->assertSuccessful()->doesntExpectOutputToContain('888')->expectsOutputToContain('Pruned 1 order block(s)');

        $this->assertSame(['order_blocks.pruned{"count":1}'], $logged);
        $this->artisan('order-blocks:prune')->assertSuccessful()->expectsOutputToContain('Pruned 0');

        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'order-blocks:prune'));
        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    // ---- the masks ----------------------------------------------------------------------------------------------------------------------

    public function test_values_are_masked_the_way_the_design_says(): void
    {
        $this->assertSame('*** *** 456', OrderBlockMasker::mask(OrderBlockType::PHONE, '+359 888 123 456'));
        $this->assertSame('m***@gmail.com', OrderBlockMasker::mask(OrderBlockType::EMAIL, 'Maria.Petrova@gmail.com'));
        $this->assertSame('203.0.*.*', OrderBlockMasker::mask(OrderBlockType::IP, '203.0.113.5'));
        $this->assertSame('2001:db8:*', OrderBlockMasker::mask(OrderBlockType::IP, '2001:db8:85a3:8d3::'));
        $this->assertSame('203.0.113.5', OrderBlockMasker::mask(OrderBlockType::IP, '203.0.113.5', fullIp: true), 'the full address only when the viewer may');
        $this->assertSame('***', OrderBlockMasker::mask(OrderBlockType::EMAIL, 'not an email'));
    }

    // ---- the activity log -----------------------------------------------------------------------------------------------------------------

    public function test_every_block_extension_and_revocation_is_logged_with_the_masked_value_never_a_full_ip(): void
    {
        $this->activityLogOn();

        $ip = $this->service()->block(OrderBlockType::IP, '203.0.113.77', 180, 'abuse');
        $phone = $this->service()->block(OrderBlockType::PHONE, '+359 888 123 456', null);
        $this->service()->block(OrderBlockType::PHONE, '0888123456', 30);
        $this->service()->revoke($ip);

        $rows = DB::table('activity_log')->where('entity_type', 'order_block')->orderBy('id')->get();

        $this->assertSame(['blocked', 'blocked', 'extended', 'revoked'], $rows->pluck('field')->all());
        $this->assertStringContainsString('ip 203.0.*.* · until 2027-04-17', (string) $rows[0]->new_value);
        $this->assertStringContainsString('phone *** *** 456 · permanent', (string) $rows[1]->new_value);
        $this->assertStringContainsString('phone *** *** 456 · permanent', (string) $rows[2]->old_value);
        $this->assertStringContainsString('until', (string) $rows[2]->new_value);
        $this->assertSame((string) $phone->id, $rows[1]->entity_id);

        $everything = json_encode($rows->all());
        $this->assertStringNotContainsString('203.0.113.77', $everything, 'a full IP never reaches the activity log');
        $this->assertStringNotContainsString('888 123 456', $everything);
        $this->assertStringNotContainsString('0888123456', $everything);
    }

    // ---- the table -----------------------------------------------------------------------------------------------------------------------

    public function test_the_table_is_what_the_design_says_and_its_names_are_short(): void
    {
        $create = (string) DB::selectOne('SHOW CREATE TABLE `order_blocks`')->{'Create Table'};

        foreach (['`type` varchar(8)', '`value_key` varchar(255)', '`value_display` varchar(255)', '`reason` varchar(500)', '`source_order_id` bigint unsigned', '`created_by` bigint unsigned', '`expires_at` timestamp NULL', '`revoked_at` timestamp NULL', '`revoked_by` bigint unsigned', 'KEY `oblk_type_key_index` (`type`,`value_key`)', 'oblk_type_check'] as $fragment) {
            $this->assertStringContainsString($fragment, $create);
        }
        $this->assertStringNotContainsString('FOREIGN KEY', $create, 'a block outlives the order and the staff member');

        foreach (array_merge(['order_blocks'], Schema::getColumnListing('order_blocks'), ['oblk_type_key_index', 'oblk_type_check']) as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }

        try {
            DB::table('order_blocks')->insert(['type' => 'fax', 'value_key' => 'x', 'value_display' => 'x']);
            $this->fail('a type outside ip | phone | email was accepted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('oblk_type_check', $e->getMessage());
        }
    }

    public function test_the_migration_is_idempotent_and_round_trips(): void
    {
        $migration = require database_path('migrations/2026_10_19_000002_create_order_blocks_table.php');
        $before = (string) DB::selectOne('SHOW CREATE TABLE `order_blocks`')->{'Create Table'};

        $migration->up();
        $migration->up();
        $this->assertSame($before, (string) DB::selectOne('SHOW CREATE TABLE `order_blocks`')->{'Create Table'});

        $migration->down();
        $this->assertFalse(Schema::hasTable('order_blocks'));
        $migration->up();
        $this->assertSame(preg_replace('/ AUTO_INCREMENT=\d+/', '', $before), preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) DB::selectOne('SHOW CREATE TABLE `order_blocks`')->{'Create Table'}));
    }
}
