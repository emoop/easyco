<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Login/logout/"me" — session-based, via the 'customer' guard. See
 * account-domain-design.md §5/§6.
 */
class AccountSessionController extends Controller
{
    /**
     * `string` and a 255-character ceiling on both: the ceiling is the width of
     * accounts.email, so a value longer than the column is a 422 field error
     * instead of a query against a column it could never have come out of.
     * The 401 for a real mismatch is unchanged, and deliberately still identical
     * whether the address exists or not.
     */
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|string|max:255',
            'password' => 'required|string|max:255',
        ]);

        // Auth::attempt() itself calls Hash::check() internally via
        // EloquentUserProvider — PasswordHasher::verify() is
        // deliberately NOT used here, see account-domain-design.md §3.
        if (! Auth::guard('customer')->attempt($credentials)) {
            // Deliberately generic and IDENTICAL whether the email
            // doesn't exist or the password is wrong — never
            // distinguish the two, avoids user enumeration.
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $request->session()->regenerate();

        $account = Auth::guard('customer')->user();

        return response()->json([
            'id' => (string) $account->getAuthIdentifier(),
            'email' => $account->email,
        ]);
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
