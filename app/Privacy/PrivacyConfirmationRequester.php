<?php

namespace App\Privacy;

use App\Mail\AccountMails;
use App\Privacy\Persistence\PrivacyRequestModel;
use EasyCo\Account\Security\SignedLink;
use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Asks a person to confirm that a privacy request is theirs (acc-4-gdpr-design.md D2 revised, §6, ACC-4d). Only the merchant starts it (admin «Поискай потвърждение» in ACC-4e, or
 * `privacy:ask-confirmation` for ops). It writes the `privacy_requests` row (`awaiting_confirmation`, `kind` = what the person asked: `export` or `erase`, `source = admin`, the email
 * in clear + its keyed hash, the staff member) and mails `privacy.confirm` to that address AFTER the commit, through SendAccountMailJob (never mail_log).
 *
 * The link is a SignedLink (purpose `privacy-confirm`, `id` = the request id) valid VALID_DAYS days: ONE constant for the link AND for `privacy:expire-requests`, so a link never
 * outlives its request and a request never waits for a link that is dead.
 *
 * Nothing is exported or erased by a confirmation; the merchant acts from admin afterwards. Without PRIVACY_HASH_KEY (outside local and testing) it refuses BEFORE anything is written
 * (PrivacyNotConfigured), exactly like the other privacy services.
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
    ) {
    }

    /**
     * @throws InvalidArgumentException a kind other than export / erase, or a blank or malformed email
     * @throws PrivacyNotConfigured
     */
    public function request(string $email, string $kind, ?StaffActor $staff): PrivacyRequestModel
    {
        in_array($kind, self::KINDS, true) || throw new InvalidArgumentException('The kind is export or erase.');

        $emailKey = IdentityKeys::email($email);

        if ($emailKey === null || filter_var($emailKey, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A valid email is required.');
        }

        $emailHash = (string) $this->hash->email($emailKey);

        $request = PrivacyRequestModel::query()->create([
            'kind' => $kind,
            'source' => PrivacySource::ADMIN->value,
            'status' => PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION,
            'email' => $emailKey,
            'email_hash' => $emailHash,
            'staff_id' => $staff?->id,
            'staff_name' => $staff === null ? null : mb_substr($staff->name, 0, 255),
        ]);

        $link = $this->mails->privacyConfirmUrl(SignedLink::params(self::PURPOSE, (string) $request->getKey(), self::VALID_DAYS * 86400));

        // AFTER the commit: a transaction around this call must not mail for a request that may still roll back
        DB::afterCommit(fn () => $this->mails->privacyConfirmation($emailKey, $kind, $link, self::VALID_DAYS));

        return $request;
    }
}
