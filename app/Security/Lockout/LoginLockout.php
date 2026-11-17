<?php

namespace App\Security\Lockout;

use App\Security\SecurityMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P8d, generalised in ACC-1: the login lockout, keyed by SCOPE (`admin` / `customer`) + the normalised email + IP.
 *
 * COUNTED on failed credential submits only, KEYED by account (the normalised email) + IP: an attacker cannot lock the real owner out from his own IP. Every
 * `failures_per_step` (5) failures start a lock: the 1st 5 min, the 2nd 30 min, the 3rd and later 24 h. A submit that arrives during a lock is refused BEFORE the
 * credentials are looked at and is NOT counted. The counter decays `decay` (24 h) after the LAST failure; a success resets it. The same steps for both scopes
 * (config/security.php `lockout`); a scope never sees the other's counters.
 *
 * The answer while locked is decided by the login (the same generic message as a wrong password). The state lives in the cache only (no table); the email is
 * never stored or logged raw: its sha256 is the key and the log field. The account-wide ALARM mail belongs to the admin scope only (AdminLoginLockout::failed()).
 */
class LoginLockout
{
    public function __construct(
        protected readonly string $scope,
    ) {
    }

    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function hash(string $email): string
    {
        return hash('sha256', self::normalise($email));
    }

    public function scope(): string
    {
        return $this->scope;
    }

    /** the SecurityMode rule name of this scope: `admin_login_lockout` / `customer_login_lockout` */
    public function rule(): string
    {
        return $this->scope.'_login_lockout';
    }

    private function key(string $email, string $ip): string
    {
        return 'security.lockout.'.($this->scope === 'admin' ? '' : $this->scope.'.').self::hash($email).'.'.hash('sha256', $ip);
    }

    /** @return int the seconds the (account, IP) pair is still locked for; 0 = not locked */
    public function lockedFor(string $email, string $ip): int
    {
        $state = Cache::get($this->key($email, $ip));
        $until = is_array($state) ? (int) ($state['until'] ?? 0) : 0;

        return max(0, $until - now()->getTimestamp());
    }

    /** a failed credential submit: counts and may start a lock */
    public function recordFailure(string $email, string $ip): void
    {
        $key = $this->key($email, $ip);
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
            Log::warning(ucfirst($this->scope).' login locked.', ['email_hash' => self::hash($email), 'ip' => $ip, 'step' => $step, 'seconds' => $seconds, 'enforced' => SecurityMode::blocks()]);
        }

        // the counter decays 24 h after the LAST failure (the TTL restarts on every failure)
        Cache::put($key, $state, (int) config('security.lockout.decay', 86400));

        $this->failed($email);
    }

    public function recordSuccess(string $email, string $ip): void
    {
        Cache::forget($this->key($email, $ip));
    }

    /** a hook after every counted failure: the admin scope feeds its account-wide alarm here, the customer scope does nothing */
    protected function failed(string $email): void
    {
    }
}
