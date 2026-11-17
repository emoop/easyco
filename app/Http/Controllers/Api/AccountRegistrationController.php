<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMails;
use App\Security\Honeypot;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher;
use EasyCo\Account\Exceptions\EmailAlreadyRegisteredException;
use EasyCo\Extensibility\Hook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Registration — see account-domain-design.md §5/§6 and account-security.md (ACC-1).
 *
 * REGISTRATION NEVER REVEALS WHETHER AN EMAIL EXISTS: after the same validation every request gets the same 202 and the same body, inside a Timebox (the same
 * minimum duration for every path):
 *  - a NEW address: an account is created UNVERIFIED and a verification mail is sent. Registration does not log in;
 *  - the address of a VERIFIED account: nothing changes, an «already registered» mail (links to log in and to reset the password) goes to that address;
 *  - the address of an UNVERIFIED account: its password is replaced by the new one and a fresh verification mail is sent (only the mailbox owner can activate it);
 *  - a soft-deleted account's address: nothing (its email stays reserved).
 * At most 3 mails an hour go to one address from this endpoint (a silent skip: the answer is the same), so it cannot be used to flood a mailbox.
 */
class AccountRegistrationController extends Controller
{
    use AccountApi;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $passwordHasher,
        private readonly AccountMails $mails,
    ) {
    }

    /**
     * max:254 on the email — the WIDEST address that can actually be registered (accounts.email is a varchar(255) and the Account domain's filter_var refuses
     * anything longer than 254); the password is bounded by PasswordPolicy (8-72 BYTES: bcrypt reads no more), so a multi-kilobyte password is a 422 and is never hashed.
     */
    public function store(Request $request): JsonResponse
    {
        // P8d: the honeypot (the storefront has no registration form yet: a form that is added later renders `storefront.partials.honeypot`)
        Honeypot::check($request, 'registration', 'email');

        $validated = $request->validate([
            'email' => 'required|email|max:254',
            'password' => 'required|string|confirmed',
        ]);

        $this->assertAcceptablePassword($validated['password'], $validated['email']);

        $this->timeboxed(fn () => $this->register($this->normalised($validated['email']), $validated['password']));

        return response()->json(['message' => $this->says('register.accepted')], 202);
    }

    private function register(string $email, string $password): void
    {
        // the hash is computed on EVERY path, so the work that differs between them is small next to it
        $hash = $this->passwordHasher->hash($password);
        $existing = $this->accountOf($email, true);

        if ($existing === null) {
            try {
                $account = Account::register($email, $hash);
                $this->accounts->save($account);

                $id = (string) $account->id();
                $this->sendOnce($email, fn () => $this->mails->verification($id, $email));
                // account.registered — app-layer only, per CLAUDE.md rule 10; arguments: the account id only (ACC-1: never the hash)
                Hook::fire('account.registered', $id);

                return;
            } catch (EmailAlreadyRegisteredException) {
                $existing = $this->accountOf($email, true); // lost a race with the same address: handled as an existing one
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['email' => [(string) __('validation.email', ['attribute' => 'email'], app(\App\Settings\StoreLocale::class)->current())]]);
            }
        }

        if ($existing === null || $existing->trashed()) {
            return;
        }

        if ($existing->email_verified_at !== null) {
            $this->sendOnce($email, fn () => $this->mails->alreadyRegistered($email));

            return;
        }

        $existing->forceFill(['password' => $hash])->save();
        $this->sendOnce($email, fn () => $this->mails->verification((string) $existing->getKey(), $email));
    }

    private function sendOnce(string $email, \Closure $send): void
    {
        if (! $this->overBudget('register-mail', $email)) {
            $send();
        }
    }
}
