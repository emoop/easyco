<?php

namespace App\Http\Controllers\Api;

use App\Settings\StoreLocale;
use Closure;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\PasswordPolicy;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

/**
 * ACC-1: what the account endpoints share. Every answer that could tell whether an address has an account goes through timeboxed() (the same minimum duration for
 * every path) and says() (the same text, in the store locale: an API request has no locale of its own).
 */
trait AccountApi
{
    protected function says(string $key): string
    {
        return (string) __('account.'.$key, [], app(StoreLocale::class)->current());
    }

    /** @template T @param  Closure(): T  $work @return T */
    protected function timeboxed(Closure $work): mixed
    {
        return app(Timebox::class)->call(static fn (): mixed => $work(), (int) config('auth.account_timebox_duration', 400_000));
    }

    protected function normalised(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** the account of an address (a soft-deleted one only when asked: its email stays reserved by the unique index) */
    protected function accountOf(string $email, bool $withTrashed = false): ?AccountModel
    {
        $query = $withTrashed ? AccountModel::withTrashed() : AccountModel::query();

        return $query->where('email', $this->normalised($email))->first();
    }

    /**
     * @throws ValidationException the password breaks the rule (8-72 bytes, not the email, not a common one)
     */
    protected function assertAcceptablePassword(string $password, string $email, string $field = 'password'): void
    {
        $problem = PasswordPolicy::problem($password, $email);

        if ($problem !== null) {
            throw ValidationException::withMessages([$field => [$this->says('password.'.$problem)]]);
        }
    }

    /** counts one request of an address in `$name`'s hourly budget; true = over the budget (the caller answers the same generic answer and does nothing) */
    protected function overBudget(string $name, string $email, int $max = 3, int $decaySeconds = 3600): bool
    {
        $key = 'account.'.$name.'.'.hash('sha256', $this->normalised($email));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, $decaySeconds);

        return false;
    }
}
