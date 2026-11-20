<?php

namespace App\Privacy;

/**
 * The payload of the `customer.erased` action: the person who was just forgotten, for the listeners that keep lists or audiences.
 * Listeners remove the person and report what they did through PrivacyLog::addAction($requestId, 'their_key').
 */
final class ErasedCustomer
{
    /** @param list<string> $phones every phone collected for this person (E.164 where it could be read) */
    public function __construct(
        public readonly int $requestId,
        public readonly string $email,
        public readonly array $phones,
    ) {
    }
}
