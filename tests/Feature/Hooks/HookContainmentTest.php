<?php

namespace Tests\Feature\Hooks;

use App\Services\FreeShippingThreshold;
use App\Services\ShippingMethodInput;
use App\Services\ShippingMethodWriter;
use App\Services\ShippingZoneWriter;
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Tests\TestCase;

/** P8a: a failing ACTION listener never breaks a core write (HookRegistry::doAction); filters are not contained. */
final class HookContainmentTest extends TestCase
{
    use RefreshDatabase;

    private RecordingLogger $logger;

    private HookRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;
        $this->registry = new HookRegistry($this->logger);
        $this->app->instance(HookRegistry::class, $this->registry);
    }

    public function test_a_throwing_listener_is_logged_and_the_next_listener_still_runs(): void
    {
        $calls = [];
        $this->registry->addAction('x.happened', function () use (&$calls) {
            throw new RuntimeException('boom');
        }, 10);
        $this->registry->addAction('x.happened', function () use (&$calls) {
            $calls[] = 'second';
        }, 20);

        Hook::fire('x.happened', 'arg');

        $this->assertSame(['second'], $calls);
        $this->assertCount(1, $this->logger->records);
        [$level, , $ctx] = $this->logger->records[0];
        $this->assertSame('error', $level);
        $this->assertSame('x.happened', $ctx['hook']);
        $this->assertSame('action', $ctx['kind']);
        $this->assertStringStartsWith('Closure@', $ctx['listener']);
        $this->assertSame(RuntimeException::class, $ctx['exception']);
        $this->assertSame('boom', $ctx['message']);
        $this->assertNotEmpty($ctx['at']);
    }

    public function test_the_logged_context_never_contains_the_hook_arguments(): void
    {
        $this->registry->addAction('x.happened', function () {
            throw new RuntimeException('boom');
        });

        Hook::fire('x.happened', 'CUSTOMER-SECRET-ARG', ['nested' => 'CUSTOMER-SECRET-ARG']);

        $this->assertCount(1, $this->logger->records);
        $this->assertStringNotContainsString('CUSTOMER-SECRET-ARG', json_encode($this->logger->records));
    }

    public function test_a_real_write_survives_a_throwing_listener(): void
    {
        $this->registry->addAction('shipping.zone.created', function () {
            throw new RuntimeException('listener failed');
        });

        $zone = app(ShippingZoneWriter::class)->create('Durable', ['BG'], [], []);

        $this->assertTrue(DB::table('shipping_zones')->where('id', $zone->id())->exists());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('shipping.zone.created', $this->logger->records[0][2]['hook']);
    }

    public function test_listener_priority_order_is_unchanged(): void
    {
        $order = [];
        $this->registry->addAction('x', function () use (&$order) { $order[] = 'p20'; }, 20);
        $this->registry->addAction('x', function () use (&$order) { $order[] = 'boom'; throw new RuntimeException; }, 10);
        $this->registry->addAction('x', function () use (&$order) { $order[] = 'p10b'; }, 10);

        Hook::fire('x');

        $this->assertSame(['boom', 'p10b', 'p20'], $order);
    }

    /** Production wiring: no injected registry. HookServiceProvider::register() (HookServiceProvider.php:12) binds the singleton; the container autowires Laravel's logger into it. */
    public function test_the_production_binding_reports_to_the_laravel_logger(): void
    {
        Log::spy();
        $this->app->forgetInstance(HookRegistry::class);

        Hook::action('prod.wired', function () {
            throw new RuntimeException('boom');
        });
        Hook::fire('prod.wired', 'SECRET-ARG');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['hook'] === 'prod.wired'
            && $context['kind'] === 'action'
            && str_starts_with($context['listener'], 'Closure@')
            && ! str_contains(json_encode($context), 'SECRET-ARG'))->once();
    }

    public function test_filters_are_not_contained(): void
    {
        $this->registry->addFilter('v', function () {
            throw new RuntimeException('filter fails the write');
        });

        $this->expectException(RuntimeException::class);
        Hook::apply('v', 1);
    }

    public function test_refresh_survives_a_failing_cache_store_and_returns_the_computed_value(): void
    {
        $this->app->instance(HookRegistry::class, new HookRegistry); // the real listeners are not needed here
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.country', 'BG');
        $zone = (string) app(ShippingZoneWriter::class)->create('Bulgaria', ['BG'], [], [])->id();
        app(ShippingMethodWriter::class)->create($zone, new ShippingMethodInput(
            name: 'Courier', kind: 'flat', active: true,
            price: Money::fromMinorUnits(500, 'EUR'), freeAbove: Money::fromMinorUnits(8000, 'EUR'),
        ));

        Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('cache down'));
        Log::shouldReceive('warning')->once();

        $value = app(FreeShippingThreshold::class)->refresh();

        $this->assertSame(8000, $value['minor'] ?? null);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: mixed, 1: string, 2: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
