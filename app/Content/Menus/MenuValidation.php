<?php

namespace App\Content\Menus;

/** The result of MenuValidator::validate(): the normalised tree and the messages by path (empty = valid). */
final class MenuValidation
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $errors  path => message
     */
    public function __construct(public readonly array $items, public readonly array $errors)
    {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
