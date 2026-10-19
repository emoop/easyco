<?php

namespace App\Storefront\Menu;

use RuntimeException;

/** The compiled menus would be larger than MenuCompiler::MAX_BYTES; the message is in the admin's language. */
final class MenuCompileException extends RuntimeException
{
}
