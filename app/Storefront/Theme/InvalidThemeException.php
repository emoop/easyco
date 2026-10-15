<?php

namespace App\Storefront\Theme;

use RuntimeException;

/** A theme manifest that cannot be used (storefront-customization-design.md 2.1): the message says which rule it broke, never the file's content. */
final class InvalidThemeException extends RuntimeException
{
}
