<?php

namespace App\Privacy;

use InvalidArgumentException;

/**
 * The action keys of a privacy log row (acc-4-gdpr-design.md §3). CORE are the keys the eraser writes; a listener of `customer.erased` may report its
 * own, of the closed SHAPE `[a-z][a-z0-9_.-]{0,62}`. A value is a bool, an integer, or a plain string of at most 255 characters without control characters.
 */
final class PrivacyActions
{
    public const ACCOUNT_DELETED = 'account_deleted';

    public const ADDRESSES_DELETED = 'addresses_deleted';

    public const CARTS_DELETED = 'carts_deleted';

    public const RESET_TOKENS_DELETED = 'reset_tokens_deleted';

    public const EMAIL_CHANGES_DELETED = 'email_changes_deleted';

    public const STAFF_NOTE_DELETED = 'staff_note_deleted';

    public const MAIL_LOG_MASKED = 'mail_log_masked';

    public const SUPPRESSED_EMAIL = 'suppressed_email';

    public const SUPPRESSED_PHONE = 'suppressed_phone';

    public const COD_BLOCKS_KEPT = 'cod_blocks_kept';

    public const HOOK_CUSTOMER_ERASED = 'hook_customer_erased';

    public const CORE = [
        self::ACCOUNT_DELETED, self::ADDRESSES_DELETED, self::CARTS_DELETED, self::RESET_TOKENS_DELETED, self::EMAIL_CHANGES_DELETED, self::STAFF_NOTE_DELETED,
        self::MAIL_LOG_MASKED, self::SUPPRESSED_EMAIL, self::SUPPRESSED_PHONE, self::COD_BLOCKS_KEPT, self::HOOK_CUSTOMER_ERASED,
    ];

    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_.-]{0,62}$/D', $key) === 1;
    }

    public static function isValidValue(mixed $value): bool
    {
        if (is_bool($value) || is_int($value)) {
            return true;
        }

        return is_string($value) && mb_strlen($value) <= 255 && preg_match('/[\p{Cc}\p{Cf}]/u', $value) !== 1;
    }

    /** @param array<mixed> $actions @throws InvalidArgumentException */
    public static function assertValidActions(array $actions): void
    {
        foreach ($actions as $key => $value) {
            if (! is_string($key) || ! self::isValidKey($key) || ! self::isValidValue($value)) {
                throw new InvalidArgumentException('A privacy action is a key of the closed shape and a bool, an integer or a short plain string.');
            }
        }
    }
}
