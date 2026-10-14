<?php

namespace App\Content\Exceptions;

use RuntimeException;

/** A page that cannot be deleted because something points at it; the message says what, in the store's language. */
final class PageInUseException extends RuntimeException
{
}
