<?php

namespace App\Couriers;

use Closure;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Shipping\Exceptions\CourierHttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * THE ONE OUTBOUND CLIENT for couriers (courier-integration-design.md §10.2): the first and, until another is written, only
 * place in app/ that sends a request to a third party for a courier. An adapter names a HOST, a path and a query; this
 * class decides whether the call may happen at all.
 *
 *  - ALLOWLIST: the host must be exactly one of the declared hosts of THE CALLING COURIER for its CURRENT environment, and that
 *    courier must be enabled (a disabled courier has no allowed host, so it cannot be called). Stricter than the design's
 *    "union of the enabled couriers' hosts": one courier cannot use another's host. A host that is not a bare lower-case DNS
 *    name is refused before anything is built; there is no DNS lookup for a refused host.
 *  - https and port 443 only: the URL is always built as `https://<host><path>` with no port.
 *  - REDIRECTS ARE NOT FOLLOWED: a 3xx is an error (REDIRECT).
 *  - TIMEOUTS: connect 3 s, total per CallKind (sync 60, admin 15, background 20).
 *  - RETRIES: a READ is retried at most twice (1 s, then 3 s) on a connection error or a 502/503/504; a read that TIMED OUT
 *    is not retried (a 60 s sync must not become 3 minutes). A WRITE is never retried here (the shipments service decides, §6.3).
 *  - RESPONSE CAP: 20 MB for a sync download, 5 MB for everything else.
 *  - LOGGING: carrier, operation, status, duration and the correlation id. NEVER a URL, a query, a body or a header.
 *    Exceptions thrown carry only a closed code.
 */
final class AllowlistedCourierHttp implements CourierHttp
{
    private const CONNECT_TIMEOUT = 3;

    /** Seconds slept before retry 1 and retry 2. */
    private const BACKOFF = [1, 3];

    /** @var Closure(int): void */
    private Closure $sleep;

    public function __construct(private readonly CourierDirectory $couriers, ?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function postJson(string $host, string $path, array $body, HttpCall $call, array $headers = []): CourierResponse
    {
        return $this->send('POST', $host, $path, ['json' => $body], $call, $headers);
    }

    public function get(string $host, string $path, array $query, HttpCall $call, array $headers = []): CourierResponse
    {
        return $this->send('GET', $host, $path, ['query' => $query], $call, $headers);
    }

    /** @param array{json?: array<string, mixed>, query?: array<string, scalar>} $payload @param array<string, string> $headers */
    private function send(string $method, string $host, string $path, array $payload, HttpCall $call, array $headers): CourierResponse
    {
        $started = hrtime(true);
        $status = null;
        $code = null;

        try {
            $this->assertAllowed($host, $path, $call);

            $response = $this->attempt($method, $host, $path, $payload, $call, $headers);
            $status = $response->status;

            return $response;
        } catch (CourierHttpException $e) {
            $code = $e->storedCode();
            $status = $e->httpStatus ?? $status;

            throw $e;
        } finally {
            Log::info('Courier call.', [
                'carrier' => $call->carrierCode,
                'operation' => $call->operation,
                'status' => $status,
                'error' => $code,
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'correlation_id' => $call->correlationId,
            ]);
        }
    }

    private function assertAllowed(string $host, string $path, HttpCall $call): void
    {
        // A bare lower-case DNS name only: no scheme, port, path, userinfo, query, space or IP literal can pass this.
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/D', $host) !== 1) {
            throw new CourierHttpException(
                preg_match('~^[a-z][a-z0-9+.-]*://~i', $host) === 1 ? CourierHttpException::SCHEME_NOT_ALLOWED : CourierHttpException::HOST_NOT_ALLOWED,
            );
        }

        if (! in_array($host, $this->couriers->allowedHosts($call->carrierCode), true)) {
            throw new CourierHttpException(CourierHttpException::HOST_NOT_ALLOWED);
        }

        if (preg_match("#^/[A-Za-z0-9._~!\$&'()*+,;=:@%/-]*\$#D", $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            throw new CourierHttpException(CourierHttpException::INVALID_REQUEST);
        }
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    private function attempt(string $method, string $host, string $path, array $payload, HttpCall $call, array $headers): CourierResponse
    {
        $retries = $call->retryable ? count(self::BACKOFF) : 0;
        $cap = $call->kind->maxResponseBytes();

        for ($try = 0; ; $try++) {
            try {
                $response = $this->request($call, $headers, $cap)->send($method, 'https://'.$host.$path, $payload);
            } catch (CourierHttpException $e) {
                throw $e;
            } catch (ConnectionException $e) {
                $timedOut = stripos($e->getMessage(), 'operation timed out') !== false || stripos($e->getMessage(), 'timeout of') !== false;

                if ($timedOut) {
                    throw new CourierHttpException(CourierHttpException::TIMEOUT);
                }

                if ($try < $retries) {
                    ($this->sleep)(self::BACKOFF[$try]);

                    continue;
                }

                throw new CourierHttpException(CourierHttpException::CONNECTION);
            } catch (Throwable $e) {
                // A size abort raised inside the transfer, or anything else the HTTP layer throws: a closed code, never its message.
                throw new CourierHttpException($e instanceof ResponseTooLarge ? CourierHttpException::TOO_LARGE : ($e->getPrevious() instanceof ResponseTooLarge ? CourierHttpException::TOO_LARGE : CourierHttpException::CONNECTION));
            }

            $status = $response->status();

            if ($status >= 300 && $status < 400) {
                throw new CourierHttpException(CourierHttpException::REDIRECT, $status);
            }

            if (in_array($status, [502, 503, 504], true) && $try < $retries) {
                ($this->sleep)(self::BACKOFF[$try]);

                continue;
            }

            $body = $response->body();

            if (strlen($body) > $cap) {
                throw new CourierHttpException(CourierHttpException::TOO_LARGE, $status);
            }

            return new CourierResponse($status, $body);
        }
    }

    /** @param array<string, string> $headers */
    private function request(HttpCall $call, array $headers, int $cap): PendingRequest
    {
        return Http::withHeaders($headers + ['User-Agent' => 'EasyCo-Courier/1', 'Accept' => 'application/json'])
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($call->kind->timeoutSeconds())
            ->withOptions([
                'allow_redirects' => false,
                // Abort a download that is going over the cap, without waiting for the whole body.
                'on_headers' => static function (ResponseInterface $response) use ($cap): void {
                    $length = $response->getHeaderLine('Content-Length');

                    if ($length !== '' && ctype_digit($length) && (int) $length > $cap) {
                        throw new ResponseTooLarge;
                    }
                },
                'progress' => static function ($downloadTotal, $downloadedNow) use ($cap): void {
                    if ($downloadedNow > $cap) {
                        throw new ResponseTooLarge;
                    }
                },
            ]);
    }
}
