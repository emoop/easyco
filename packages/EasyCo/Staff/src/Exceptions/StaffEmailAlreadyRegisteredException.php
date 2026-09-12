<?php

namespace EasyCo\Staff\Exceptions;

use RuntimeException;

/**
 * Thrown by EloquentStaffRepository::save() when the underlying
 * UNIQUE(email) constraint (staff table) is violated — a second staff
 * member registered with the same (case-insensitively normalized)
 * email. See staff-access-domain-design.md §2.
 */
final class StaffEmailAlreadyRegisteredException extends RuntimeException
{
    public static function forEmail(string $email): self
    {
        return new self("A staff member with email \"{$email}\" is already registered.");
    }
}
