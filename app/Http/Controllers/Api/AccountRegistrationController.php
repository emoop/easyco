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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
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
