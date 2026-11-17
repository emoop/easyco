<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMails;
use App\Services\GuestOrderAttacher;
use EasyCo\Account\Contracts\PasswordHasher;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use EasyCo\Extensibility\Hook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ACC-1: forgotten password, reset, and change.
 *
 *  - forgot: always 202 inside a Timebox; for any existing (not deleted) account, verified or not (the reset proves the mailbox and verifies it), a reset mail (`/account/reset?token=…&email=…`). The token lives in its own table
 *    (`account_password_reset_tokens`, only a hash, 60 min, one row per email: a new request replaces the old); at most 3 requests an hour per address.
 *  - reset: valid → the new password, the token deleted, the session version bumped (every session and remember cookie dies), «password changed» mail, hook;
 *    it also verifies an unverified email (the mailbox was proven). Anything wrong with the token / email / age → one generic 422.
 *  - change (logged in): the current password is checked first (422 on `current_password`); success → version bumped (this session kept), mail, hook.
 */
class AccountPasswordController extends Controller
{
    use AccountApi;

    public const RESET_MINUTES = 60;

    public function __construct(
        private readonly AccountMails $mails,
        private readonly PasswordHasher $passwordHasher,
        private readonly GuestOrderAttacher $attacher,
    ) {
    }

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|max:254']);

        $this->timeboxed(function () use ($data): void {
            $over = $this->overBudget('password-forgot', $data['email']);
            $account = $this->accountOf($data['email']);

            if ($over || $account === null) {
                return;
            }

            $token = Str::random(48);

            DB::table('account_password_reset_tokens')->upsert(
                [['email' => $account->email, 'token_hash' => hash('sha256', $token), 'created_at' => now()]],
                ['email'],
                ['token_hash', 'created_at'],
            );

            $this->mails->passwordReset($account->email, $token);
        });

        return response()->json(['message' => $this->says('password.forgot_accepted')], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|max:254',
            'token' => 'required|string|max:200',
            'password' => 'required|string|confirmed',
        ]);

        $this->assertAcceptablePassword($data['password'], $data['email']);

        $account = $this->timeboxed(fn (): ?AccountModel => $this->consumeToken($this->normalised($data['email']), $data['token']));

        if ($account === null) {
            return response()->json(['message' => $this->says('password.reset_invalid')], 422);
        }

        $firstVerification = $account->email_verified_at === null;

        $account->forceFill([
            'password' => $this->passwordHasher->hash($data['password']),
            'email_verified_at' => $account->email_verified_at ?? now(),
        ])->save();

        AccountSessionVersion::bump($account);
        $this->mails->passwordChanged($account->email);
        Hook::fire('account.password_changed', (string) $account->getKey());

        if ($firstVerification) {
            // ACC-2: the reset proved the mailbox (the same trigger as the verify link)
            Hook::fire('account.verified', (string) $account->getKey());
            $this->attacher->attach($account);
        }

        return response()->json(['message' => $this->says('password.reset_done')]);
    }

    /** the account of a valid, unexpired token (the token is deleted: it is used once); null otherwise, whatever the reason */
    private function consumeToken(string $email, string $token): ?AccountModel
    {
        $row = DB::table('account_password_reset_tokens')->where('email', $email)->first();
        $account = $this->accountOf($email);

        $valid = $row !== null
            && $account !== null
            && hash_equals((string) $row->token_hash, hash('sha256', $token))
            && now()->subMinutes(self::RESET_MINUTES)->lessThanOrEqualTo(\Illuminate\Support\Carbon::parse($row->created_at));

        if ($row !== null && ($valid || now()->subMinutes(self::RESET_MINUTES)->greaterThan(\Illuminate\Support\Carbon::parse($row->created_at)))) {
            DB::table('account_password_reset_tokens')->where('email', $email)->delete();
        }

        return $valid ? $account : null;
    }

    public function change(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:255',
            'password' => 'required|string|confirmed',
        ]);

        /** @var AccountModel $account */
        $account = Auth::guard('customer')->user();

        if (! Hash::check($data['current_password'], (string) $account->password)) {
            return response()->json(['message' => $this->says('password.current_wrong'), 'errors' => ['current_password' => [$this->says('password.current_wrong')]]], 422);
        }

        $this->assertAcceptablePassword($data['password'], $account->email);

        $account->forceFill(['password' => $this->passwordHasher->hash($data['password'])])->save();
        AccountSessionVersion::bump($account, $request->hasSession() ? $request->session() : null);

        $this->mails->passwordChanged($account->email);
        Hook::fire('account.password_changed', (string) $account->getKey());

        return response()->json(['message' => $this->says('password.changed')]);
    }
}
