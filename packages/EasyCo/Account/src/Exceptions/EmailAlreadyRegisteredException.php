<?php

namespace EasyCo\Account\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentAccountRepository::save() when the underlying
 * UNIQUE(email) constraint (accounts table) is violated — a second
 * registration attempt for the same (case-insensitively normalized)
 * email. See account-domain-design.md §5.
 */
final class EmailAlreadyRegisteredException extends RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self("An account with email \"{$email}\" is already registered.");
    }
}
