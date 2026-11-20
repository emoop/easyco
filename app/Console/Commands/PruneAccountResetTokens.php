<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AccountPasswordController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan accounts:prune-reset-tokens` (ACC-4a, acc-4-gdpr-design.md section 5): `account_password_reset_tokens` is keyed by email with no foreign
 * key, and a token is deleted only when it is used or checked. This deletes the rows older than their own lifetime (AccountPasswordController::RESET_MINUTES,
 * 60): an unused token, however old, is only a hash of a secret plus a person's email. IDEMPOTENT; only the COUNT is logged.
 */
class PruneAccountResetTokens extends Command
{
    protected $signature = 'accounts:prune-reset-tokens';

    protected $description = 'Delete expired account password reset tokens';

    public function handle(): int
    {
        $deleted = DB::table('account_password_reset_tokens')->where('created_at', '<', now()->subMinutes(AccountPasswordController::RESET_MINUTES))->delete();

        if ($deleted > 0) {
            Log::info('privacy.reset_tokens_pruned', ['count' => $deleted]);
        }

        $this->info("Deleted {$deleted} expired reset token(s).");

        return self::SUCCESS;
    }
}
