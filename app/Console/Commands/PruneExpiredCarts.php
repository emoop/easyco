<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use EasyCo\Cart\Contracts\CartRepository;
use Illuminate\Console\Command;

/**
 * `php artisan cart:prune` — deletes every cart past its expires_at
 * (30 days for account carts, 10 for guest carts, refreshed on every
 * write — cart-domain-design.md §9).
 *
 * NOTHING SCHEDULES THIS YET, deliberately: this project has no
 * scheduler wired up at all, and quietly introducing one as a side
 * effect of Cart would be a separate infrastructure decision, not
 * this task's to make. A future deployment/scheduling task is
 * expected to add this to the Laravel scheduler (or an OS-level cron/
 * Task Scheduler entry) — flagged here and in cart-domain-design.md
 * §Deferred, not forgotten.
 */
class PruneExpiredCarts extends Command
{
    protected $signature = 'cart:prune';

    protected $description = 'Delete carts past their expiry (not scheduled automatically — see class docblock)';

    public function handle(CartRepository $carts): int
    {
        $deleted = $carts->deleteExpired(new DateTimeImmutable());

        $this->info("Pruned {$deleted} expired cart(s).");

        return self::SUCCESS;
    }
}
