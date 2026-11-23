<?php

namespace App\Console\Commands;

use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyConfirmationRequester;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan privacy:expire-requests` (ACC-4a, acc-4-gdpr-design.md section 5): a request that waited for its confirmation link for more than
 * PrivacyConfirmationRequester::VALID_DAYS days (7, ACC-4d; was 24 hours) becomes `expired` and loses its clear email and phone (the hashes stay). The SAME constant is the life
 * of the confirmation link, so the link and the request die together. IDEMPOTENT; a cheap no-op when none is that old; only the COUNT is logged.
 */
class ExpirePrivacyRequests extends Command
{
    protected $signature = 'privacy:expire-requests';

    protected $description = 'Expire privacy requests that waited more than 7 days for their confirmation';

    public function handle(): int
    {
        $expired = PrivacyRequestModel::query()
            ->where('status', PrivacyRequestModel::STATUS_AWAITING_CONFIRMATION)
            ->where('created_at', '<', now()->subDays(PrivacyConfirmationRequester::VALID_DAYS))
            ->update(['status' => PrivacyRequestModel::STATUS_EXPIRED, 'email' => null, 'phone' => null]);

        if ($expired > 0) {
            Log::info('privacy.requests_expired', ['count' => $expired]);
        }

        $this->info("Expired {$expired} privacy request(s).");

        return self::SUCCESS;
    }
}
