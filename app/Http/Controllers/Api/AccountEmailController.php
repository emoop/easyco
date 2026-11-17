<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMails;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use EasyCo\Account\Security\SignedLink;
use EasyCo\Extensibility\Hook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ACC-1: changing the email of a logged-in customer.
 *
 *  - request: the current password is checked first (422 on `current_password`); then ALWAYS 202 (an address that is taken, or the current one, gets the same answer and
 *    no mail). A pending change (one per account, own table, only the token's hash) gets a signed 24 h link to the NEW address and a notice to the OLD one. The account's
 *    email does NOT change here.
 *  - confirm (the link; works from any browser, the signature is the proof): the email changes only now. If the new address was taken meanwhile → the generic 422 (no
 *    enumeration). Success → email changed and verified, session version bumped, hook `account.email_changed`.
 */
class AccountEmailController extends Controller
{
    use AccountApi;

    public function __construct(
        private readonly AccountMails $mails,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => 'required|string|max:255',
            'email' => 'required|email|max:254',
        ]);

        /** @var AccountModel $account */
        $account = Auth::guard('customer')->user();

        if (! Hash::check($data['current_password'], (string) $account->password)) {
            return response()->json(['message' => $this->says('password.current_wrong'), 'errors' => ['current_password' => [$this->says('password.current_wrong')]]], 422);
        }

        $new = $this->normalised($data['email']);

        $this->timeboxed(function () use ($account, $new): void {
            if ($this->overBudget('email-change.'.$account->getKey(), $account->email) || $new === $account->email || $this->accountOf($new, true) !== null) {
                return;
            }

            $token = Str::random(48);

            DB::table('account_email_changes')->upsert(
                [['account_id' => $account->getKey(), 'new_email' => $new, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addSeconds(AccountMails::EMAIL_CONFIRM_TTL), 'created_at' => now()]],
                ['account_id'],
                ['new_email', 'token_hash', 'expires_at', 'created_at'],
            );

            $this->mails->emailConfirmation((string) $account->getKey(), $new, $token);
            $this->mails->emailNotice($account->email);
        });

        return response()->json(['message' => $this->says('email.accepted')], 202);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|string|max:20',
            'token' => 'required|string|max:200',
            'expires' => 'required',
            'signature' => 'required|string|max:128',
        ]);

        $generic = response()->json(['message' => $this->says('email.confirm_invalid')], 422);

        if (! SignedLink::valid('email', $data['id'], $data['expires'], $data['signature'], $data['token'])) {
            return $generic;
        }

        $account = AccountModel::find($data['id']);
        $change = $account === null ? null : DB::table('account_email_changes')->where('account_id', $account->getKey())->first();

        if ($change === null || ! hash_equals((string) $change->token_hash, hash('sha256', $data['token'])) || Carbon::parse($change->expires_at)->isPast()) {
            return $generic;
        }

        if ($this->accountOf((string) $change->new_email, true) !== null) {
            DB::table('account_email_changes')->where('account_id', $account->getKey())->delete();

            return $generic;
        }

        $account->forceFill(['email' => $change->new_email, 'email_verified_at' => now()])->save();
        DB::table('account_email_changes')->where('account_id', $account->getKey())->delete();

        // the browser that confirms keeps its login only if it already was this account's
        $current = Auth::guard('customer')->user();
        AccountSessionVersion::bump($account, $current instanceof AccountModel && $current->is($account) && $request->hasSession() ? $request->session() : null);

        Hook::fire('account.email_changed', (string) $account->getKey());

        return response()->json(['message' => $this->says('email.confirmed')]);
    }
}
