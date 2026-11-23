<?php

namespace App\Privacy;

use App\Mail\AccountMails;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Settings\StoreLocale;
use EasyCo\Account\Security\SignedLink;
use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Asks a person to confirm that a privacy request is theirs (acc-4-gdpr-design.md D2 revised, §6, ACC-4d, ACC-4e-1). Only the merchant starts it (admin «Поискай потвърждение», or
 * `privacy:ask-confirmation` for ops). It writes the `privacy_requests` row (`awaiting_confirmation`, `kind` = what the person asked: `export` or `erase`, `source = admin`, the email
 * in clear + its keyed hash, the staff member) and mails `privacy.confirm` to that address AFTER the commit, through SendAccountMailJob (never mail_log), in the SHOP's default locale
 * (StoreLocale, never the admin's own).
 *
 * ONE OPEN REQUEST per email and kind (ACC-4e-1): while a row of that `email_hash` and kind is `awaiting_confirmation` and younger than VALID_DAYS, `request()` writes nothing and throws
 * PrivacyRequestAlreadyOpen (carrying that row); the admin then offers `resend()`. After the row expired, a new request makes a new row. (Two clicks at the same instant are serialised by a
 * short cache lock: MySQL has no partial unique index to make it a constraint.)
 *
 * The link is a SignedLink (purpose `privacy-confirm`, `id` = the request id) that ends EXACTLY when its row ends: `created_at` + VALID_DAYS, for the first mail and for every resend, so a link
 * never outlives its request and a request never waits for a link that is dead. ONE constant, VALID_DAYS, also drives `privacy:expire-requests`.
 *
 * Nothing is exported or erased by a confirmation; the merchant acts from admin afterwards. Without PRIVACY_HASH_KEY (outside local and testing) it refuses BEFORE anything is written
 * (PrivacyNotConfigured), exactly like the other privacy services. A mail that cannot be queued is reported (PrivacyMailNotQueued), never silently treated as sent.
 */
final class PrivacyConfirmationRequester
{
    public const PURPOSE = 'privacy-confirm';

    /** The life of the confirmation link AND of the request that waits for it (ExpirePrivacyRequests). */
    public const VALID_DAYS = 7;

    public const KINDS = [PrivacyRequestModel::KIND_EXPORT, PrivacyRequestModel::KIND_ERASE];

    public function __construct(
        private readonly PrivacyHash $hash,
        private readonly AccountMails $mails,
        private readonly StoreLocale $locale,
    ) {
    }

    /**
     * @throws InvalidArgumentException a kind other than export / erase, or a blank or malformed email
     * @throws PrivacyNotConfigured
     * @throws PrivacyRequestAlreadyOpen a request of this email and kind is already waiting
     * @throws PrivacyMailNotQueued the row was written, the mail could not be queued
     */
    public function request(string $email, string $kind, ?StaffActor $staff): PrivacyRequestModel
    {
        in_array($kind, self::KINDS, true) || throw new InvalidArgumentException('The kind is export or erase.');

        $emailKey = IdentityKeys::email($email);

        if ($emailKey === null || filter_var($emailKey, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email is required.');
        }

        $emailHash = (string) $this->hash->email($emailKey);

        return Cache::lock('privacy-open-request:'.$kind.':'.substr($emailHash, 0, 24), 10)->block(5, function () use ($emailKey, $emailHash, $kind, $staff): PrivacyRequestModel {
            $open = $this->openRow($emailHash, $kind);

            if ($open !== null) {
                throw new PrivacyRequestAlreadyOpen($open);
            }

            $request = PrivacyRequestModel::query()->create([
                'kind' => $kind,
                'source' => PrivacySource::ADMIN->value,
                'status' => PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION,
                'email' => $emailKey,
                'email_hash' => $emailHash,
                'staff_id' => $staff?->id,
                'staff_name' => $staff === null ? null : mb_substr($staff->name, 0, 255),
            ]);

            $this->send($request);

            return $request;
        });
    }

    /**
     * A NEW signed link for the SAME row, mailed again. Only for an `awaiting_confirmation` row that has not expired; the link ends where the row ends.
     *
     * @throws InvalidArgumentException the row is not waiting, or its time is over
     * @throws PrivacyMailNotQueued
     */
    public function resend(PrivacyRequestModel $row, ?StaffActor $staff): PrivacyRequestModel
    {
        $row = $row->newQuery()->findOrFail($row->getKey());

        if ($row->status !== PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION || $this->endsAt($row) <= now()->getTimestamp() || blank($row->email)) {
            throw new InvalidArgumentException('Only a waiting request that has not expired can be sent again.');
        }

        $this->send($row);

        return $row;
    }

    /** The open row of this email and kind, if any: waiting for its confirmation and still inside its own time. */
    public function openRow(string $emailHash, string $kind): ?PrivacyRequestModel
    {
        return PrivacyRequestModel::query()
            ->where('email_hash', $emailHash)
            ->where('kind', $kind)
            ->where('status', PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION)
            ->where('created_at', '>', now()->subDays(self::VALID_DAYS))
            ->orderByDesc('id')
            ->first();
    }

    /** The unix second the row (and so every link of it) ends. */
    public function endsAt(PrivacyRequestModel $row): int
    {
        return $row->created_at->getTimestamp() + self::VALID_DAYS * 86400;
    }

    /** @throws PrivacyMailNotQueued */
    private function send(PrivacyRequestModel $row): void
    {
        $link = $this->mails->privacyConfirmUrl(SignedLink::paramsUntil(self::PURPOSE, (string) $row->getKey(), $this->endsAt($row)));
        $email = (string) $row->email;
        $kind = (string) $row->kind;
        $locale = $this->locale->current();
        $sent = null;

        // AFTER the commit: a transaction around this call must not mail for a request that may still roll back (outside a transaction this runs at once)
        DB::afterCommit(function () use (&$sent, $email, $kind, $link, $locale): void {
            $sent = $this->mails->privacyConfirmation($email, $kind, $link, self::VALID_DAYS, $locale);
        });

        if ($sent === false) {
            throw new PrivacyMailNotQueued($row);
        }
    }
}
