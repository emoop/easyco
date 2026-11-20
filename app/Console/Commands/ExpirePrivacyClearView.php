<?php

namespace App\Console\Commands;

use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan privacy:expire-clear-view` (ACC-4a, acc-4-gdpr-design.md section 5, D6): the email and the phone of a privacy request are visible in clear for
 * 30 days after the erasure (`clear_until`); then only the keyed hashes remain, and the lookup «was there a forget request for this email?» keeps working
 * through them. Nulls `email` and `phone` of every row whose `clear_until` has passed. IDEMPOTENT (a row already cleared is not selected); a cheap no-op
 * when nothing has aged out; only the COUNT is logged, never a value.
 */
class ExpirePrivacyClearView extends Command
{
    protected $signature = 'privacy:expire-clear-view';

    protected $description = 'Remove the clear email and phone of privacy requests whose clear-view period has passed (the hashes stay)';

    public function handle(): int
    {
        $cleared = PrivacyRequestModel::query()
            ->whereNotNull('clear_until')
            ->where('clear_until', '<', now())
            ->where(fn ($query) => $query->whereNotNull('email')->orWhereNotNull('phone'))
            ->update(['email' => null, 'phone' => null]);

        if ($cleared > 0) {
            Log::info('privacy.clear_view_expired', ['count' => $cleared]);
        }

        $this->info("Cleared the email and phone of {$cleared} privacy request(s).");

        return self::SUCCESS;
    }
}
