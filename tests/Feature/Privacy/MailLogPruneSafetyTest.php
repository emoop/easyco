<?php

namespace Tests\Feature\Privacy;

use App\Console\Commands\ReconcileOrderConfirmations;
use App\Mail\SendMailJob;
use App\Privacy\PrivacySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * ACC-4a: mail:prune-log must never lead to a second order confirmation. The idempotency key `order.confirmation:{id}` is what stops a duplicate, and the
 * prune deletes it: it is safe only because mail:reconcile-order-confirmations (the one other sender) looks at the last 24 hours, and the shortest mail log
 * life is 30 days. Both facts are pinned here.
 */
class MailLogPruneSafetyTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegionOrders();
    }

    private function placedAt(string $orderId, string $when): void
    {
        DB::table('orders')->where('id', $orderId)->update(['placed_at' => $when]);
        DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$orderId)->update(['queued_at' => $when]);
    }

    public function test_the_confirmation_of_an_old_order_whose_mail_log_row_was_pruned_is_never_sent_again(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $orderId = (string) DB::table('orders')->value('id');
        $this->assertSame(1, DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$orderId)->count(), 'the hook wrote the key');

        $this->placedAt($orderId, now()->subDays(400)->toDateTimeString());

        $this->artisan('mail:prune-log')->assertSuccessful();
        $this->assertSame(0, DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$orderId)->count(), 'the prune deleted the row');

        Bus::fake([SendMailJob::class]);
        $this->artisan('mail:reconcile-order-confirmations')->expectsOutputToContain('Queued 0 order confirmation(s).')->assertSuccessful();

        Bus::assertNotDispatched(SendMailJob::class);
        $this->assertSame(0, DB::table('mail_log')->count(), 'and no new row was reserved');
    }

    public function test_the_same_reconcile_does_queue_the_confirmation_of_a_recent_order_that_has_none(): void
    {
        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $orderId = (string) DB::table('orders')->value('id');
        DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$orderId)->delete();
        $this->placedAt($orderId, now()->subHour()->toDateTimeString());

        Bus::fake([SendMailJob::class]);
        $this->artisan('mail:reconcile-order-confirmations')->expectsOutputToContain('Queued 1 order confirmation(s).')->assertSuccessful();

        Bus::assertDispatched(SendMailJob::class);
    }

    public function test_the_reconcile_window_is_shorter_than_the_shortest_mail_log_life(): void
    {
        $this->assertLessThan(PrivacySettings::MIN_MAIL_LOG_RETENTION_DAYS * 24, ReconcileOrderConfirmations::WINDOW_HOURS);
        $this->assertLessThan(PrivacySettings::MIN_MAIL_LOG_RETENTION_DAYS * 24 / 2, ReconcileOrderConfirmations::WINDOW_HOURS, 'with a wide margin, not by an hour');
    }
}
