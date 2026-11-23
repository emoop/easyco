<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use EasyCo\Order\IdentityKeys;
use InvalidArgumentException;

/**
 * Starts a personal-data export (acc-4-gdpr-design.md D2 revised, D3, §3, ACC-4c): ONLY the merchant starts one (admin «Изтегли данните» in ACC-4e, or `privacy:export` for ops). It writes
 * the `privacy_requests` row (`kind = export`, `source = admin`, `status = processing`, the email in clear + its keyed hash, the staff member) and queues BuildPrivacyExportJob AFTER the commit.
 * No mail and no customer link: the staff member downloads the ZIP and sends it.
 *
 * Without PRIVACY_HASH_KEY (outside local and testing) it refuses BEFORE anything is written (PrivacyNotConfigured), exactly like the erase: never a silent fallback.
 */
final class PrivacyExportRequester
{
    public function __construct(
        private readonly PrivacyHash $hash,
    ) {
    }

    /**
     * @param bool $queue false = the caller runs the job itself (`privacy:export --sync`); the row is written the same way
     *
     * @throws InvalidArgumentException a blank email
     * @throws PrivacyNotConfigured
     */
    public function request(string $email, ?StaffActor $staff, bool $queue = true): PrivacyRequestModel
    {
        $emailKey = IdentityKeys::email($email) ?? throw new InvalidArgumentException('An email is required.');
        $emailHash = (string) $this->hash->email($emailKey);

        $request = PrivacyRequestModel::query()->create([
            'kind' => PrivacyRequestModel::KIND_EXPORT,
            'source' => PrivacySource::ADMIN->value,
            'status' => PrivacyRequestModel::STATUS_PROCESSING,
            'email' => $emailKey,
            'email_hash' => $emailHash,
            'staff_id' => $staff?->id,
            'staff_name' => $staff === null ? null : mb_substr($staff->name, 0, 255),
            'confirmed_at' => now(),
        ]);

        if ($queue) {
            // dispatched AFTER the commit (afterCommit() in the job's constructor); the row is already written
            BuildPrivacyExportJob::dispatch((int) $request->getKey());
        }

        return $request;
    }
}
