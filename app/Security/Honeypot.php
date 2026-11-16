<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * P8d: the server side of the honeypot (checkout submit, account registration). A NON-EMPTY value of the field is a bot: `log` mode logs it and goes on (the
 * order / the account is made as before); `block` mode answers the same generic validation error the form already uses and writes nothing. There is no time-trap: the
 * HTML is cached and identical for everyone, so no server timestamp can be in it.
 */
final class Honeypot
{
    public static function field(): string
    {
        return (string) config('security.honeypot.field', 'ec_hp');
    }

    public static function tripped(Request $request): bool
    {
        $value = $request->input(self::field());

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * @param  string  $surface  `checkout` | `registration`
     * @param  string  $errorField  the field whose generic error the form already shows
     *
     * @throws ValidationException in `block` mode
     */
    public static function check(Request $request, string $surface, string $errorField): void
    {
        if (! self::tripped($request)) {
            return;
        }

        // the value is never logged (it is bot-controlled text): only that it was filled
        if (SecurityMode::decide('honeypot_'.$surface, ['ip' => (string) $request->ip()])) {
            throw ValidationException::withMessages([$errorField => __('validation.email', ['attribute' => $errorField])]);
        }
    }
}
