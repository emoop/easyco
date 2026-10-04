<?php

namespace App\Services;

/**
 * The lifetimes of shipping-domain-design.md §6.5, in one place. Seconds.
 */
final class QuoteCachePolicy
{
    /** A carrier's ANSWER (including "no quotes"), and a quote handle: long enough to cover a customer deciding. */
    public const ANSWER_TTL = 600;

    /** An UNAVAILABLE carrier result: short, so a failing carrier is not hammered yet recovers quickly. */
    public const UNAVAILABLE_TTL = 30;

    public const HANDLE_TTL = 600;

    /** The time a single carrier call may take (handed to the provider, which must honour it). */
    public const CARRIER_BUDGET_MS = 3000;
}
