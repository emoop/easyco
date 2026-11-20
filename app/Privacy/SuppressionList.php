<?php

namespace App\Privacy;

use App\Privacy\Persistence\PrivacySuppressionModel;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The suppression list (acc-4-gdpr-design.md §4, D6): the keyed hashes of the emails and phones of the people who were forgotten. A hash on the list blocks
 * adding that person to ANY list or audience; a later explicit double opt-in is new consent and lifts it (ACC-5 calls lift(); nothing else does).
 *
 * Only hashes are stored here (never the value). One row per (kind, hash) is a UNIQUE key of the table: suppress() is insert-or-ignore, so two concurrent
 * erasures of the same person leave one row. A lifted row is never deleted: `lifted_at` and `lifted_reason` say it was lifted, and a new erase of the same
 * person puts it back.
 */
final class SuppressionList
{
    public function __construct(
        private readonly PrivacyHash $hash,
    ) {
    }

    /** Is this email, or this phone, on the list (and not lifted)? Blank or unreadable values are never suppressed. @throws PrivacyNotConfigured */
    public function isSuppressed(?string $email, ?string $phone = null): bool
    {
        $pairs = array_filter([
            PrivacySuppressionModel::KIND_EMAIL => $this->hash->email($email),
            PrivacySuppressionModel::KIND_PHONE => $this->hash->phone($phone),
        ], static fn (?string $hash): bool => $hash !== null);

        if ($pairs === []) {
            return false;
        }

        return PrivacySuppressionModel::query()
            ->whereNull('lifted_at')
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $kind => $hash) {
                    $query->orWhere(fn ($inner) => $inner->where('kind', $kind)->where('hash', $hash));
                }
            })
            ->exists();
    }

    /** Suppresses an email or a phone (the raw value). False when the value has no hash (blank, or a phone that cannot be read). @throws PrivacyNotConfigured */
    public function suppress(string $kind, string $value, int $requestId): bool
    {
        $hash = match ($kind) {
            PrivacySuppressionModel::KIND_EMAIL => $this->hash->email($value),
            PrivacySuppressionModel::KIND_PHONE => $this->hash->phone($value),
            default => throw new InvalidArgumentException('A suppression is of kind email or phone.'),
        };

        if ($hash === null) {
            return false;
        }

        $this->suppressHash($kind, $hash, $requestId);

        return true;
    }

    /** The same, for a hash already computed (the eraser holds them): insert-or-ignore, and a lifted row is put back. */
    public function suppressHash(string $kind, string $hash, int $requestId): void
    {
        DB::transaction(function () use ($kind, $hash, $requestId): void {
            $now = now();

            PrivacySuppressionModel::query()->insertOrIgnore([
                'kind' => $kind, 'hash' => $hash, 'request_id' => $requestId, 'created_at' => $now, 'updated_at' => $now,
            ]);

            PrivacySuppressionModel::query()->where('kind', $kind)->where('hash', $hash)->whereNotNull('lifted_at')
                ->update(['lifted_at' => null, 'lifted_reason' => null, 'request_id' => $requestId, 'updated_at' => $now]);
        });
    }

    /**
     * Lifts the suppression of an email (new, explicit consent). The reason is required (plain text, at most 500). True when an active row was lifted.
     *
     * @throws InvalidArgumentException a blank, too long or control-character reason
     * @throws PrivacyNotConfigured
     */
    public function lift(string $email, string $reason): bool
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500 || preg_match('/[\p{Cc}\p{Cf}]/u', $reason) === 1) {
            throw new InvalidArgumentException('Lifting a suppression needs a plain-text reason of at most 500 characters.');
        }

        $hash = $this->hash->email($email);

        if ($hash === null) {
            return false;
        }

        return PrivacySuppressionModel::query()->where('kind', PrivacySuppressionModel::KIND_EMAIL)->where('hash', $hash)->whereNull('lifted_at')
            ->update(['lifted_at' => now(), 'lifted_reason' => $reason, 'updated_at' => now()]) > 0;
    }
}
