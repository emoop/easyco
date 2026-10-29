<?php

namespace EasyCo\Catalog\Exceptions;

use InvalidArgumentException;

/** AT-1: a colour of an attribute value that is not `#rrggbb`. The message is for developers; the admin shows its own translated text. */
final class InvalidAttributeColourException extends InvalidArgumentException
{
    public static function notHex(string $value): self
    {
        return new self('An attribute value colour must be #rrggbb, got "'.mb_substr($value, 0, 20).'".');
    }
}
