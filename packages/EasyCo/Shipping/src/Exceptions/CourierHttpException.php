<?php

namespace EasyCo\Shipping\Exceptions;

use RuntimeException;

/**
 * A call that never produced a usable answer. The message is ONLY a closed code: never a URL, a courier's reply, a body or
 * a header, so it is safe to log and to show to staff.
 */
final class CourierHttpException extends RuntimeException
{
    public const HOST_NOT_ALLOWED = 'host_not_allowed';

    public const SCHEME_NOT_ALLOWED = 'scheme_not_allowed';

    public const REDIRECT = 'redirect_refused';

    public const TOO_LARGE = 'response_too_large';

    public const TIMEOUT = 'timeout';

    public const CONNECTION = 'connection_failed';

    public const UNAVAILABLE = 'courier_unavailable';

    public const INVALID_REQUEST = 'invalid_request';

    /** The call needs credentials that are not filled in. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The courier refused the credentials. */
    public const AUTH_FAILED = 'auth_failed';

    /** The courier answered, but not with what its contract says (e.g. a list that is not a list). */
    public const INVALID_RESPONSE = 'invalid_response';

    /** A settlement list far shorter than the one in use (or empty): the build is refused and nothing is written. */
    public const SETTLEMENTS_SHRANK = 'settlements_shrank';

    public function __construct(public readonly string $closedCode, public readonly ?int $httpStatus = null)
    {
        parent::__construct($closedCode);
    }
}
