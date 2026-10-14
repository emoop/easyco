<?php

namespace App\Services;

/**
 * The facts of the HTTP request that placed an order and need NO storage on the visitor's device (order-context-design.md, stage C2-A):
 * the client address and the user agent, read by the HTTP layer from the request itself, never from the body. A caller with no request at
 * all (a console or integration caller) passes none: the attribution row is still written, with both NULL.
 *
 * THE ADDRESS IS PERSONAL DATA: recorded for fraud, security and dispute evidence only; it is never logged, never put in an exception
 * message and never echoed in a response (section 7.2). It is the client address ONLY when the deployment names its proxies
 * (config/trustedproxy.php, section 7.4); otherwise it is the direct peer.
 */
final class CheckoutRequestFacts
{
    public function __construct(
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
