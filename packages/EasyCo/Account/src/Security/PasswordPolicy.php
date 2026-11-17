<?php

namespace EasyCo\Account\Security;

/**
 * ACC-1: the ONE password rule of the storefront accounts (register, reset, change): 8 to 72 BYTES (bcrypt reads no more than 72), not the email,
 * not in the small local list of common passwords (resources/security/common-passwords.txt; checked case-insensitively; no external service).
 */
final class PasswordPolicy
{
    public const MIN_BYTES = 8;

    public const MAX_BYTES = 72;

    /** @var array<string, true>|null */
    private static ?array $common = null;

    /** @return string|null the key of the first broken rule (lang `account.password.*`), null = acceptable */
    public static function problem(string $password, string $email = ''): ?string
    {
        $bytes = strlen($password);

        if ($bytes < self::MIN_BYTES) {
            return 'too_short';
        }

        if ($bytes > self::MAX_BYTES) {
            return 'too_long';
        }

        if ($email !== '' && mb_strtolower($password) === mb_strtolower(trim($email))) {
            return 'same_as_email';
        }

        if (isset(self::common()[mb_strtolower($password)])) {
            return 'common';
        }

        return null;
    }

    /** @return array<string, true> */
    private static function common(): array
    {
        if (self::$common === null) {
            $path = resource_path('security/common-passwords.txt');
            $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
            self::$common = array_fill_keys(array_map(static fn (string $line): string => mb_strtolower(trim($line)), $lines), true);
        }

        return self::$common;
    }
}
