<?php

use App\Http\TrustedProxyList;

/**
 * The reverse proxies (Varnish, a load balancer, a CDN) whose forwarded headers the application may believe — read by Laravel's own global
 * TrustProxies middleware (`config('trustedproxy.proxies')`). OPERATOR configuration, not a Site Setting: a merchant cannot be asked to
 * know the load balancer's addresses (order-context-design.md section 7.4).
 *
 * `TRUSTED_PROXIES` is a comma-separated list of IP addresses and CIDR ranges ("10.0.0.5,10.0.1.0/24"). Empty or unset trusts NO proxy, so
 * `$request->ip()` is the direct peer and a forged `X-Forwarded-For` is never recorded. A wildcard is never accepted.
 * Behind Varnish or any load balancer it MUST be set, or every order records the proxy's own address (production-requirements.md).
 */
return [
    'proxies' => TrustedProxyList::parse(env('TRUSTED_PROXIES')),
];
