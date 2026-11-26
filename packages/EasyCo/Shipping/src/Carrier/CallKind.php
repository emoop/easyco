<?php

namespace EasyCo\Shipping\Carrier;

/** What a call is for: decides its total timeout and its response-size cap (courier-integration-design.md §10.2). */
enum CallKind: string
{
    /** The nightly download of a full list. */
    case SYNC = 'sync';
    /** A staff action waiting on the courier. */
    case ADMIN = 'admin';
    /** A poll or a webhook-triggered call. */
    case BACKGROUND = 'background';

    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::SYNC => 60,
            self::ADMIN => 15,
            self::BACKGROUND => 20,
        };
    }

    public function maxResponseBytes(): int
    {
        return $this === self::SYNC ? 20 * 1024 * 1024 : 5 * 1024 * 1024;
    }
}
