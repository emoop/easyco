<?php

namespace Tests\Feature\Couriers;

use App\Couriers\AllowlistedCourierHttp;
use App\Couriers\CourierDirectory;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Carrier\CallKind;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EnablesBoxNow;
use Tests\TestCase;

/** courier-integration-design.md §10.2: the one outbound client. No request reaches a real host: Http is faked everywhere. */
class AllowlistedCourierHttpTest extends TestCase
{
    use EnablesBoxNow;
    use RefreshDatabase;

    private const LIST_HOST = 'locationapi-stage.boxnow.bg';

    /** @var list<int> seconds the client asked to sleep between retries (no real sleeping in a test) */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->slept = [];
        Http::preventStrayRequests();
    }

    /** Http::fake() ADDS to the stubs already registered, and the first match wins: a second scenario needs a fresh factory. */
    private function freshHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function client(): AllowlistedCourierHttp
    {
        return new AllowlistedCourierHttp(app(CourierDirectory::class), function (int $seconds): void {
            $this->slept[] = $seconds;
        });
    }

    private function read(CallKind $kind = CallKind::ADMIN): HttpCall
    {
        return HttpCall::read('boxnow', 'locker_list', $kind, 'corr-1');
    }

    private function refused(callable $call): string
    {
        try {
            $call();
        } catch (CourierHttpException $e) {
            return $e->closedCode;
        }

        $this->fail('the call must be refused');
    }

    public function test_an_allowed_host_is_called_over_https_without_a_port(): void
    {
        $this->enableBoxNow();
        Http::fake(['*' => Http::response('[]', 200)]);

        $response = $this->client()->get(self::LIST_HOST, '/v1/apms_bg-BG.json', [], $this->read());

        $this->assertSame(200, $response->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://'.self::LIST_HOST.'/v1/apms_bg-BG.json');
    }

    public function test_another_host_is_refused_before_any_request(): void
    {
        $this->enableBoxNow();
        Http::fake();

        foreach (['evil.example.com', 'locationapi-production.boxnow.bg' /* the other environment's host */, 'boxnow.bg', 'x.'.self::LIST_HOST] as $host) {
            $this->assertSame('host_not_allowed', $this->refused(fn () => $this->client()->get($host, '/v1/x', [], $this->read())), $host);
        }

        Http::assertNothingSent();
    }

    public function test_http_a_port_userinfo_an_ip_and_an_upper_case_host_are_refused(): void
    {
        $this->enableBoxNow();
        Http::fake();

        $this->assertSame('scheme_not_allowed', $this->refused(fn () => $this->client()->get('http://'.self::LIST_HOST, '/v1/x', [], $this->read())));
        $this->assertSame('scheme_not_allowed', $this->refused(fn () => $this->client()->get('https://'.self::LIST_HOST, '/v1/x', [], $this->read())));

        foreach ([self::LIST_HOST.':8443', self::LIST_HOST.':443', 'user@'.self::LIST_HOST, '127.0.0.1', strtoupper(self::LIST_HOST), self::LIST_HOST.'/x', self::LIST_HOST.' '] as $host) {
            $this->assertSame('host_not_allowed', $this->refused(fn () => $this->client()->get($host, '/v1/x', [], $this->read())), $host);
        }

        Http::assertNothingSent();
    }

    public function test_a_path_cannot_carry_a_query_a_fragment_or_a_traversal(): void
    {
        $this->enableBoxNow();
        Http::fake();

        foreach (['v1/x', '/v1/x?a=1', '/v1/x#f', '/v1/../x', '//evil.example.com/x', "/v1/x\r\nHost: evil"] as $path) {
            $this->assertSame('invalid_request', $this->refused(fn () => $this->client()->get(self::LIST_HOST, $path, [], $this->read())), $path);
        }

        Http::assertNothingSent();
    }

    public function test_a_disabled_courier_has_no_allowed_host(): void
    {
        Http::fake();

        // Installed but never enabled: even its own declared host is refused.
        $this->assertSame('host_not_allowed', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read())));

        $this->enableBoxNow();
        app(SiteSettingsRepository::class)->forget('couriers.boxnow.enabled');

        $this->assertSame('host_not_allowed', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read())));
        Http::assertNothingSent();
    }

    public function test_the_api_host_is_allowed_only_while_it_is_on_the_fixed_boxnow_domains(): void
    {
        $this->enableBoxNow();
        Http::fake(['*' => Http::response('{}', 200)]);

        $this->assertSame(200, $this->client()->postJson(self::FAKE_API_HOST, '/api/v1/auth-sessions', ['a' => 1], HttpCall::read('boxnow', 'auth_sessions', CallKind::ADMIN))->status);

        // A tampered stored value that is not a BOX NOW domain never becomes a host.
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.api_host', 'evil.example.com');

        $this->assertSame('host_not_allowed', $this->refused(fn () => $this->client()->postJson('evil.example.com', '/api/v1/auth-sessions', [], HttpCall::read('boxnow', 'auth_sessions', CallKind::ADMIN))));
    }

    public function test_a_redirect_is_an_error_and_is_not_followed(): void
    {
        $this->enableBoxNow();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example.com/'])]);

        $this->assertSame('redirect_refused', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read())));
        Http::assertSentCount(1);
    }

    public function test_a_read_is_retried_twice_with_a_back_off_on_502_503_504(): void
    {
        $this->enableBoxNow();
        Http::fake(['*' => Http::sequence()->push('', 503)->push('', 502)->push('{"ok":true}', 200)]);

        $response = $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read());

        $this->assertSame(200, $response->status);
        $this->assertSame([1, 3], $this->slept);
        Http::assertSentCount(3);
    }

    public function test_a_read_that_keeps_failing_gives_up_after_two_retries_and_a_500_is_not_retried(): void
    {
        $this->enableBoxNow();
        Http::fake(['*' => Http::sequence()->push('', 504)->push('', 504)->push('', 504)->push('', 200)]);

        $this->assertSame(504, $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read())->status, 'the last answer is returned for the adapter to map');
        $this->assertSame([1, 3], $this->slept);
        Http::assertSentCount(3);

        $this->slept = [];
        $this->freshHttp();
        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame(500, $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read())->status);
        $this->assertSame([], $this->slept);
        Http::assertSentCount(1);
    }

    public function test_a_read_is_retried_on_connection_errors_then_fails_with_a_closed_code(): void
    {
        $this->enableBoxNow();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 6: Could not resolve host: '.self::LIST_HOST.' secret-query-text');
        });

        $code = $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read()));

        $this->assertSame('connection_failed', $code);
        $this->assertSame(3, $attempts);
        $this->assertSame([1, 3], $this->slept);
    }

    public function test_a_timeout_is_not_retried(): void
    {
        $this->enableBoxNow();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds');
        });

        $this->assertSame('timeout', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read())));
        $this->assertSame(1, $attempts);
    }

    public function test_a_write_is_never_retried(): void
    {
        $this->enableBoxNow();
        Http::fake(['*' => Http::sequence()->push('', 503)->push('{}', 200)]);

        $response = $this->client()->postJson(self::FAKE_API_HOST, '/api/v1/delivery-requests', ['x' => 1], HttpCall::write('boxnow', 'create', CallKind::ADMIN));

        $this->assertSame(503, $response->status);
        $this->assertSame([], $this->slept);
        Http::assertSentCount(1);

        // A connection error on a write is not retried either.
        $attempts = 0;
        $this->freshHttp();
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $this->assertSame('connection_failed', $this->refused(fn () => $this->client()->postJson(self::FAKE_API_HOST, '/api/v1/delivery-requests', [], HttpCall::write('boxnow', 'create', CallKind::ADMIN))));
        $this->assertSame(1, $attempts);
    }

    public function test_the_response_cap_is_20_mb_for_a_sync_and_5_mb_for_everything_else(): void
    {
        $this->enableBoxNow();
        $six = str_repeat('a', 6 * 1024 * 1024);
        Http::fake(['*' => Http::response($six, 200)]);

        $this->assertSame('response_too_large', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read(CallKind::ADMIN))));
        $this->assertSame('response_too_large', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read(CallKind::BACKGROUND))));
        $this->assertSame(200, $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read(CallKind::SYNC))->status);

        $this->freshHttp();
        Http::fake(['*' => Http::response(str_repeat('a', 21 * 1024 * 1024), 200)]);
        $this->assertSame('response_too_large', $this->refused(fn () => $this->client()->get(self::LIST_HOST, '/v1/x', [], $this->read(CallKind::SYNC))));
    }

    public function test_the_log_has_the_call_facts_and_nothing_of_a_url_query_body_or_header(): void
    {
        $this->enableBoxNow();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });
        Http::fake(['*' => Http::response('RESPONSE-BODY-TEXT', 200)]);

        $this->client()->get(self::LIST_HOST, '/v1/secret-path', ['token' => 'QUERY-SECRET'], $this->read(), ['Authorization' => 'Bearer HEADER-SECRET']);
        $this->client()->postJson(self::FAKE_API_HOST, '/api/v1/auth-sessions', ['client_secret' => 'BODY-SECRET'], HttpCall::read('boxnow', 'auth_sessions', CallKind::ADMIN, 'corr-2'), ['Authorization' => 'Bearer HEADER-SECRET']);

        $courierLogs = array_values(array_filter($logged, static fn (string $line): bool => str_contains($line, 'Courier call')));
        $this->assertCount(2, $courierLogs);
        $this->assertStringContainsString('"carrier":"boxnow"', $courierLogs[0]);
        $this->assertStringContainsString('"operation":"locker_list"', $courierLogs[0]);
        $this->assertStringContainsString('"status":200', $courierLogs[0]);
        $this->assertStringContainsString('"duration_ms"', $courierLogs[0]);
        $this->assertStringContainsString('"correlation_id":"corr-1"', $courierLogs[0]);
        $this->assertStringContainsString('"correlation_id":"corr-2"', $courierLogs[1]);

        $all = implode("\n", $logged);

        foreach (['QUERY-SECRET', 'HEADER-SECRET', 'BODY-SECRET', 'RESPONSE-BODY-TEXT', 'secret-path', 'https://', 'auth-sessions'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $all, "the log must not contain: {$forbidden}");
        }
    }

    public function test_a_refused_call_is_logged_with_its_closed_code_only(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        Http::fake();

        $this->refused(fn () => $this->client()->get('evil.example.com', '/v1/leaky-path', ['q' => 'LEAKY-QUERY'], $this->read()));

        $line = implode("\n", $logged);
        $this->assertStringContainsString('"error":"host_not_allowed"', $line);
        $this->assertStringNotContainsString('evil.example.com', $line);
        $this->assertStringNotContainsString('LEAKY', $line);
    }
}
