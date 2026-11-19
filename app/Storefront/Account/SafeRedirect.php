<?php

namespace App\Storefront\Account;

/**
 * ACC-3a: the ONE rule for where a login may send the browser afterwards. Only a same-origin RELATIVE path: it starts with a single `/`; never `//`
 * (protocol-relative), never a backslash anywhere (browsers read `/\host` as `//host`), never a space or control character (a browser strips a tab or a
 * newline, so `/<tab>/host` would become `//host`), never a scheme. Anything else is the account page. storefront-account.js applies the same rule again.
 */
final class SafeRedirect
{
    public const DEFAULT = '/account';

    public static function path(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2000) {
            return self::DEFAULT;
        }

        if ($value[0] !== '/' || (isset($value[1]) && ($value[1] === '/' || $value[1] === '\\'))) {
            return self::DEFAULT;
        }

        if (preg_match('/[\\\\\x00-\x20\x7f]/', $value) === 1) {
            return self::DEFAULT;
        }

        return $value;
    }
}
