<?php

namespace App\Filament\Pages\Auth;

use App\Security\Lockout\AdminLoginLockout;
use App\Security\SecurityMode;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Timebox;

/**
 * P8d: Filament's login page + the account/IP lockout (AdminLoginLockout) on top of its own per-IP limit.
 *
 *  - BEFORE the credentials are looked at: a (account, IP) pair that is locked gets EXACTLY the answer of a wrong password (`throwFailureValidationException()`,
 *    inside a Timebox so it takes as long as one): the page never says that the account exists or that it is locked. In `SECURITY_MODE=log` the lock is only logged.
 *  - A credential failure is counted where Filament says «the credentials were wrong» (`throwFailureValidationException()` is called for exactly that, a wrong
 *    password and a user who may not enter the panel alike; a missing field or Filament's own rate limit never reach it).
 *  - A successful login resets the counter.
 *
 * Retry-After: the login is a Livewire action, whose answer is a component update: there is no place for the header, and the generic message must not carry a time anyway.
 */
class AdminLogin extends Login
{
    public function authenticate(): ?LoginResponse
    {
        $email = (string) ($this->data['email'] ?? '');
        $ip = (string) request()->ip();
        $lockout = app(AdminLoginLockout::class);

        if ($email !== '' && $lockout->lockedFor($email, $ip) > 0 && SecurityMode::decide('admin_login_lockout', ['email_hash' => AdminLoginLockout::hash($email), 'ip' => $ip])) {
            // the same message, the same duration: a locked pair cannot be told from a wrong password
            app(Timebox::class)->call(function (): void {
                $this->throwFailureValidationException(false);
            }, (int) config('auth.timebox_duration', 200_000));
        }

        $response = parent::authenticate();

        if ($response !== null && $email !== '') {
            $lockout->recordSuccess($email, $ip);
        }

        return $response;
    }

    protected function throwFailureValidationException(bool $count = true): never
    {
        $email = (string) ($this->data['email'] ?? '');

        if ($count && $email !== '') {
            app(AdminLoginLockout::class)->recordFailure($email, (string) request()->ip());
        }

        parent::throwFailureValidationException();
    }
}
