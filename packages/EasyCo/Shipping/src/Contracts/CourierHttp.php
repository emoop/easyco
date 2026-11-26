<?php

namespace EasyCo\Shipping\Contracts;

use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Carrier\HttpCall;
use EasyCo\Shipping\Exceptions\CourierHttpException;

/**
 * The adapters' ONLY way to the network (courier-integration-design.md §2.2, §10.2), implemented in app/ by
 * AllowlistedCourierHttp. An adapter names a HOST (never a URL), a path and a query; the client decides whether the host
 * is allowed. A non-2xx answer is returned as a CourierResponse (the adapter maps it to its own closed codes); a call that
 * produced no answer at all throws CourierHttpException (closed code, no detail).
 */
interface CourierHttp
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers  e.g. Authorization; never logged
     *
     * @throws CourierHttpException
     */
    public function postJson(string $host, string $path, array $body, HttpCall $call, array $headers = []): CourierResponse;

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers  e.g. Authorization; never logged
     *
     * @throws CourierHttpException
     */
    public function get(string $host, string $path, array $query, HttpCall $call, array $headers = []): CourierResponse;
}
