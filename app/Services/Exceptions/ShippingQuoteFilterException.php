<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A `shipping.quotes` filter returned something the quote service refuses
 * (shipping-domain-design.md §6.8): a filter may only remove a method or change
 * its amount (never below 0). The reason is a closed list so the refusal is named;
 * the message carries the method id (configuration, not personal data).
 *
 * Per the Hook error policy a faulty listener is not papered over: the quote fails
 * and the endpoint answers with a controlled error, logging this reason.
 */
final class ShippingQuoteFilterException extends RuntimeException
{
    public const NOT_A_LIST = 'not_a_list';

    public const INVALID_ITEM = 'invalid_item';

    public const UNKNOWN_METHOD = 'unknown_method';

    public const DUPLICATE_METHOD = 'duplicate_method';

    public const AVAILABILITY_CHANGED = 'availability_changed';

    public const NEGATIVE_AMOUNT = 'negative_amount';

    public const CHANGED_FIELD = 'changed_field';

    public function __construct(public readonly string $reason, ?string $methodId = null)
    {
        parent::__construct("A shipping.quotes filter was refused: {$reason}".($methodId !== null ? " (method {$methodId})" : '').'.');
    }

    public static function because(string $reason, ?string $methodId = null): self
    {
        return new self($reason, $methodId);
    }
}
