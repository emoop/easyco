<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use EasyCo\Cart\Contracts\CartRepository;
use Illuminate\Console\Command;

/**
 * `php artisan cart:prune` — deletes every UNCLAIMED cart past its expires_at
 * (30 days for account carts, 10 for guest carts, refreshed on every
 * write — cart-domain-design.md §9). A cart claimed by an order is never
 * deleted (shipping stage 4h): it is the evidence a replay of its checkout
 * is answered from (cart-domain-design.md §14.4). THAT MAKES THIS COMMAND
 * SAFE TO SCHEDULE — it is still NOT scheduled.
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

    protected $description = 'Delete unclaimed carts past their expiry (safe to schedule, not scheduled automatically — see class docblock)';

    public function handle(CartRepository $carts): int
    {
        $deleted = $carts->deleteExpired(new DateTimeImmutable());

        $this->info("Pruned {$deleted} expired cart(s).");

        return self::SUCCESS;
    }
}
