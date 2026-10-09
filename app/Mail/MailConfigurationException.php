<?php

namespace App\Mail;

use RuntimeException;

/** The stored mail settings cannot be used. The message never contains a secret. */
final class MailConfigurationException extends RuntimeException
{
}
