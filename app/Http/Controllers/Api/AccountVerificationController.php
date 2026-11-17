<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\AccountMails;
use App\Services\GuestOrderAttacher;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\SignedLink;
use EasyCo\Extensibility\Hook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ACC-1: email verification. The link in the mail (`/account/verify?id=…&expires=…&signature=…`, signed, 24 h) is consumed here by the storefront page (ACC-3),
 * which forwards the three parameters. Valid → `email_verified_at = now()` and hook `account.verified` (only the first time); the answer is a plain 200 message.
 * A VERIFY LINK PROVES THE MAILBOX, IT IS NOT A LOGIN: registration replaces the password of an unverified account, so a link that logged in would put the first
 * clicker into an account whose password someone else chose. Whoever wants in must know the password (or reset it: «забравена парола» works for an unverified
 * account too, and its session version kills every earlier session). Already verified → the same 200, no change, no hook. Expired / tampered / unknown → one generic 422.
 */
class AccountVerificationController extends Controller
{
    use AccountApi;

    public function __construct(
        private readonly AccountMails $mails,
        private readonly GuestOrderAttacher $attacher,
    ) {
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => 'required|string|max:20',
            'expires' => 'required',
            'signature' => 'required|string|max:128',
        ]);

        $account = SignedLink::valid('verify', $data['id'], $data['expires'], $data['signature']) ? AccountModel::find($data['id']) : null;

        if ($account === null) {
            return response()->json(['message' => $this->says('verify.invalid')], 422);
        }

        if ($account->email_verified_at === null) {
            $account->forceFill(['email_verified_at' => now()])->save();

            Hook::fire('account.verified', (string) $account->getKey());

            // ACC-2: the mailbox is proven: the guest orders of this address become hers (never throws; a failure is retried by the next trigger / the command)
            $this->attacher->attach($account);
        }

        return response()->json(['message' => $this->says('verify.done')]);
    }

    /** always 202; sends only for an existing unverified account, at most 3 an hour per address */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|max:254']);

        $this->timeboxed(function () use ($data): void {
            $over = $this->overBudget('verify-resend', $data['email']);
            $account = $this->accountOf($data['email']);

            if (! $over && $account !== null && $account->email_verified_at === null) {
                $this->mails->verification((string) $account->getKey(), $account->email);
            }
        });

        return response()->json(['message' => $this->says('verify.resend_accepted')], 202);
    }
}
