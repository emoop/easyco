<?php

namespace Tests\Feature;

use App\Models\OrderAttributionModel;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\OrderContextSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Order context, stage C2-A (order-context-design.md 7.2, 7.3): `privacy:anonymise-order-ips` replaces the full client address of orders older
 * than `privacy.ip_full_retention_days` (30 by default) by its anonymised form, in place, stamping `ip_anonymised_at`. Idempotent, `--dry-run`
 * writes nothing, counts (never values) are logged, and it is scheduled daily.
 */
class AnonymiseOrderIpsCommandTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Places an order from $ip whose context was captured $age ago. */
    private function orderAged(string $age, string $ip = '203.0.113.5'): string
    {
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $order = (string) $this->checkout($this->streetFields('BG'))->assertStatus(201)->json('order.id');
        DB::table('order_attributions')->where('order_id', $order)->update(['captured_at' => Carbon::now()->sub($age)]);

        return $order;
    }

    private function row(string $order): object
    {
        return DB::table('order_attributions')->where('order_id', $order)->first();
    }

    public function test_the_default_window_is_thirty_days_and_the_boundary_is_exact(): void
    {
        $before = $this->orderAged('29 days 23 hours');
        $after = $this->orderAged('30 days 1 hour');

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $this->assertSame('203.0.113.5', $this->row($before)->ip, 'inside the window: untouched');
        $this->assertNull($this->row($before)->ip_anonymised_at);
        $this->assertSame('203.0.113.0', $this->row($after)->ip, 'past the window: anonymised in place');
        $this->assertNotNull($this->row($after)->ip_anonymised_at, 'and the date it happened is stamped');
    }

    public function test_the_window_is_the_merchants_setting(): void
    {
        app(SiteSettingsRepository::class)->set(OrderContextSettings::IP_RETENTION_DAYS_KEY, '7');
        $young = $this->orderAged('6 days');
        $old = $this->orderAged('8 days');

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $this->assertNull($this->row($young)->ip_anonymised_at);
        $this->assertNotNull($this->row($old)->ip_anonymised_at);
    }

    public function test_an_ipv6_address_keeps_only_its_routing_prefix(): void
    {
        $order = $this->orderAged('40 days', '2001:db8:85a3:8d3:1319:8a2e:370:7348');

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $this->assertSame('2001:db8:85a3:8d3::', $this->row($order)->ip);
    }

    public function test_a_second_run_changes_nothing_and_logs_nothing(): void
    {
        $this->orderAged('40 days');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message;
        });

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();
        $stamped = DB::table('order_attributions')->value('ip_anonymised_at');
        $updated = DB::table('order_attributions')->value('updated_at');
        $this->assertSame(['privacy.order_ips_anonymised'], $logged);

        $logged = [];
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful()->expectsOutputToContain('Anonymised 0 order address(es)');

        $this->assertSame($stamped, DB::table('order_attributions')->value('ip_anonymised_at'), 'the date it was anonymised is not moved');
        $this->assertSame($updated, DB::table('order_attributions')->value('updated_at'), 'the row is not touched again');
        $this->assertSame('203.0.113.0', DB::table('order_attributions')->value('ip'), 'and an anonymised address is not anonymised twice');
        $this->assertSame([], $logged, 'nothing to say, nothing logged');
    }

    public function test_dry_run_writes_nothing_and_says_how_many(): void
    {
        $this->orderAged('40 days');
        $this->orderAged('50 days');
        $this->orderAged('1 day');

        $this->artisan('privacy:anonymise-order-ips --dry-run')->assertSuccessful()->expectsOutputToContain('2 order address(es)');

        $this->assertSame(0, DB::table('order_attributions')->whereNotNull('ip_anonymised_at')->count());
        $this->assertSame(3, DB::table('order_attributions')->where('ip', '203.0.113.5')->count(), 'every full address is still there');
    }

    public function test_a_retention_of_zero_anonymises_everything_at_once(): void
    {
        // Placed under the default window, then the merchant sets "never keep the full address": the command sweeps it at once.
        $order = $this->orderAged('1 minute');
        app(SiteSettingsRepository::class)->set(OrderContextSettings::IP_RETENTION_DAYS_KEY, '0');

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $this->assertSame('203.0.113.0', $this->row($order)->ip);
    }

    public function test_rows_without_an_address_or_already_anonymised_are_left_alone(): void
    {
        $none = $this->orderAged('90 days');
        DB::table('order_attributions')->where('order_id', $none)->update(['ip' => null]);

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $this->assertNull($this->row($none)->ip);
        $this->assertNull($this->row($none)->ip_anonymised_at, 'an erased or never-captured address is not "anonymised on" anything');
    }

    public function test_no_log_line_output_or_activity_row_ever_carries_an_address(): void
    {
        $this->orderAged('40 days', '203.0.113.77');
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });

        $this->artisan('privacy:anonymise-order-ips --dry-run')->doesntExpectOutputToContain('203.0.113')->assertSuccessful();
        $this->artisan('privacy:anonymise-order-ips')->doesntExpectOutputToContain('203.0.113')->assertSuccessful();

        $this->assertNotEmpty($logged, 'the run did log (the check is not vacuous)');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('203.0.113', $line);
        }
        $this->assertStringNotContainsString('203.0.113', json_encode(DB::table('activity_log')->get()->all()));
        $this->assertStringContainsString('"count":1', $logged[0], 'counts, never values');
    }

    public function test_it_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => str_contains((string) $event->command, 'privacy:anonymise-order-ips'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()->expression);
    }

    public function test_the_model_is_the_only_thing_it_rewrites(): void
    {
        $order = $this->orderAged('40 days');
        $before = (array) $this->row($order);

        $this->artisan('privacy:anonymise-order-ips')->assertSuccessful();

        $after = (array) $this->row($order);
        $changed = array_values(array_diff(array_keys(array_diff_assoc($after, $before)), ['updated_at']));
        sort($changed);

        $this->assertSame(['ip', 'ip_anonymised_at'], $changed, 'only the address and the stamp (the update time of the row aside)');
        $this->assertSame(1, OrderAttributionModel::query()->count());
    }
}
