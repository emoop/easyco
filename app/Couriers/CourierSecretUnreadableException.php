<?php

namespace App\Couriers;

use RuntimeException;

/** A stored courier secret cannot be decrypted (APP_KEY changed). The message never carries the value or the key name's content. */
final class CourierSecretUnreadableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A stored courier secret cannot be read; enter it again.');
    }
}
