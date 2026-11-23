<?php

namespace Tests\Feature\Privacy;

use App\Enums\OrderBlockType;
use App\Mail\SendAccountMailJob;
use App\Privacy\ErasedCustomer;
use App\Privacy\Persistence\CustomerNoteModel;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyEraser;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyLog;
use App\Privacy\PrivacySource;
use App\Privacy\StaffActor;
use App\Privacy\SuppressionList;
use App\Services\OrderBlockService;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/**
 * ACC-4a, Parts C and D: the eraser («forget me»), the suppression list and the forget log. Real orders through POST /api/checkout, a real account with a
 * real session, the real tables; what must NOT change (orders, snapshots, attributions, order events) is compared row for row.
 */
class PrivacyEraserTest extends TestCase
{
    use DrivesAccountApi;
    use PlacesRegionOrders;
    use RefreshDatabase;

    /** the tables D1 says «forget me» never touches (orders are compared without account_id and address_id, the two links the database sets to NULL) */
    private const UNTOUCHED = ['orders', 'order_placement_snapshots', 'order_invoice_details', 'order_attributions', 'order_events'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
    }

    // ---- fixtures ---------------------------------------------------------------------------------------------------------------

    /** a verified account, logged in, with one saved address (phone +359888111111) and one order (phone +359888000000) */
    private function accountWithOrder(string $email = 'me@example.com'): AccountModel
    {
        $account = $this->verifiedAccount($email);
        $this->login($email)->assertOk();

        DB::table('addresses')->insert([
            'account_id' => $account->getKey(), 'delivery_type' => 'street_address', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov',
            'phone' => '+359888111111', 'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha 1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->checkout($this->streetFields('BG'))->assertStatus(201);
        $this->regionCart(); // a LIVE cart of the account (the one the order used is claimed)

        return $account;
    }

    /** @return array<string, string> table => json of its rows */
    private function snapshot(): array
    {
        $out = [];

        foreach (self::UNTOUCHED as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(function (object $row) use ($table): array {
                $row = (array) $row;
                if ($table === 'orders') {
                    $row['account_id'] = $row['address_id'] = '(unlinked by the database)';
                }

                return $row;
            });
            $out[$table] = json_encode($rows, JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    /** @param array<string, string> $before */
    private function assertUntouched(array $before): void
    {
        foreach ($before as $table => $json) {
            $was = json_decode($json, true);
            $now = json_decode($this->snapshot()[$table], true);
            $this->assertSame(count($was), count($now), "{$table}: the number of rows changed");

            foreach ($was as $i => $row) {
                $this->assertSame([], array_keys(array_diff_assoc($row, $now[$i])), "{$table} row {$i}: these columns changed (D1: «forget me» never touches this table)");
            }
        }
    }

    private function erase(string $email = 'me@example.com', PrivacySource $source = PrivacySource::STOREFRONT, ?StaffActor $staff = null, ?string $reason = null): PrivacyRequestModel
    {
        return app(PrivacyEraser::class)->erase($email, $source, $staff, $reason);
    }

    private function mailLog(string $template, string $to): void
    {
        DB::table('mail_log')->insert([
            'template_key' => $template, 'idempotency_key' => $template.'|'.$to.'|'.uniqid(), 'category' => 'transactional', 'to_email' => $to, 'locale' => 'en',
            'status' => 'queued', 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- 2: an account with orders ----------------------------------------------------------------------------------------------

    public function test_erasing_an_account_deletes_what_hangs_on_it_and_leaves_every_order_table_byte_for_byte(): void
    {
        $account = $this->accountWithOrder();
        CustomerNoteModel::query()->create(['email_key' => 'me@example.com', 'body' => 'VIP, calls twice']);
        DB::table('account_password_reset_tokens')->insert(['email' => 'me@example.com', 'token_hash' => str_repeat('a', 64), 'created_at' => now()]);
        $other = $this->verifiedAccount('other@example.com');
        DB::table('account_email_changes')->insert(['account_id' => $other->getKey(), 'new_email' => 'me@example.com', 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->addDay(), 'created_at' => now()]);
        $this->mailLog('account.verify', 'me@example.com');

        $before = $this->snapshot();
        $this->assertNotSame('[]', $before['orders']);
        $this->assertNotSame('[]', $before['order_attributions']);
        $this->assertNotSame('[]', $before['order_events']);

        $request = $this->erase(' ME@Example.com ');

        $this->assertSame(0, AccountModel::withTrashed()->where('email', 'me@example.com')->count(), 'the account is gone');
        $this->assertSame(1, AccountModel::withTrashed()->count(), 'and only the other account is left');
        $this->assertSame(0, DB::table('addresses')->where('account_id', $account->getKey())->count());
        $this->assertSame(0, DB::table('carts')->where('account_id', $account->getKey())->count());
        $this->assertSame(0, DB::table('account_password_reset_tokens')->where('email', 'me@example.com')->count());
        $this->assertSame(0, CustomerNoteModel::query()->count(), 'notes left');

        $this->assertUntouched($before);
        $this->assertNull(DB::table('orders')->value('account_id'), 'the order is unlinked from the account');
        $this->assertNull(DB::table('orders')->value('address_id'), 'and from the saved address that was deleted');
        $this->assertSame('me@example.com', DB::table('orders')->value('email'), 'and keeps its email');

        $this->assertSame('erase', $request->kind);
        $this->assertSame('done', $request->status);
        $this->assertSame('storefront', $request->source);
        $this->assertSame('me@example.com', $request->email);
        $this->assertSame('+359888111111', $request->phone, 'the first collected phone: the account\'s address');
        $this->assertSame((string) $account->getKey(), (string) $request->account_id);
        $this->assertSame(app(PrivacyHash::class)->email('me@example.com'), $request->email_hash);
        $this->assertSame(app(PrivacyHash::class)->phone('+359888111111'), $request->phone_hash);
        $this->assertEqualsCanonicalizing(
            ['account_deleted', 'addresses_deleted', 'carts_deleted', 'reset_tokens_deleted', 'email_changes_deleted', 'staff_note_deleted', 'mail_log_masked', 'suppressed_email', 'suppressed_phone', 'hook_customer_erased'],
            array_keys($request->actions),
        );
        $this->assertSame(2, $request->actions['addresses_deleted'], 'the saved one and the one the checkout saved');
        $this->assertSame(1, $request->actions['carts_deleted']);
        $this->assertSame(1, $request->actions['reset_tokens_deleted'], 'the password-reset tokens only');
        $this->assertSame(1, $request->actions['email_changes_deleted'], 'the pending email changes, counted on their own');
        $this->assertSame(0, DB::table('account_email_changes')->count(), 'email changes left');
        $this->assertSame(30, (int) round($request->completed_at->diffInDays($request->clear_until)));

        // the email and both phones (the address's and the order's) are on the suppression list
        $this->assertSame(3, DB::table('privacy_suppressions')->count());
    }

    // ---- 3: a guest, and an unknown email ---------------------------------------------------------------------------------------

    public function test_erasing_a_guest_only_email_keeps_its_orders_and_writes_log_and_suppression(): void
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'guest@example.com'])->assertStatus(201);
        $before = $this->snapshot();

        $request = $this->erase('guest@example.com');

        $this->assertUntouched($before);
        $this->assertSame('guest@example.com', DB::table('orders')->value('email'));
        $this->assertNull($request->account_id);
        $this->assertEqualsCanonicalizing(['suppressed_email', 'suppressed_phone', 'hook_customer_erased'], array_keys($request->actions));
        $this->assertSame('+359888000000', $request->phone);
    }

    public function test_erasing_an_email_nobody_ever_used_is_still_honoured(): void
    {
        $request = $this->erase('nobody@example.com');

        $this->assertSame('done', $request->status);
        $this->assertEqualsCanonicalizing(['suppressed_email', 'hook_customer_erased'], array_keys($request->actions));
        $this->assertNull($request->phone);
        $this->assertTrue(app(SuppressionList::class)->isSuppressed('nobody@example.com'));
    }

    // ---- 4: COD blocks ----------------------------------------------------------------------------------------------------------

    private function block(OrderBlockType $type, string $value, ?string $expires): void
    {
        DB::table('order_blocks')->insert([
            'type' => $type->value, 'value_key' => OrderBlockService::keyFor($type, $value), 'value_display' => $value, 'reason' => 'refused delivery',
            'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_an_active_cod_block_stays_and_is_recorded_with_its_expiry_and_an_expired_one_is_not_listed(): void
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'guest@example.com'])->assertStatus(201);
        $soon = now()->addDays(5)->startOfSecond();
        $later = now()->addDays(40)->startOfSecond();

        $this->block(OrderBlockType::PHONE, '+359888000000', $later->toDateTimeString());
        $this->block(OrderBlockType::EMAIL, 'guest@example.com', $soon->toDateTimeString());
        $this->block(OrderBlockType::EMAIL, 'guest@example.com', now()->subDays(3)->toDateTimeString());

        $request = $this->erase('guest@example.com');

        $this->assertSame(3, DB::table('order_blocks')->count(), 'no block is lifted by «forget me»');
        $this->assertSame($later->format('Y-m-d H:i:s'), $request->actions['cod_blocks_kept']);
    }

    public function test_a_permanent_block_is_recorded_as_permanent_and_only_expired_ones_leave_no_trace(): void
    {
        $this->block(OrderBlockType::EMAIL, 'a@example.com', null);
        $this->assertSame('permanent', $this->erase('a@example.com')->actions['cod_blocks_kept']);

        $this->block(OrderBlockType::EMAIL, 'b@example.com', now()->subDay()->toDateTimeString());
        $this->assertArrayNotHasKey('cod_blocks_kept', $this->erase('b@example.com')->actions);
    }

    // ---- 5: an admin request ----------------------------------------------------------------------------------------------------

    public function test_an_admin_request_needs_a_staff_member_and_a_plain_reason_and_writes_nothing_otherwise(): void
    {
        $staff = new StaffActor(7, 'Admin Tester');

        foreach ([null, '', '   ', str_repeat('я', 501), "line\x07bell"] as $reason) {
            try {
                $this->erase('me@example.com', PrivacySource::ADMIN, $staff, $reason);
                $this->fail('the reason '.json_encode($reason).' must be refused');
            } catch (ValidationException) {
                // refused
            }
        }

        $this->expectedNothingWritten();

        try {
            $this->erase('me@example.com', PrivacySource::ADMIN, null, 'phone request');
            $this->fail('an admin request without a staff member must be refused');
        } catch (\InvalidArgumentException) {
            // refused
        }

        $this->expectedNothingWritten();

        $request = $this->erase('me@example.com', PrivacySource::ADMIN, $staff, '  Asked by phone, 20 Nov  ');
        $this->assertSame('admin', $request->source);
        $this->assertSame(7, (int) $request->staff_id);
        $this->assertSame('Admin Tester', $request->staff_name);
        $this->assertSame('Asked by phone, 20 Nov', $request->reason);
    }

    private function expectedNothingWritten(): void
    {
        $this->assertSame(0, DB::table('privacy_requests')->count());
        $this->assertSame(0, DB::table('privacy_suppressions')->count());
    }

    public function test_the_database_itself_refuses_an_admin_erase_without_a_reason(): void
    {
        $row = ['kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];

        $this->expectException(QueryException::class);

        try {
            DB::table('privacy_requests')->insert($row);
        } finally {
            $this->assertSame(0, DB::table('privacy_requests')->count());
            // the same row WITH a reason, and a site request without one, are fine
            DB::table('privacy_requests')->insert($row + ['reason' => 'phone']);
            DB::table('privacy_requests')->insert(['source' => 'storefront', 'email_hash' => str_repeat('b', 64)] + $row);
            $this->assertSame(2, DB::table('privacy_requests')->count());
        }
    }

    // ---- 6: the hook ------------------------------------------------------------------------------------------------------------

    public function test_the_hook_fires_once_after_the_commit_with_every_phone_and_a_listener_can_add_an_action(): void
    {
        $this->accountWithOrder();
        $seen = [];
        $statusSeen = null;

        Hook::action('customer.erased', function (ErasedCustomer $customer) use (&$seen, &$statusSeen): void {
            $seen[] = $customer;
            $statusSeen = PrivacyRequestModel::query()->find($customer->requestId)?->status;
            app(PrivacyLog::class)->addAction($customer->requestId, 'mailchimp_removed');
            app(PrivacyLog::class)->addAction($customer->requestId, 'facebook_audience', 'queued');
        });

        $request = $this->erase();

        $this->assertCount(1, $seen);
        $this->assertSame('me@example.com', $seen[0]->email);
        $this->assertSame((int) $request->getKey(), $seen[0]->requestId);
        $this->assertSame(['+359888111111', '+359888000000'], $seen[0]->phones);
        $this->assertSame('done', $statusSeen, 'the erasure is committed before the listeners run');
        $this->assertTrue($request->actions['mailchimp_removed']);
        $this->assertSame('queued', $request->actions['facebook_audience']);
        $this->assertTrue($request->actions['hook_customer_erased']);
    }

    public function test_a_listener_that_throws_is_logged_and_does_not_undo_the_erasure(): void
    {
        $this->accountWithOrder();
        $logger = \Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with(\Mockery::any(), \Mockery::on(static fn (array $context): bool => $context['hook'] === 'customer.erased' && $context['message'] === 'audience down'));
        $this->app->instance(HookRegistry::class, new HookRegistry($logger));

        Hook::action('customer.erased', static function (): void {
            throw new \RuntimeException('audience down');
        }, 5);
        Hook::action('customer.erased', function (ErasedCustomer $customer): void {
            app(PrivacyLog::class)->addAction($customer->requestId, 'second_listener_ran');
        }, 20);

        $request = $this->erase();

        $this->assertSame('done', $request->status);
        $this->assertSame(0, AccountModel::withTrashed()->count(), 'accounts left');
        $this->assertTrue($request->actions['second_listener_ran'], 'the listeners after it still run');
        $this->assertTrue(app(SuppressionList::class)->isSuppressed('me@example.com'));
    }

    public function test_a_listener_cannot_write_an_action_of_a_bad_shape(): void
    {
        $request = $this->erase('x@example.com');

        foreach ([['Bad Key', true], ['ok_key', ['nested']], ['ok_key', str_repeat('x', 256)], ['', true]] as [$key, $value]) {
            try {
                app(PrivacyLog::class)->addAction($request->getKey(), $key, $value);
                $this->fail('the action '.json_encode([$key, $value]).' must be refused');
            } catch (\InvalidArgumentException|\TypeError) {
                // refused
            }
        }

        $this->assertArrayNotHasKey('ok_key', $request->fresh()->actions);
    }

    // ---- 7: the mails -----------------------------------------------------------------------------------------------------------

    public function test_the_final_mail_goes_through_the_account_mail_job_and_the_mail_log_is_masked_only_for_account_mails(): void
    {
        $this->accountWithOrder();
        $this->mailLog('account.verify', 'me@example.com');
        $this->mailLog('account.password_reset', 'ME@example.com');
        $this->mailLog('order.confirmation', 'me@example.com');
        $this->mailLog('account.verify', 'someone-else@example.com');
        $orderMails = DB::table('mail_log')->where('template_key', 'order.confirmation')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $before = DB::table('mail_log')->count();
        Queue::fake();

        $request = $this->erase();

        Queue::assertPushed(SendAccountMailJob::class, static fn (SendAccountMailJob $job): bool => $job->templateKey === 'privacy.erased' && $job->email === 'me@example.com');
        $this->assertSame(0, DB::table('mail_log')->where('template_key', 'privacy.erased')->count(), 'never in the mail log');
        $this->assertSame($before, DB::table('mail_log')->count(), 'no mail_log row was added');

        $this->assertSame(2, DB::table('mail_log')->where('to_email', 'erased@invalid')->count());
        $this->assertSame(2, $request->actions['mail_log_masked']);
        $this->assertSame($orderMails, DB::table('mail_log')->where('template_key', 'order.confirmation')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'order mails stay with the order');
        $this->assertSame(1, DB::table('mail_log')->where('to_email', 'someone-else@example.com')->count());
    }

    public function test_erase_refuses_to_run_inside_a_transaction_before_writing_anything(): void
    {
        $this->accountWithOrder();
        $fired = false;
        Hook::action('customer.erased', function () use (&$fired): void {
            $fired = true;
        });
        Queue::fake();

        try {
            DB::transaction(fn () => $this->erase());
            $this->fail('erase() inside a transaction must be refused');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('must not run inside a transaction', $e->getMessage());
        }

        $this->assertSame(1, AccountModel::withTrashed()->count(), 'nothing was deleted');
        $this->assertSame(0, DB::table('privacy_requests')->count());
        $this->assertSame(0, DB::table('privacy_suppressions')->count());
        $this->assertFalse($fired, 'the hook did not fire');
        Queue::assertNothingPushed();

        $this->erase(); // outside: fine
        $this->assertSame(1, DB::table('privacy_requests')->count());
    }

    public function test_the_final_mail_renders_in_both_languages_with_the_shop_name_and_no_link(): void
    {
        $definition = app(\App\Mail\MailTemplates::class)->get(\App\Mail\MailTemplates::PRIVACY_ERASED);

        foreach (['bg' => 'изтрити', 'en' => 'erased'] as $locale => $word) {
            $mail = app(\App\Mail\TemplateRenderer::class)->render($definition, $locale, ['shop_name' => 'Raf Shop'], []);

            $this->assertStringContainsString('Raf Shop', $mail->subject);
            $this->assertStringContainsString($word, $mail->subject);
            $this->assertStringContainsString('Raf Shop', $mail->text);
            $this->assertStringNotContainsString('{{', $mail->html.$mail->text);
            $this->assertStringNotContainsString('http', $mail->html, 'nothing to click');
            $this->assertStringNotContainsString($locale === 'bg' ? 'профила' : 'your account', $mail->text, 'no claim about the account');
            $this->assertStringNotContainsString($locale === 'bg' ? 'Повече не получавате' : 'not receive messages', $mail->text, 'no promise that no message ever comes again');
            $this->assertStringContainsString($locale === 'bg' ? 'неприключена поръчка' : 'unfinished order', $mail->text);
        }
    }

    // ---- 8: twice ---------------------------------------------------------------------------------------------------------------

    public function test_erasing_the_same_email_twice_does_not_fail_and_leaves_one_suppression_per_hash(): void
    {
        $this->accountWithOrder();
        $first = $this->erase();
        $this->travel(PrivacyEraser::DOUBLE_SUBMIT_SECONDS + 1)->seconds(); // ACC-4e-2: inside the window the second would be a double submit and write nothing (see PrivacyEraserReuseTest)
        $second = $this->erase('Me@Example.com');

        $this->assertNotSame($first->getKey(), $second->getKey(), 'two log rows: both requests were honoured');
        $this->assertSame(1, DB::table('privacy_suppressions')->where('kind', 'email')->count());
        $this->assertSame(2, DB::table('privacy_suppressions')->where('kind', 'phone')->count(), 'the second run still sees the phones of the orders');
        $this->assertSame(['suppressed_email', 'suppressed_phone', 'hook_customer_erased'], array_keys($second->actions));
    }

    public function test_a_suppression_that_a_concurrent_erasure_already_wrote_is_not_an_error(): void
    {
        $first = $this->erase('race@example.com');
        $hash = app(PrivacyHash::class)->email('race@example.com');

        app(SuppressionList::class)->suppressHash('email', $hash, (int) $first->getKey());
        app(SuppressionList::class)->suppressHash('email', $hash, (int) $first->getKey());

        $this->assertSame(1, DB::table('privacy_suppressions')->where('hash', $hash)->count());
    }

    // ---- 11: the suppression list -----------------------------------------------------------------------------------------------

    public function test_the_list_knows_the_email_and_every_phone_and_a_lift_is_reversible_only_by_a_new_erase(): void
    {
        $this->accountWithOrder();
        $list = app(SuppressionList::class);

        $this->assertFalse($list->isSuppressed('me@example.com', '+359888111111'));
        $this->erase();

        $this->assertTrue($list->isSuppressed('ME@example.com'));
        $this->assertTrue($list->isSuppressed(null, '+359888111111'));
        $this->assertTrue($list->isSuppressed(null, '0888 000 000'), 'a phone of an order, in its national form');
        $this->assertTrue($list->isSuppressed('nobody@example.com', '+359888111111'), 'either one is enough');
        $this->assertFalse($list->isSuppressed('other@example.com', '+359888999999'));
        $this->assertFalse($list->isSuppressed(null, null));

        $this->assertTrue($list->lift('me@example.com', 'Signed up again with a confirmed double opt-in'));
        $this->assertFalse($list->isSuppressed('me@example.com'));
        $this->assertTrue($list->isSuppressed(null, '+359888111111'), 'the phone is a separate suppression');
        $this->assertFalse($list->lift('me@example.com', 'again'), 'nothing active is left to lift');
        $this->travel(PrivacyEraser::DOUBLE_SUBMIT_SECONDS + 1)->seconds(); // ACC-4e-2: a DELIBERATE later erase, not a double submit
        $this->assertSame(1, DB::table('privacy_suppressions')->where('kind', 'email')->whereNotNull('lifted_at')->count(), 'the row stays, lifted');

        $this->erase();
        $this->assertTrue($list->isSuppressed('me@example.com'), 'a new erase puts it back');
        $this->assertSame(1, DB::table('privacy_suppressions')->where('kind', 'email')->count());
    }

    public function test_lifting_needs_a_plain_reason(): void
    {
        $this->erase('a@example.com');

        foreach (['', '  ', str_repeat('x', 501), "ba\x00d"] as $reason) {
            try {
                app(SuppressionList::class)->lift('a@example.com', $reason);
                $this->fail('the reason '.json_encode($reason).' must be refused');
            } catch (\InvalidArgumentException) {
                // refused
            }
        }

        $this->assertTrue(app(SuppressionList::class)->isSuppressed('a@example.com'));
    }

    public function test_the_database_keeps_one_suppression_per_hash_and_refuses_an_unknown_kind(): void
    {
        $request = $this->erase('unique@example.com');
        $hash = app(PrivacyHash::class)->email('unique@example.com');
        $row = ['hash' => $hash, 'request_id' => $request->getKey(), 'created_at' => now(), 'updated_at' => now()];

        try {
            DB::table('privacy_suppressions')->insert(['kind' => 'email'] + $row);
            $this->fail('a second (kind, hash) must be refused by the unique key');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
            $this->assertSame(1062, $e->errorInfo[1]);
        }

        $this->expectException(QueryException::class);
        DB::table('privacy_suppressions')->insert(['kind' => 'fax', 'hash' => str_repeat('c', 64)] + array_slice($row, 1));
    }

    public function test_the_staff_note_is_plain_bounded_and_one_per_email(): void
    {
        CustomerNoteModel::query()->create(['email_key' => 'n@example.com', 'body' => str_repeat('я', 2000)]);

        foreach ([['n@example.com', 'second note for the same email'], ['m@example.com', '   '], ['m@example.com', str_repeat('x', 2001)]] as [$key, $body]) {
            try {
                CustomerNoteModel::query()->create(['email_key' => $key, 'body' => $body]);
                $this->fail('the note '.json_encode(mb_substr($body, 0, 10)).' must be refused by the database');
            } catch (QueryException) {
                // refused
            }
        }

        $this->assertSame(1, CustomerNoteModel::query()->count());
    }
}
