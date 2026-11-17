<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Security\Lockout\CustomerLoginLockout;
use App\Security\SecurityMode;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Login/logout/"me" — session-based, via the 'customer' guard. See account-domain-design.md §5/§6 and account-security.md (ACC-1).
 *
 * LOGIN (ACC-1): a wrong email and a wrong password get the SAME 401 body inside a Timebox (the answer takes as long either way). A correct password on an
 * unverified account is a 403 with the code `email_unverified` (only someone who knows the password learns that the account exists). The customer lockout
 * (LoginLockout, scope `customer`, same steps and same SECURITY_MODE rule as the admin's) counts failed credential submits per (email, IP) and refuses a locked
 * pair with the answer of a wrong password BEFORE the password is looked at. `remember` keeps the login 30 days (the guard's own cookie).
 */
class AccountSessionController extends Controller
{
    use AccountApi;

    public const REMEMBER_MINUTES = 43200;

    public function __construct(
        private readonly CustomerLoginLockout $lockout,
    ) {
    }

    /**
     * `string` and a 255-character ceiling on both: the ceiling is the width of accounts.email, so a value longer than the column is a 422 field error
     * instead of a query against a column it could never have come out of.
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'remember' => 'sometimes|boolean',
        ]);

        $email = $this->normalised($credentials['email']);
        $ip = (string) $request->ip();
        $remember = (bool) ($credentials['remember'] ?? false);

        $outcome = $this->timeboxed(fn (): string|AccountModel => $this->check($email, $credentials['password'], $ip));

        if ($outcome === 'invalid') {
            return response()->json(['message' => $this->says('login.invalid')], 401);
        }

        if ($outcome === 'unverified') {
            return response()->json(['message' => $this->says('login.unverified'), 'code' => 'email_unverified'], 403);
        }

        $guard = Auth::guard('customer');
        $guard->setRememberDuration(self::REMEMBER_MINUTES);
        $guard->login($outcome, $remember);

        $request->session()->regenerate();
        AccountSessionVersion::adopt($outcome, $request->session());
        $this->lockout->recordSuccess($email, $ip);

        return response()->json([
            'id' => (string) $outcome->getAuthIdentifier(),
            'email' => $outcome->email,
        ]);
    }

    /** @return string|AccountModel `invalid` | `unverified` | the account to log in */
    private function check(string $email, string $password, string $ip): string|AccountModel
    {
        if ($this->lockout->lockedFor($email, $ip) > 0 && SecurityMode::decide($this->lockout->rule(), ['email_hash' => $this->lockout::hash($email), 'ip' => $ip])) {
            return 'invalid';
        }

        // Auth's own provider: it hashes through EloquentUserProvider::validateCredentials (PasswordHasher::verify() is deliberately NOT used, see account-domain-design.md §3)
        $provider = Auth::guard('customer')->getProvider();
        $account = $provider->retrieveByCredentials(['email' => $email]);

        if (! $account instanceof AccountModel || ! $provider->validateCredentials($account, ['password' => $password])) {
            $this->lockout->recordFailure($email, $ip);

            return 'invalid';
        }

        return $account->email_verified_at === null ? 'unverified' : $account;
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::guard('customer')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function show(Request $request): JsonResponse
    {
        $account = Auth::guard('customer')->user();

        return response()->json([
            'id' => (string) $account->getAuthIdentifier(),
            'email' => $account->email,
        ]);
    }
}
