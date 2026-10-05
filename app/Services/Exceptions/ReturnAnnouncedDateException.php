<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The date the customer announced a return (shipping-domain-design.md §7.2.6) is
 * impossible: after the moment the return is recorded, or before the order was
 * placed. A FACT is validated for being possible, never for being on time — no
 * deadline is enforced anywhere. Thrown inside the order-locked transaction before
 * anything is written, so the whole operation rolls back. The message is a
 * translated sentence (orders.return_announced_date.*).
 */
final class ReturnAnnouncedDateException extends RuntimeException
{
    public const IN_FUTURE = 'in_future';

    public const BEFORE_PLACEMENT = 'before_placement';

    private function __construct(public readonly string $reason)
    {
        parent::__construct(__('orders.return_announced_date.'.$reason));
    }

    public static function inFuture(): self
    {
        return new self(self::IN_FUTURE);
    }

    public static function beforePlacement(): self
    {
        return new self(self::BEFORE_PLACEMENT);
    }
}
