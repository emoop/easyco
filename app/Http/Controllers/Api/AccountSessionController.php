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
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string',
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
