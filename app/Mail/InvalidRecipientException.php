<?php

namespace App\Mail;

use RuntimeException;

/** The stored recipient address is not a single valid address. Permanent: the mail is marked failed, not retried. */
final class InvalidRecipientException extends RuntimeException
{
}
