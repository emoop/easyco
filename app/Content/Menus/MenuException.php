<?php

namespace App\Content\Menus;

use RuntimeException;

/** A menu operation that was refused; the message is already in the admin's language. `$errors` (path => message) is set for an invalid tree. */
final class MenuException extends RuntimeException
{
    /** @param  array<string, string>  $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** @param  array<string, string>  $errors */
    public static function invalid(array $errors): self
    {
        return new self(__('menus.errors.invalid'), $errors);
    }
}
