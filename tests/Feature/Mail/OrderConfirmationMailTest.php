<?php

namespace Tests\Feature\Mail;

use App\Mail\BankTransferDetails;
use App\Mail\MailDispatcher;
use App\Mail\MailLog;
use App\Mail\SendMailJob;
use App\Mail\TemplateOverrides;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Contracts\OrderRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Stage M1, end to end: a real checkout fires the real `order.placed` hook, the listener queues one mail, the
 * job renders it from the ORDER and its sale-line snapshot and sends it through the `array` mailer (phpunit.xml
 * sets MAIL_MAILER=array and QUEUE_CONNECTION=sync, so the job runs inline).
 */
class OrderConfirmationMailTest extends TestCase
{
    use PlacesOrdersForMail;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlacesOrders();
        FlakyTransport::reset();
    }

    /** @return list<SentMessage> */
    private function sent(): array
    {
        return array_values(Mail::mailer('array')->getSymfonyTransport()->messages()->all());
    }

    private function email(SentMessage $message): Email
    {
        $email = $message->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function logRows(): array
    {
        return DB::table('mail_log')->orderBy('id')->get()->all();
    }

    private function setting(string $key, string $value): void
    {
        app(SiteSettingsRepository::class)->set($key, $value);
    }

    private function useFlakyMailer(int $failures, int $code): void
    {
        Mail::extend('flaky', fn () => new FlakyTransport);
        config(['mail.mailers.flaky' => ['transport' => 'flaky'], 'mail.default' => 'flaky']);
        Mail::purge('flaky');
        FlakyTransport::reset($failures, $code);
    }

    private function runJob(int $logId): void
    {
        app()->call([new SendMailJob($logId), 'handle']);
    }

    public function test_placing_an_order_sends_exactly_one_confirmation_with_the_order_facts(): void
    {
        $placed = $this->placeMailOrder([], 'Blue Shirt', '10.00', 2);

        $rows = $this->logRows();
        $this->assertCount(1, $rows);
        $this->assertSame('order.confirmation:'.$placed['order_id'], $rows[0]->idempotency_key);
        $this->assertSame('sent', $rows[0]->status);
        $this->assertSame('transactional', $rows[0]->category);
        $this->assertSame(1, (int) $rows[0]->attempts);
        $this->assertNotNull($rows[0]->sent_at);

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $email = $this->email($sent[0]);
        $this->assertSame('buyer@example.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString($placed['order_id'], $email->getSubject());
        $this->assertSame($rows[0]->subject, $email->getSubject());

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $this->assertStringContainsString('Blue Shirt', $html);
        $this->assertStringContainsString('MAIL-SKU-', $html);
        $this->assertStringContainsString('20.00 €', $html); // 2 x 10.00 line total and order total
        $this->assertStringContainsString('Vitosha Blvd 1', $html);
        $this->assertStringContainsString('Blue Shirt', $text);
        $this->assertStringNotContainsString('<', $text);
        $this->assertSame('auto-generated', $email->getHeaders()->get('Auto-Submitted')->getBodyAsString());
    }

    public function test_a_double_event_a_replayed_checkout_and_a_retried_job_still_produce_one_mail_and_one_row(): void
    {
        $placed = $this->placeMailOrder();

        // A double event.
        $order = app(OrderRepository::class)->findById($placed['order_id']);
        Hook::fire('order.placed', $order);
        Hook::fire('order.placed', $order);

        // A replayed checkout (answered from the claimed cart; the hook does not fire).
        $this->postJson('/api/checkout', [
            'cart_id' => $this->mailCartId,
            'email' => 'buyer@example.com',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address',
            'country' => 'BG',
            'city' => 'Sofia',
            'address_line_1' => 'Vitosha Blvd 1',
            ...$this->shippingPayload(),
        ])->assertStatus(201)->assertJsonPath('already_placed', true);

        // A retried job for the same row.
        $this->runJob((int) $this->logRows()[0]->id);

        $this->assertCount(1, $this->logRows());
        $this->assertCount(1, $this->sent());
    }

    public function test_two_workers_cannot_send_the_same_mail(): void
    {
        Bus::fake([SendMailJob::class]);
        $this->placeMailOrder();
        $id = (int) $this->logRows()[0]->id;
        $log = app(MailLog::class);

        $this->assertTrue($log->claim($id), 'the first worker claims the row');
        $this->assertFalse($log->claim($id), 'the second worker finds it taken');

        $this->runJob($id); // a job arriving while the row is `sending`
        $this->assertCount(0, $this->sent());

        // A crashed worker's stale `sending` row may be claimed again.
        DB::table('mail_log')->where('id', $id)->update(['updated_at' => now()->subMinutes(MailLog::STALE_SENDING_MINUTES + 1)]);
        $this->runJob($id);
        $this->assertCount(1, $this->sent());
        $this->assertSame('sent', $this->logRows()[0]->status);
    }

    public function test_a_transient_failure_is_retried_and_produces_one_mail(): void
    {
        $this->useFlakyMailer(failures: 1, code: 0);
        // The sync queue driver fails a job at once and never retries; a real queue releases it with the backoff. The
        // retry is therefore driven by hand: the job runs, throws (the queue would release it), runs again.
        Bus::fake([SendMailJob::class]);
        $this->placeMailOrder();
        $id = (int) $this->logRows()[0]->id;

        try {
            $this->runJob($id);
            $this->fail('A transient failure must be rethrown so the queue retries.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('buyer@example.com', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $row = $this->logRows()[0];
        $this->assertSame('queued', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringNotContainsString('buyer@example.com', (string) $row->last_error);
        $this->assertStringNotContainsString('secret', (string) $row->last_error);
        $this->assertStringNotContainsString('smtp://', (string) $row->last_error);

        $this->runJob($id); // the queue's retry

        $row = $this->logRows()[0];
        $this->assertSame('sent', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertCount(1, FlakyTransport::$sent);
        $this->assertCount(1, $this->logRows());
    }

    public function test_a_permanent_failure_is_marked_failed_and_not_retried(): void
    {
        $this->useFlakyMailer(failures: 5, code: 550);

        $this->placeMailOrder();
        $row = $this->logRows()[0];
        $this->assertSame('failed', $row->status);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertStringStartsWith('TransportException', (string) $row->last_error);
        $this->assertStringNotContainsString('buyer@example.com', (string) $row->last_error);

        $this->runJob((int) $row->id); // a `failed` row is never claimed again
        $this->assertSame(1, FlakyTransport::$attempts);
    }

    public function test_the_last_retry_used_up_marks_the_row_failed(): void
    {
        Bus::fake([SendMailJob::class]);
        $this->placeMailOrder();
        $id = (int) $this->logRows()[0]->id;

        (new SendMailJob($id))->failed(new RuntimeException('gave up'));

        $this->assertSame('failed', $this->logRows()[0]->status);
    }

    public function test_checkout_never_breaks_when_the_queue_driver_throws_and_the_reconciliation_recovers_the_mail(): void
    {
        $queue = Mockery::mock(QueueContract::class)->shouldIgnoreMissing();
        $queue->shouldReceive('pushOn')->andThrow(new RuntimeException('queue is down'));
        $queue->shouldReceive('push')->andThrow(new RuntimeException('queue is down'));
        $factory = Mockery::mock(QueueFactory::class);
        $factory->shouldReceive('connection')->andReturn($queue);
        $original = app('queue');
        $this->app->instance('queue', $factory);

        $placed = $this->placeMailOrder(); // 201, no exception

        // The reserved row was released, so the reconciliation can find the order.
        $this->assertCount(0, $this->logRows());
        $this->assertCount(0, $this->sent());

        $this->app->instance('queue', $original);
        DB::table('orders')->where('id', $placed['order_id'])->update(['placed_at' => now()->subMinutes(30)]);
        $this->artisan('mail:reconcile-order-confirmations')->expectsOutput('Queued 1 order confirmation(s).')->assertSuccessful();

        $this->assertCount(1, $this->sent());
    }

    public function test_a_throwing_listener_never_breaks_the_checkout(): void
    {
        $this->app->instance(MailDispatcher::class, new class extends MailDispatcher
        {
            public function __construct()
            {
            }

            public function queueOrderConfirmation(string $orderId, string $email): bool
            {
                throw new RuntimeException('boom');
            }
        });

        $this->placeMailOrder(); // 201 regardless

        $this->assertCount(0, $this->sent());
    }

    public function test_the_content_comes_from_the_snapshot_after_the_product_is_renamed(): void
    {
        Bus::fake([SendMailJob::class]); // queued, not yet sent
        $placed = $this->placeMailOrder([], 'Blue Shirt');

        $this->renameMailProduct($placed['product_id'], 'Totally Renamed Product');

        $this->runJob((int) $this->logRows()[0]->id);

        $html = $this->email($this->sent()[0])->getHtmlBody();
        $this->assertStringContainsString('Blue Shirt', $html);
        $this->assertStringNotContainsString('Totally Renamed Product', $html);
    }

    public function test_both_locales_are_rendered_in_the_store_language(): void
    {
        $this->setting('site.locale', 'bg');
        $this->placeMailOrder(['payment_method' => 'cash_on_delivery']);
        $bg = $this->email($this->sent()[0]);
        $this->assertStringContainsString('Вашата поръчка', $bg->getSubject());
        $this->assertStringContainsString('Наложен платеж', $bg->getHtmlBody());
        $this->assertStringContainsString('Сума за плащане при доставка', $bg->getHtmlBody());
        $this->assertSame('bg', $this->logRows()[0]->locale);

        $this->setting('site.locale', 'en');
        $this->placeMailOrder(['payment_method' => 'cash_on_delivery']);
        $en = $this->email($this->sent()[1]);
        $this->assertStringContainsString('Your order', $en->getSubject());
        $this->assertStringContainsString('Cash on delivery', $en->getHtmlBody());
        $this->assertStringContainsString('Amount to pay on delivery', $en->getHtmlBody());
    }

    public function test_the_bank_transfer_block_is_rendered_from_the_settings_and_omitted_when_they_are_empty(): void
    {
        // Empty settings: the mail still goes out, without the block.
        $this->placeMailOrder(['payment_method' => 'bank_transfer']);
        $empty = $this->email($this->sent()[0]);
        $this->assertStringNotContainsString('Pay by bank transfer', $empty->getHtmlBody());
        $this->assertStringContainsString('Bank transfer', $empty->getHtmlBody()); // the method label line

        // Filled settings.
        $this->setting(BankTransferDetails::HOLDER, 'Raf Ltd <b>');
        $this->setting(BankTransferDetails::BANK, 'Test Bank');
        $this->setting(BankTransferDetails::IBAN, 'BG80BNBG96611020345678');
        $this->setting(BankTransferDetails::BIC, 'BNBGBGSD');
        $this->setting(BankTransferDetails::DEADLINE_DAYS, '3');
        $this->setting(BankTransferDetails::INSTRUCTIONS, "Please write the order number.\n\nThank you <script>alert(1)</script>");

        $placed = $this->placeMailOrder(['payment_method' => 'bank_transfer']);
        $filled = $this->email($this->sent()[1]);
        $html = $filled->getHtmlBody();
        $this->assertStringContainsString('Pay by bank transfer', $html);
        $this->assertStringContainsString('BG80 BNBG 9661 1020 3456 78', $html);
        $this->assertStringContainsString('BNBGBGSD', $html);
        $this->assertStringContainsString('Test Bank', $html);
        $this->assertStringContainsString('Order '.$placed['order_id'], $html);
        $this->assertStringContainsString('within 3 day(s)', $html);
        $this->assertStringContainsString('Please write the order number.', $html);
        $this->assertStringContainsString('Raf Ltd &lt;b&gt;', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('BG80 BNBG', $filled->getTextBody());
    }

    public function test_a_payment_step_that_needs_attention_gets_the_neutral_confirmation(): void
    {
        $this->setting(BankTransferDetails::IBAN, 'BG80BNBG96611020345678');
        Bus::fake([SendMailJob::class]);
        $placed = $this->placeMailOrder(['payment_method' => 'bank_transfer']);
        DB::table('payments')->where('order_id', $placed['order_id'])->update(['attempted_at' => null]);

        $this->runJob((int) $this->logRows()[0]->id);

        $html = $this->email($this->sent()[0])->getHtmlBody();
        $this->assertStringNotContainsString('BG80', $html);
        $this->assertStringNotContainsString('Pay by bank transfer', $html);
        $this->assertStringContainsString('Bank transfer', $html);
    }

    public function test_a_template_that_fails_to_render_falls_back_to_the_shipped_default(): void
    {
        $this->app->instance(TemplateOverrides::class, new class extends TemplateOverrides
        {
            public function find(string $key, string $locale): ?array
            {
                return ['subject' => 'Broken {{ not_a_variable }}', 'body' => 'Hello {{ also_unknown }}'];
            }
        });
        Log::spy();

        $this->placeMailOrder();

        Log::shouldHaveReceived('warning')->with('mail.template_override_failed', Mockery::on(fn (array $c): bool => $c['template'] === 'order.confirmation'))->once();
        $email = $this->email($this->sent()[0]);
        $this->assertStringContainsString('Your order', $email->getSubject());
        $this->assertStringNotContainsString('not_a_variable', $email->getSubject());
        $this->assertStringContainsString('Blue Shirt', $email->getHtmlBody());
    }

    public function test_the_log_mailer_writes_the_mail_to_the_log_and_the_row_is_sent(): void
    {
        // The development default (MAIL_MAILER=log): nothing is delivered, the message is written to the log channel.
        config([
            'logging.channels.mailtest' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\TestHandler::class, 'level' => 'debug'],
            'mail.mailers.log' => ['transport' => 'log', 'channel' => 'mailtest'],
            'mail.default' => 'log',
        ]);
        Mail::purge('log');

        $placed = $this->placeMailOrder();

        $this->assertSame('sent', $this->logRows()[0]->status);
        $records = Log::channel('mailtest')->getLogger()->getHandlers()[0]->getRecords();
        $this->assertCount(1, $records);
        $this->assertStringContainsString('Your order '.$placed['order_id'], (string) $records[0]->message);
        $this->assertCount(0, $this->sent(), 'nothing reached the array transport');
    }

    public function test_an_unusable_recipient_is_recorded_as_skipped_and_nothing_is_sent(): void
    {
        $queued = app(MailDispatcher::class)->queueOrderConfirmation('999', "evil@example.com\r\nBcc: spy@example.com");

        $this->assertFalse($queued);
        $row = $this->logRows()[0];
        $this->assertSame('skipped', $row->status);
        $this->assertSame('invalid_recipient', $row->last_error);
        $this->assertStringNotContainsString("\r", $row->to_email);
        $this->assertStringNotContainsString("\n", $row->to_email);
        $this->assertCount(0, $this->sent());
    }
}
