<?php

namespace App\Security\Lockout;

/**
 * P8d: the admin login lockout, on top of Filament's own per-IP limit (`rateLimit(5)`). Since ACC-1 the rules live in LoginLockout (scope `admin`); this class keeps
 * the admin's name and the one thing only the admin has: every counted failure also feeds the account-wide alarm (AdminLoginAlarm, mail to staff).
 * The admin cache keys are the ones P8d wrote (no scope segment), so a lock that is running survives the deploy.
 */
final class AdminLoginLockout extends LoginLockout
{
    public function __construct()
    {
        parent::__construct('admin');
    }

    protected function failed(string $email): void
    {
        app(AdminLoginAlarm::class)->failure($email);
    }
}
