<?php

namespace EasyCo\Account\Security;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * ACC-1: the account's SESSION VERSION. `accounts.session_version` is copied into the session at login (adopt()) and compared on every `auth:customer` request
 * (the EnsureAccountSessionIsCurrent middleware). bump() raises it and rotates the remember token in the same write, so after a password reset / change or an
 * email change every OTHER session and every remember cookie is dead; the session that did the change is given the new number (keep).
 */
final class AccountSessionVersion
{
    public const SESSION_KEY = 'account_session_version';

    public static function adopt(AccountModel $account, Session $session): void
    {
        $session->put(self::SESSION_KEY, (int) $account->session_version);
    }

    /** the version in the session matches the account's (a session that has none is not current) */
    public static function isCurrent(AccountModel $account, Session $session): bool
    {
        return $session->has(self::SESSION_KEY) && (int) $session->get(self::SESSION_KEY) === (int) $account->session_version;
    }

    /** @param  Session|null  $keep  the session that stays logged in (the one that made the change), null = none */
    public static function bump(AccountModel $account, ?Session $keep = null): void
    {
        AccountModel::query()->whereKey($account->getKey())->update([
            'session_version' => \Illuminate\Support\Facades\DB::raw('session_version + 1'),
            'remember_token' => Str::random(60),
        ]);

        $account->refresh();

        if ($keep !== null) {
            self::adopt($account, $keep);
        }
    }
}
