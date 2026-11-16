<?php

namespace App\Security\Lockout;

use App\Security\SecurityMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P8d: the admin login lockout, on top of Filament's own per-IP limit (`rateLimit(5)`).
 *
 * COUNTED on failed credential submits only, KEYED by account (the normalised email) + IP: an attacker cannot lock the real owner out from his own IP. Every
 * `failures_per_step` (5) failures start a lock: the 1st 5 min, the 2nd 30 min, the 3rd and later 24 h. A submit that arrives during a lock is refused BEFORE the
 * credentials are looked at and is NOT counted. The counter decays `decay` (24 h) after the LAST failure; a success resets it.
 *
 * The answer while locked is decided by the login page (the same generic message as a wrong password). The state lives in the cache only (no table); the email is
 * never stored or logged raw: its sha256 is the key and the log field.
 */
final class AdminLoginLockout
{
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function hash(string $email): string
    {
        return hash('sha256', self::normalise($email));
    }

    private static function key(string $email, string $ip): string
    {
        return 'security.lockout.'.self::hash($email).'.'.hash('sha256', $ip);
    }

    /** @return int the seconds the (account, IP) pair is still locked for; 0 = not locked */
    public function lockedFor(string $email, string $ip): int
    {
        $state = Cache::get(self::key($email, $ip));
        $until = is_array($state) ? (int) ($state['until'] ?? 0) : 0;

        return max(0, $until - now()->getTimestamp());
    }

    /** a failed credential submit: counts, may start a lock, feeds the account-wide alarm */
    public function recordFailure(string $email, string $ip): void
    {
        $key = self::key($email, $ip);
        $state = Cache::get($key);
        $state = is_array($state) ? $state : ['failures' => 0, 'until' => 0];
        $state['failures'] = (int) $state['failures'] + 1;

        $perStep = max(1, (int) config('security.lockout.failures_per_step', 5));

        if ($state['failures'] % $perStep === 0) {
            $step = intdiv($state['failures'], $perStep);
            $locks = array_values((array) config('security.lockout.locks', [300, 1800, 86400]));
            $seconds = (int) $locks[min($step, count($locks)) - 1];
            $state['until'] = now()->getTimestamp() + $seconds;

            // every lock is logged (the email hashed), in both modes: `log` mode only decides whether the lock is ENFORCED
            Log::warning('Admin login locked.', ['email_hash' => self::hash($email), 'ip' => $ip, 'step' => $step, 'seconds' => $seconds, 'enforced' => SecurityMode::blocks()]);
        }

        // the counter decays 24 h after the LAST failure (the TTL restarts on every failure)
        Cache::put($key, $state, (int) config('security.lockout.decay', 86400));

        app(AdminLoginAlarm::class)->failure($email);
    }

    public function recordSuccess(string $email, string $ip): void
    {
        Cache::forget(self::key($email, $ip));
    }
}
