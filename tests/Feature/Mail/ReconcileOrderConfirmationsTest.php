<?php

namespace Tests\Feature\Mail;

use App\Mail\SendMailJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ReconcileOrderConfirmationsTest extends TestCase
{
    use PlacesOrdersForMail;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacesOrders();
    }

    private function sentCount(): int
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function age(string $orderId, int $minutes): void
    {
        DB::table('orders')->where('id', $orderId)->update(['placed_at' => now()->subMinutes($minutes)]);
    }

    public function test_it_finds_an_order_with_no_log_row_and_ignores_one_that_has_it(): void
    {
        $withRow = $this->placeMailOrder()['order_id']; // the hook ran: row + mail
        $lost = $this->placeMailOrder(['email' => 'lost@example.com'])['order_id'];

        // The process "died" before the hook for the second order: no row, no mail.
        DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$lost)->delete();
        $before = $this->sentCount();
        $this->age($withRow, 30);
        $this->age($lost, 30);

        $this->artisan('mail:reconcile-order-confirmations')->expectsOutput('Queued 1 order confirmation(s).')->assertSuccessful();

        $this->assertSame($before + 1, $this->sentCount());
        $this->assertSame(1, DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$lost)->count());
        $this->assertSame(1, DB::table('mail_log')->where('idempotency_key', 'order.confirmation:'.$withRow)->count());

        // Running it again changes nothing.
        $this->artisan('mail:reconcile-order-confirmations')->expectsOutput('Queued 0 order confirmation(s).')->assertSuccessful();
        $this->assertSame($before + 1, $this->sentCount());
    }

    public function test_it_ignores_orders_that_are_too_new_too_old_or_cancelled(): void
    {
        Bus::fake([SendMailJob::class]);
        $new = $this->placeMailOrder()['order_id'];
        $old = $this->placeMailOrder()['order_id'];
        $cancelled = $this->placeMailOrder()['order_id'];
        DB::table('mail_log')->delete();

        $this->age($new, 1);          // still inside the 5-minute grace: a request may be in flight
        $this->age($old, 60 * 25);    // outside the 24-hour window
        $this->age($cancelled, 30);
        DB::table('orders')->where('id', $cancelled)->update(['status' => 'cancelled']);

        $this->artisan('mail:reconcile-order-confirmations')->expectsOutput('Queued 0 order confirmation(s).')->assertSuccessful();

        $this->assertSame(0, DB::table('mail_log')->count());
    }

    public function test_it_is_scheduled_every_ten_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'mail:reconcile-order-confirmations'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
