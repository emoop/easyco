<?php

namespace App\Privacy;

use App\Mail\AccountMails;
use App\Models\OrderBlockModel;
use App\Privacy\Persistence\CustomerNoteModel;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Rules\PlainText;
use App\Services\OrderBlockService;
use App\Enums\OrderBlockType;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\IdentityKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * «Forget me» (acc-4-gdpr-design.md §4, owner decisions D1, D3, D6, D7). One request, one transaction:
 *
 *  1. the phones are collected (the account's addresses, the orders of this email);
 *  2. the account is force-deleted (the database cascades addresses, carts and their lines, email changes; orders, promotion redemptions and POS clients
 *     are unlinked: account_id NULL), its reset token and the email changes that point at this email are deleted;
 *  3. the staff note is deleted;
 *  4. the ACCOUNT mails of this email in mail_log lose the address (order mails stay with the order, D1);
 *  5. the email and every readable phone go on the suppression list;
 *  6. ACTIVE cash-on-delivery blocks are KEPT until they expire (D7) and only recorded;
 *  7. the log row is written (`done`, the actions, `clear_until` = completion + 30 days; email and phone in clear until then).
 *
 * AFTER the commit: the `customer.erased` action (the listeners that keep lists or audiences remove the person and report through PrivacyLog) and the
 * final mail «Данните ви са изтрити» through SendAccountMailJob (never mail_log). A listener that throws is contained by the hook registry and never undoes
 * the erasure.
 *
 * NOT TOUCHED, ON PURPOSE (D1): orders, placement snapshots, invoice details, order events, attributions. They are anonymised at the end of the retention
 * period (ACC-4b), not here.
 *
 * Honoured always: an email with no data at all still gets its `done` row and its suppression. Idempotent: a second erase of the same email finds nothing
 * to delete, writes another log row and keeps the one suppression per hash (UNIQUE key, insert-or-ignore).
 *
 * ACC-4e-2 (acc-4-gdpr-design.md section 10): ONE REQUEST, ONE ROW. A `confirmed` ERASE request of this email (the person pressed the link, ACC-4d) is taken as the forget-log row: one conditional
 * update `WHERE id = ? AND status = 'confirmed'` that sets `processing`, the reason and the staff member in the SAME statement (so `prq_reason_check` holds); `confirmed_at` stays. Zero rows updated
 * (another click took it) means no confirmed row: a new row, as before. An `export`, `awaiting_confirmation` or `expired` row is never taken. If anything after it fails, the transaction puts the row back to `confirmed`.
 * NO DOUBLE RUN: the whole erase runs under a short `Cache::lock()` per email hash (it works on the file, database and Redis stores); inside it, a person already erased by a `done` row of the last
 * DOUBLE_SUBMIT_SECONDS with nothing left to erase (no account, no note, no order since) gets that row back and nothing is written. A later deliberate erase writes a new row.
 * Without PRIVACY_HASH_KEY (outside local and testing) it refuses BEFORE anything is written (PrivacyNotConfigured).
 */
final class PrivacyEraser
{
    /** What a masked account-mail row holds instead of the address (`.invalid` can never be delivered). */
    public const MASKED_ADDRESS = 'erased@invalid';

    /** A second erase of the same, already erased, person inside this many seconds writes nothing (a double submit). */
    public const DOUBLE_SUBMIT_SECONDS = 60;

    public function __construct(
        private readonly PrivacyHash $hash,
        private readonly SuppressionList $suppressions,
        private readonly PrivacyLog $log,
        private readonly AccountMails $mails,
    ) {
    }

    /**
     * @throws LogicException called inside a transaction (the hook and the mail must follow OUR commit)
     * @throws InvalidArgumentException a blank email, or an admin request without a staff member
     * @throws ValidationException an admin request without a reason, or a reason that is too long or not plain text
     * @throws PrivacyNotConfigured
     */
    public function erase(string $email, PrivacySource $source, ?StaffActor $staff = null, ?string $reason = null): PrivacyRequestModel
    {
        // BEFORE anything is written: the hook and the final mail come after OUR commit; inside someone else's transaction they would fire for an erasure
        // that may still roll back. (Under PHPUnit one ambient level is allowed: RefreshDatabase wraps every test in a transaction. The constant is PHPUnit's own, not the environment name, which a test may change.)
        if (DB::transactionLevel() > (defined('PHPUNIT_COMPOSER_INSTALL') ? 1 : 0)) {
            throw new LogicException('erase() must not run inside a transaction: the hook and the mail come after the commit.');
        }

        $emailKey = IdentityKeys::email($email) ?? throw new InvalidArgumentException('An email is required.');
        $reason = $this->checkedReason($source, $staff, $reason);
        $emailHash = (string) $this->hash->email($emailKey);

        return Cache::lock('privacy-erase:'.substr($emailHash, 0, 24), 15)->block(8, function () use ($emailKey, $emailHash, $source, $staff, $reason): PrivacyRequestModel {
            $again = $this->justErased($emailKey, $emailHash);

            if ($again !== null) {
                return $again;
            }

            [$request, $phones] = DB::transaction(fn (): array => $this->eraseInTransaction($emailKey, $emailHash, $source, $staff, $reason));

            // AFTER the commit: the lists and audiences, then the final mail. Neither can undo what is done.
            Hook::fire('customer.erased', new ErasedCustomer((int) $request->getKey(), $emailKey, $phones));
            $this->log->addAction((int) $request->getKey(), PrivacyActions::HOOK_CUSTOMER_ERASED);
            $this->mails->privacyErased($emailKey);

            return $request->newQuery()->findOrFail($request->getKey());
        });
    }

    /** The `done` row of the last DOUBLE_SUBMIT_SECONDS when nothing is left to erase and nothing came back since; else null. */
    private function justErased(string $emailKey, string $emailHash): ?PrivacyRequestModel
    {
        $row = PrivacyRequestModel::query()
            ->where('kind', PrivacyRequestModel::KIND_ERASE)
            ->where('email_hash', $emailHash)
            ->where('status', PrivacyRequestModel::STATUS_DONE)
            ->where('completed_at', '>', now()->subSeconds(self::DOUBLE_SUBMIT_SECONDS))
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $somethingLeft = AccountModel::withTrashed()->where('email', $emailKey)->exists()
            || CustomerNoteModel::query()->where('email_key', $emailKey)->exists()
            || DB::table('orders')->where('email_key', $emailKey)->where('placed_at', '>', $row->completed_at)->exists();

        return $somethingLeft ? null : $row;
    }

    /** The confirmed ERASE row of this email, moved to `processing` with the reason and the staff member in ONE conditional statement; null when there is none or another click was first. */
    private function takeConfirmed(string $emailHash, ?StaffActor $staff, ?string $reason, ?int $accountId, ?string $firstPhone, ?string $firstPhoneHash): ?PrivacyRequestModel
    {
        $confirmed = PrivacyRequestModel::query()
            ->where('kind', PrivacyRequestModel::KIND_ERASE)
            ->where('email_hash', $emailHash)
            ->where('status', PrivacyRequestModel::STATUS_CONFIRMED)
            ->orderByDesc('id')
            ->first(['id']);

        if ($confirmed === null) {
            return null;
        }

        $moved = PrivacyRequestModel::query()
            ->whereKey($confirmed->getKey())
            ->where('status', PrivacyRequestModel::STATUS_CONFIRMED)
            ->update([
                'status' => PrivacyRequestModel::STATUS_PROCESSING,
                'reason' => $reason,
                'staff_id' => $staff?->id,
                'staff_name' => $staff === null ? null : mb_substr($staff->name, 0, 255),
                'account_id' => $accountId,
                'phone' => $firstPhone,
                'phone_hash' => $firstPhoneHash,
                'updated_at' => now(),
            ]);

        return $moved === 1 ? PrivacyRequestModel::query()->findOrFail($confirmed->getKey()) : null;
    }

    /** @return array{0: PrivacyRequestModel, 1: list<string>} the log row and the collected phones */
    private function eraseInTransaction(string $emailKey, string $emailHash, PrivacySource $source, ?StaffActor $staff, ?string $reason): array
    {
        $now = now();
        $actions = [];

        $account = AccountModel::withTrashed()->where('email', $emailKey)->first();
        $phones = $this->collectPhones($emailKey, $account);
        $phoneHashes = [];

        foreach ($phones as $phone) {
            $hash = $this->hash->phone($phone);

            if ($hash !== null) {
                $phoneHashes[$phone] = $hash;
            }
        }

        $firstPhone = $phones[0] ?? null;

        $firstPhoneHash = $firstPhone === null ? null : ($phoneHashes[$firstPhone] ?? null);

        // section 10: a confirmed erase request of this email IS the log row; otherwise a new one
        $request = $this->takeConfirmed($emailHash, $staff, $reason, $account === null ? null : (int) $account->getKey(), $firstPhone, $firstPhoneHash)
            ?? PrivacyRequestModel::query()->create([
                'kind' => PrivacyRequestModel::KIND_ERASE,
                'source' => $source->value,
                'status' => PrivacyRequestModel::STATUS_PROCESSING,
                'email' => $emailKey,
                'email_hash' => $emailHash,
                'phone' => $firstPhone,
                'phone_hash' => $firstPhoneHash,
                'account_id' => $account?->getKey(),
                'reason' => $reason,
                'staff_id' => $staff?->id,
                'staff_name' => $staff === null ? null : mb_substr($staff->name, 0, 255),
                'confirmed_at' => $now,
            ]);

        // 2. the account and what hangs on it
        $tokens = DB::table('account_password_reset_tokens')->where('email', $emailKey)->delete();
        $changes = DB::table('account_email_changes')->where('new_email', $emailKey)->delete();

        if ($account !== null) {
            $addresses = DB::table('addresses')->where('account_id', $account->getKey())->count();
            $carts = DB::table('carts')->where('account_id', $account->getKey())->count();
            $account->forceDelete();

            $actions[PrivacyActions::ACCOUNT_DELETED] = true;
            $addresses > 0 && $actions[PrivacyActions::ADDRESSES_DELETED] = $addresses;
            $carts > 0 && $actions[PrivacyActions::CARTS_DELETED] = $carts;
        }

        $tokens > 0 && $actions[PrivacyActions::RESET_TOKENS_DELETED] = $tokens;
        $changes > 0 && $actions[PrivacyActions::EMAIL_CHANGES_DELETED] = $changes;

        // 3. the staff note
        CustomerNoteModel::query()->where('email_key', $emailKey)->delete() > 0 && $actions[PrivacyActions::STAFF_NOTE_DELETED] = true;

        // 4. the account mails of this address in the mail log
        $masked = DB::table('mail_log')->whereRaw('LOWER(to_email) = ?', [$emailKey])->where('template_key', 'like', 'account.%')->update(['to_email' => self::MASKED_ADDRESS]);
        $masked > 0 && $actions[PrivacyActions::MAIL_LOG_MASKED] = $masked;

        // 5. the suppression list
        $this->suppressions->suppressHash('email', $emailHash, (int) $request->getKey());
        $actions[PrivacyActions::SUPPRESSED_EMAIL] = true;

        foreach (array_unique(array_values($phoneHashes)) as $hash) {
            $this->suppressions->suppressHash('phone', $hash, (int) $request->getKey());
            $actions[PrivacyActions::SUPPRESSED_PHONE] = true;
        }

        // 6. active COD blocks stay (D7); only the latest expiry is recorded
        $kept = $this->keptBlocksUntil($emailKey, $phones, $now);
        $kept !== null && $actions[PrivacyActions::COD_BLOCKS_KEPT] = $kept;

        // 7. the log row
        $request->update([
            'status' => PrivacyRequestModel::STATUS_DONE,
            'actions' => $actions,
            'completed_at' => $now,
            'clear_until' => $now->copy()->addDays((int) config('privacy.clear_view_days')),
        ]);
        return [$request, $phones];
    }

    /** @return list<string> every distinct phone of this person: the account's addresses first, then the orders of this email */
    private function collectPhones(string $emailKey, ?AccountModel $account): array
    {
        $phones = [];

        if ($account !== null) {
            $phones = DB::table('addresses')->where('account_id', $account->getKey())->orderBy('id')->pluck('phone')->all();
        }

        $phones = array_merge($phones, DB::table('orders')->where('email_key', $emailKey)->orderBy('id')->pluck('phone')->all());

        $clean = [];

        foreach ($phones as $phone) {
            $phone = is_string($phone) ? trim($phone) : '';

            if ($phone !== '' && mb_strlen($phone) <= 32 && ! in_array($phone, $clean, true)) {
                $clean[] = $phone;
            }
        }

        return $clean;
    }

    /** The latest expiry (`Y-m-d H:i:s`, or `permanent`) of the ACTIVE blocks on this email or these phones; null when none is active. @param list<string> $phones */
    private function keptBlocksUntil(string $emailKey, array $phones, \Illuminate\Support\Carbon $now): ?string
    {
        $phoneKeys = array_values(array_filter(array_map(static fn (string $phone): ?string => OrderBlockService::keyFor(OrderBlockType::PHONE, $phone), $phones)));

        $blocks = OrderBlockModel::query()->active($now)->where(function ($query) use ($emailKey, $phoneKeys): void {
            $query->where(fn ($inner) => $inner->where('type', OrderBlockType::EMAIL->value)->where('value_key', $emailKey));

            if ($phoneKeys !== []) {
                $query->orWhere(fn ($inner) => $inner->where('type', OrderBlockType::PHONE->value)->whereIn('value_key', $phoneKeys));
            }
        })->get();

        if ($blocks->isEmpty()) {
            return null;
        }

        if ($blocks->contains(fn (OrderBlockModel $block): bool => $block->expires_at === null)) {
            return 'permanent';
        }

        return $blocks->max('expires_at')->format('Y-m-d H:i:s');
    }

    /** @throws InvalidArgumentException|ValidationException */
    private function checkedReason(PrivacySource $source, ?StaffActor $staff, ?string $reason): ?string
    {
        if ($source === PrivacySource::ADMIN && $staff === null) {
            throw new InvalidArgumentException('An admin privacy request needs the staff member who made it.');
        }

        $reason = $reason === null ? null : trim($reason);
        $required = $source === PrivacySource::ADMIN;

        if (! $required && ($reason === null || $reason === '')) {
            return null;
        }

        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500', new PlainText()]])->validate();

        return $reason;
    }
}
