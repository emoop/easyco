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
 * A CONFIRMED export request of this email (the person pressed the link of «Поискай потвърждение», ACC-4d) is built ON, not duplicated (ACC-4e-1): one conditional update
 * `confirmed` → `processing` (`WHERE status = 'confirmed'`), so two clicks run the build once; the second finds nothing to move and dispatches nothing. Otherwise a new row, as before.
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

        $confirmed = PrivacyRequestModel::query()
            ->where('kind', PrivacyRequestModel::KIND_EXPORT)
            ->where('email_hash', $emailHash)
            ->where('status', PrivacyRequestModel::STATUS_CONFIRMED)
            ->orderByDesc('id')
            ->first();

        if ($confirmed !== null) {
            // the ONE transition; `confirmed_at` (the person's own confirmation) and `staff_*` (who asked for it) stay as they are
            $moved = PrivacyRequestModel::query()
                ->whereKey($confirmed->getKey())
                ->where('status', PrivacyRequestModel::STATUS_CONFIRMED)
                ->update(['status' => PrivacyRequestModel::STATUS_PROCESSING, 'updated_at' => now()]);

            $queue && $moved === 1 && BuildPrivacyExportJob::dispatch((int) $confirmed->getKey());

            return $confirmed->fresh();
        }

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
