<?php

namespace App\Mail;

use RuntimeException;

/** A template cannot be rendered (unknown variable, malformed token, misplaced block, missing file). Never carries customer data. */
final class TemplateException extends RuntimeException
{
}
