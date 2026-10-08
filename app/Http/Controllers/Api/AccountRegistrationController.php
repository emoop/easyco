<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher;
use EasyCo\Account\Exceptions\EmailAlreadyRegisteredException;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Registration — mirrors AttributeValueController's style: no form
 * request class, no resource transformer. See
 * account-domain-design.md §5/§6.
 */
class AccountRegistrationController extends Controller
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $passwordHasher,
    ) {
    }

    /**
     * max:254 on the email — the WIDEST address that can actually be registered —
     * and max:255 on the password.
     *
     * WHY 254 AND NOT 255: accounts.email is a varchar(255) and `email` carries NO
     * length bound of its own (an 80-character local part is perfectly legal), while
     * `password` is an arbitrary-length string. Without a bound an overlong email is
     * a 500 from MySQL, and a multi-kilobyte password is hashed for nothing — with
     * one, both are a 422 field error and no account row is written.
     *
     * 255 was the ONE width that got past this layer and still failed: the Account
     * domain normalises and validates the address with filter_var(FILTER_VALIDATE_
     * EMAIL), which refuses anything longer than 254 characters, so a 255-character
     * address passed `email|max:255` and then threw an InvalidArgumentException
     * this controller does not catch — a 500, not a 422. Reported as a gap by
     * tests\Feature\AccountRegistrationControllerTest's own 255 case in the second
     * hardening pass and settled here: the API layer now refuses the width itself,
     * so the domain's ceiling is never reached with an address it will reject.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:254',
            'password' => 'required|min:8|max:255|confirmed',
        ]);

        $account = Account::register(
            $validated['email'],
            $this->passwordHasher->hash($validated['password']),
        );

        try {
            $this->accounts->save($account);
        } catch (EmailAlreadyRegisteredException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Auth::guard('customer')->login() needs the Eloquent model,
        // not the domain object — fetched fresh rather than reusing
        // any in-memory state, since save() only guarantees the
        // domain object's id was assigned.
        Auth::guard('customer')->login(AccountModel::findOrFail($account->id()));

        // account.registered — app-layer only, per CLAUDE.md rule 10;
        // the Account domain class/EasyCo\Account package itself never
        // calls Hook:: directly. No listener registered in this task —
        // purely the extension point (extensibility-design-and-hooks.md).
        Hook::fire('account.registered', $account);

        return response()->json([
            'id' => $account->id(),
            'email' => $account->email(),
        ], 201);
    }
}
