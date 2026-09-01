<?php

namespace EasyCo\Promotions\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentPromotionRepository::save() when the underlying
 * UNIQUE(code) constraint (promotions table) is violated — a second
 * Promotion created with the same (case-insensitively normalized) code.
 * Mirrors EasyCo\Account\Exceptions\EmailAlreadyRegisteredException,
 * per CLAUDE.md rule 3 ("required pattern for any future unique-
 * constraint handling").
 */
final class PromotionCodeAlreadyExistsException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self("A Promotion with code \"{$code}\" already exists.");
    }
}
