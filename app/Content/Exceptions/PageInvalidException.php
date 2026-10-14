<?php

namespace App\Content\Exceptions;

use RuntimeException;

/** A page the writer refused: field => list of translated messages (the form shows them next to the fields). */
final class PageInvalidException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The page is not valid: '.implode(', ', array_keys($errors)));
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }
}
