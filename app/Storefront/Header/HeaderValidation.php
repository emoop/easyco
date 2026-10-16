<?php

namespace App\Storefront\Header;

/** The result of HeaderValidator::validate(): the normalised layout and the error messages by path (empty = valid). */
final class HeaderValidation
{
    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, string>  $errors
     */
    public function __construct(public readonly array $layout, public readonly array $errors)
    {
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }
}
