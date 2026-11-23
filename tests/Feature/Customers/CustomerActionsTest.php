<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\CustomerPage;
use App\Mail\AccountMails;
use App\Mail\SendAccountMailJob;
use App\Models\OrderBlockModel;
use App\Privacy\BuildPrivacyExportJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyConfirmationRequester;
use App\Privacy\PrivacyExportRequester;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyMailNotQueued;
use App\Privacy\PrivacyRequestAlreadyOpen;
use App\Services\OrderBlockService;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Account\Security\SignedLink;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md §6, D2, §10): the actions of the customer page: «Изтегли данните», «Поискай потвърждение», «Изпрати отново» (PRIVACY_MANAGE) and the cash-on-delivery card
 * (ORDER_MANAGE, the order view's own service). Every permission is proved by calling the Livewire actions DIRECTLY, the way a client could, not through a hidden button.
 */
class CustomerActionsTest extends TestCase
{
    use BuildsShippingZones;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        config(['app.url' => 'https://shop.example']);
    }

    private function page(int $orderId): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CustomerPage::class, ['key' => 'o-'.$orderId]);
    }

    private function hash(string $email = 'me@example.com'): string
    {
        return (string) app(PrivacyHash::class)->email($email);
    }

    /** @param array<string, mixed> $o */
    private function request(array $o = []): PrivacyRequestModel
    {
        return PrivacyRequestModel::query()->create($o + ['kind' => 'export', 'source' => 'admin', 'status' => 'confirmed', 'email' => 'me@example.com', 'email_hash' => $this->hash(), 'confirmed_at' => now()->subHour(), 'staff_id' => 3, 'staff_name' => 'Asker']);
    }

    /** the unix `expires` of the link a mail job carries */
    private function expiresOf(SendAccountMailJob $job): int
    {
        parse_str((string) parse_url($job->links['action_link']['url'], PHP_URL_QUERY), $q);

        return (int) $q['expires'];
    }

    // ---- the permission, called directly -------------------------------------------------------------------------------------------

    /** ★ CUSTOMER_VIEW without PRIVACY_MANAGE: the three privacy actions called directly are refused and change nothing */
    public function test_a_user_with_customer_view_but_without_privacy_manage_cannot_run_the_privacy_actions(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $open = $this->request(['status' => 'awaiting_confirmation', 'created_at' => now()]);
        $confirmedBefore = PrivacyRequestModel::query()->count();
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW, Permission::ORDER_VIEW]);
        Queue::fake();

        $page = $this->page($id);

        foreach ([['export', []], ['confirmation', ['kind' => 'export']], ['resend', ['id' => $open->getKey()]]] as [$name, $arguments]) {
            try {
                $page->call('mountAction', $name, $name === 'resend' ? $arguments : [])->call('callMountedAction');
            } catch (\Throwable) {
                // refused (403): either way nothing may change
            }
        }

        $this->assertSame($confirmedBefore, PrivacyRequestModel::query()->count(), 'no row was made');
        $this->assertSame('awaiting_confirmation', $open->fresh()->status);
        Queue::assertNothingPushed();
    }

    // ---- «Изтегли данните» ---------------------------------------------------------------------------------------------------------

    public function test_the_export_action_makes_a_new_row_and_queues_the_build_when_no_request_was_confirmed(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $staff = $this->actingAsStaff('Administrator');
        Queue::fake();

        $this->page($id)->callAction('export')->assertNotified(__('customers.actions.export_preparing'));

        $row = PrivacyRequestModel::query()->sole();
        $this->assertSame('export', $row->kind);
        $this->assertSame('processing', $row->status);
        $this->assertSame($this->hash(), $row->email_hash);
        $this->assertSame((int) $staff->getKey(), (int) $row->staff_id);
        Queue::assertPushed(BuildPrivacyExportJob::class, 1);
    }

    /** ★ a CONFIRMED export request of this email is built on: no second row, the conditional transition, the person's own confirmation time kept */
    public function test_a_confirmed_export_request_is_reused_and_not_duplicated(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $confirmed = $this->request(['kind' => 'export', 'status' => 'confirmed']);
        $erase = $this->request(['kind' => 'erase', 'status' => 'confirmed', 'reason' => null]);
        $confirmedAt = $confirmed->confirmed_at->toDateTimeString();
        $this->actingAsStaff('Administrator');
        Queue::fake();

        $this->page($id)->callAction('export');

        $this->assertSame(2, PrivacyRequestModel::query()->count(), 'no new row');
        $this->assertSame('processing', $confirmed->fresh()->status);
        $this->assertSame($confirmedAt, $confirmed->fresh()->confirmed_at->toDateTimeString());
        $this->assertSame(3, (int) $confirmed->fresh()->staff_id, 'who asked for the confirmation stays');
        $this->assertSame('confirmed', $erase->fresh()->status, 'an erase confirmation is not an export');
        Queue::assertPushed(BuildPrivacyExportJob::class, fn (BuildPrivacyExportJob $job): bool => $job->requestId === (int) $confirmed->getKey());
        Queue::assertPushed(BuildPrivacyExportJob::class, 1);
    }

    /** ★ two clicks at once: the second finds the row already moved (WHERE status = 'confirmed') and dispatches nothing */
    public function test_two_clicks_at_once_run_the_build_once(): void
    {
        $confirmed = $this->request(['kind' => 'export', 'status' => 'confirmed']);
        Queue::fake();
        $raced = false;

        // right after the requester READ the confirmed row, the other click moves it
        DB::listen(function ($query) use ($confirmed, &$raced): void {
            if (! $raced && str_contains($query->sql, 'from `privacy_requests`') && str_contains($query->sql, 'status')) {
                $raced = true;
                DB::table('privacy_requests')->where('id', $confirmed->getKey())->update(['status' => 'processing']);
            }
        });

        app(PrivacyExportRequester::class)->request('me@example.com', null);

        $this->assertTrue($raced);
        Queue::assertNothingPushed();
        $this->assertSame(1, PrivacyRequestModel::query()->count());
    }

    // ---- «Поискай потвърждение» -----------------------------------------------------------------------------------------------------

    public function test_the_confirmation_action_writes_the_row_and_mails_the_link_and_the_dialog_turns_into_resend_when_one_is_open(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $staff = $this->actingAsStaff('Administrator');
        Queue::fake();

        $page = $this->page($id)->callAction('confirmation', ['kind' => 'erase'])->assertNotified(__('customers.confirm.sent'));

        $row = PrivacyRequestModel::query()->sole();
        $this->assertSame('awaiting_confirmation', $row->status);
        $this->assertSame('erase', $row->kind);
        $this->assertSame((int) $staff->getKey(), (int) $row->staff_id);
        Queue::assertPushed(SendAccountMailJob::class, fn (SendAccountMailJob $job): bool => $job->templateKey === 'privacy.confirm' && $job->email === 'me@example.com');

        // the second request of the same email and kind: no second row, the dialog is replaced by «Вече е пратено …» with the resend button
        $page->callAction('confirmation', ['kind' => 'erase']);

        $this->assertSame(1, PrivacyRequestModel::query()->count());
        Queue::assertPushed(SendAccountMailJob::class, 1);
        $this->assertSame('resend', $page->instance()->mountedActions[0]['name'] ?? null);

        // another KIND is a different request
        $this->page($id)->callAction('confirmation', ['kind' => 'export']);
        $this->assertSame(2, PrivacyRequestModel::query()->count());
    }

    /** ★ a second request for the same email and kind makes no row and raises PrivacyRequestAlreadyOpen; after the row expired a new one is allowed */
    public function test_one_open_request_per_email_and_kind_and_a_new_one_after_expiry(): void
    {
        Queue::fake();
        $requester = app(PrivacyConfirmationRequester::class);
        $first = $requester->request('me@example.com', 'export', null);

        try {
            $requester->request('Me@Example.com', 'export', null);
            $this->fail('expected PrivacyRequestAlreadyOpen');
        } catch (PrivacyRequestAlreadyOpen $e) {
            $this->assertTrue($e->request->is($first), 'it carries the open row');
        }

        $this->assertSame(1, PrivacyRequestModel::query()->count());
        Queue::assertPushed(SendAccountMailJob::class, 1);

        // 6 days 23 hours: still open
        PrivacyRequestModel::query()->whereKey($first->getKey())->update(['created_at' => now()->subDays(6)->subHours(23)]);
        $this->assertNotNull($requester->openRow($this->hash(), 'export'));

        // past 7 days: the row is over, a new request makes a new row (the old one is untouched)
        PrivacyRequestModel::query()->whereKey($first->getKey())->update(['created_at' => now()->subDays(7)->subMinute()]);
        $second = $requester->request('me@example.com', 'export', null);

        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, PrivacyRequestModel::query()->count());
        $this->assertSame('awaiting_confirmation', $first->fresh()->status);
    }

    // ---- «Изпрати отново» ----------------------------------------------------------------------------------------------------------

    /** ★ the resent link is a NEW link for the SAME row and its `expires` is created_at + VALID_DAYS, never later */
    public function test_resend_mails_a_new_link_for_the_same_row_that_never_outlives_it(): void
    {
        Carbon::setTestNow('2026-12-01 10:00:00');
        Queue::fake();
        $requester = app(PrivacyConfirmationRequester::class);
        $row = $requester->request('me@example.com', 'export', null);
        $end = $row->created_at->getTimestamp() + 7 * 86400;

        Carbon::setTestNow('2026-12-04 15:30:00');
        $requester->resend($row, null);

        $jobs = Queue::pushed(SendAccountMailJob::class);
        $this->assertCount(2, $jobs);
        $this->assertSame(1, PrivacyRequestModel::query()->count(), 'the same row');

        foreach ($jobs as $job) {
            $this->assertSame($end, $this->expiresOf($job), 'the first mail and the resent one end exactly where the row ends');
            parse_str((string) parse_url($job->links['action_link']['url'], PHP_URL_QUERY), $q);
            $this->assertSame((string) $row->getKey(), $q['id']);
            $this->assertTrue(SignedLink::valid('privacy-confirm', $q['id'], $q['expires'], $q['signature']));
        }

        Carbon::setTestNow();
    }

    public function test_resend_refuses_an_expired_row_and_a_row_that_is_not_waiting(): void
    {
        Queue::fake();
        $requester = app(PrivacyConfirmationRequester::class);

        $expired = $requester->request('a@example.com', 'export', null);
        PrivacyRequestModel::query()->whereKey($expired->getKey())->update(['created_at' => now()->subDays(8)]);
        $confirmed = $this->request(['status' => 'confirmed', 'email' => 'c@example.com', 'email_hash' => $this->hash('c@example.com')]);
        $done = $this->request(['status' => 'done', 'email' => 'd@example.com', 'email_hash' => $this->hash('d@example.com')]);
        $marked = $this->request(['status' => 'expired', 'email' => null, 'email_hash' => $this->hash('e@example.com')]);
        $sent = Queue::pushed(SendAccountMailJob::class)->count();

        foreach ([$expired, $confirmed, $done, $marked] as $row) {
            try {
                $requester->resend($row, null);
                $this->fail('row '.$row->getKey().' must be refused');
            } catch (InvalidArgumentException) {
                // refused
            }
        }

        $this->assertSame($sent, Queue::pushed(SendAccountMailJob::class)->count(), 'no mail went out');
    }

    public function test_the_resend_action_works_only_on_a_row_of_this_customer(): void
    {
        $mine = $this->insertOrder(['email' => 'me@example.com']);
        $this->insertOrder(['email' => 'other@example.com']);
        Queue::fake();
        $requester = app(PrivacyConfirmationRequester::class);
        $own = $requester->request('me@example.com', 'export', null);
        $foreign = $requester->request('other@example.com', 'export', null);
        $this->actingAsStaff('Administrator');
        $sent = Queue::pushed(SendAccountMailJob::class)->count();

        $this->page($mine)->callAction('resend', arguments: ['id' => $foreign->getKey()])->assertNotified(__('customers.confirm.not_resendable'));
        $this->assertSame($sent, Queue::pushed(SendAccountMailJob::class)->count(), 'another customer\'s row is not resent from this page');

        $this->page($mine)->callAction('resend', arguments: ['id' => $own->getKey()])->assertNotified(__('customers.confirm.resent'));
        $this->assertSame($sent + 1, Queue::pushed(SendAccountMailJob::class)->count());
    }

    // ---- the language of the mail --------------------------------------------------------------------------------------------------

    /** ★ the mail is in the SHOP's default locale even when the app (the admin's) locale is `en` */
    public function test_the_mail_is_in_the_shops_default_locale_not_the_admins(): void
    {
        Queue::fake();
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        app()->setLocale('en');
        $requester = app(PrivacyConfirmationRequester::class);

        $row = $requester->request('me@example.com', 'export', null);
        $requester->resend($row, null);

        $jobs = Queue::pushed(SendAccountMailJob::class);
        $this->assertCount(2, $jobs);

        foreach ($jobs as $job) {
            $this->assertSame('bg', $job->locale);
            $this->assertSame(__('mail.privacy_confirm.export', [], 'bg'), $job->scalars['request_type'], 'the request type is written in Bulgarian too');
        }

        app(SiteSettingsRepository::class)->set('site.locale', 'en');
        $en = $requester->request('en@example.com', 'erase', null);
        $this->assertSame('en', Queue::pushed(SendAccountMailJob::class)->last()->locale);
        $this->assertNotNull($en);
    }

    // ---- a mail that cannot be queued ---------------------------------------------------------------------------------------------

    public function test_a_mail_that_cannot_be_queued_is_reported_and_the_row_stays_for_a_resend(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $this->mock(AccountMails::class, function ($mock): void {
            $mock->shouldReceive('privacyConfirmUrl')->andReturn('https://shop.example/x');
            $mock->shouldReceive('privacyConfirmation')->andReturn(false);
        });

        try {
            app(PrivacyConfirmationRequester::class)->request('me@example.com', 'export', null);
            $this->fail('expected PrivacyMailNotQueued');
        } catch (PrivacyMailNotQueued $e) {
            $this->assertSame('awaiting_confirmation', $e->request->fresh()->status, 'the row is kept: «Изпрати отново» can try again');
        }

        // through the page the staff member is told, never «sent»
        PrivacyRequestModel::query()->delete();
        $this->actingAsStaff('Administrator');
        $this->page($id)->callAction('confirmation', ['kind' => 'export'])->assertNotified(__('customers.confirm.mail_failed'));
    }

    // ---- the cash-on-delivery card -------------------------------------------------------------------------------------------------

    /** ★ blocking from the customer page creates the SAME record the order view's service creates */
    public function test_blocking_from_the_customer_page_makes_the_same_record_as_the_service(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $staff = $this->actingAsStaff('Administrator');
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1'); // the activity log is the merchant's opt-in

        $this->page($id)->callAction('block', ['duration' => '180', 'reason' => 'refused parcel'], ['type' => 'phone', 'key' => '+359888123456']);
        $this->page($id)->callAction('block', ['duration' => 'forever', 'reason' => 'abuse'], ['type' => 'email']);

        $phone = OrderBlockModel::query()->where('type', 'phone')->sole();
        $email = OrderBlockModel::query()->where('type', 'email')->sole();

        $this->assertSame('+359888123456', $phone->value_key);
        $this->assertSame('refused parcel', $phone->reason);
        $this->assertSame($id, (int) $phone->source_order_id, 'the order that carried the phone');
        $this->assertSame((int) $staff->getKey(), (int) $phone->created_by);
        $this->assertEqualsWithDelta(now()->addDays(180)->getTimestamp(), $phone->expires_at->getTimestamp(), 5);
        $this->assertSame('me@example.com', $email->value_key);
        $this->assertNull($email->expires_at, 'permanent');

        // what the order view's own service makes for the same values
        OrderBlockModel::query()->delete();
        $direct = app(OrderBlockService::class)->block(\App\Enums\OrderBlockType::PHONE, '+359888123456', 180, 'refused parcel', (string) $id);
        $this->assertSame([$phone->type, $phone->value_key, $phone->value_display, $phone->reason, $phone->source_order_id, $phone->created_by], [$direct->type, $direct->value_key, $direct->value_display, $direct->reason, $direct->source_order_id, $direct->created_by]);

        // it is the same activity-log writer: the order view and this page share the service
        $this->assertGreaterThanOrEqual(1, DB::table('activity_log')->where('entity_type', 'order_block')->count());
    }

    public function test_unblock_revokes_the_active_block_and_only_one_of_this_customer(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $this->insertOrder(['email' => 'other@example.com', 'phone' => '+359877999888']);
        $this->actingAsStaff('Administrator');
        $service = app(OrderBlockService::class);
        $mine = $service->block(\App\Enums\OrderBlockType::PHONE, '+359888123456', 180);
        $theirs = $service->block(\App\Enums\OrderBlockType::PHONE, '+359877999888', 180);

        $this->page($id)->callAction('unblock', arguments: ['type' => 'phone', 'key' => '+359877999888']);
        $this->assertNull($theirs->fresh()->revoked_at, 'a phone that is not this customer\'s cannot be revoked from here');

        $this->page($id)->callAction('unblock', arguments: ['type' => 'phone', 'key' => '+359888123456']);
        $this->assertNotNull($mine->fresh()->revoked_at);
    }

    public function test_the_card_shows_the_state_the_reason_and_the_source_order_of_each_value(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $this->actingAsStaff('Administrator');
        app(OrderBlockService::class)->block(\App\Enums\OrderBlockType::PHONE, '+359888123456', null, 'chargeback', (string) $id);
        app(OrderBlockService::class)->block(\App\Enums\OrderBlockType::EMAIL, 'me@example.com', 30);

        $html = (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent();

        $this->assertStringContainsString(__('customers.cod.forever'), $html);
        $this->assertStringContainsString('chargeback', $html);
        $this->assertStringContainsString('#'.$id, $html);
        $this->assertStringContainsString(__('customers.cod.until', ['date' => now()->addDays(30)->format('d.m.Y')]), $html);
    }

    public function test_the_phones_are_deduplicated_by_the_key_the_blocks_use(): void
    {
        $accountId = $this->insertAccount('me@example.com');
        DB::table('addresses')->insert(['account_id' => $accountId, 'delivery_type' => 'street_address', 'recipient_first_name' => 'a', 'recipient_last_name' => 'b', 'phone' => '0888 123 456', 'country' => 'BG', 'city' => 'X', 'postal_code' => '1', 'address_line_1' => 'Y', 'created_at' => now(), 'updated_at' => now()]);
        $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359877000111']);

        $phones = app(\App\Customers\CustomerPageReader::class)->phones('me@example.com', $accountId);

        $keys = array_map(fn (array $p): ?string => $p['key'], $phones);
        sort($keys);
        $this->assertSame(['+359877000111', '+359888123456'], $keys, 'one entry per key: the order phone and the address phone are the same');
    }

    public function test_a_tampered_phone_or_type_is_refused_on_the_server(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $this->actingAsStaff('Administrator');

        foreach ([['type' => 'phone', 'key' => '+359000000000'], ['type' => 'ip', 'key' => '203.0.113.7'], ['type' => 'phone'], ['type' => 'email', 'key' => 'victim@example.com']] as $arguments) {
            $this->page($id)->callAction('block', ['duration' => '180'], $arguments);
        }

        $blocks = OrderBlockModel::query()->get();
        $this->assertCount(1, $blocks, 'only the email block (always the customer\'s OWN email, whatever the client sent)');
        $this->assertSame('me@example.com', $blocks[0]->value_key);
    }

    /** ★ the COD actions need the order view's own permission, ORDER_MANAGE, checked on the server */
    public function test_the_block_actions_need_order_manage(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com', 'phone' => '+359888123456']);
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW, Permission::ORDER_VIEW]);

        $page = $this->page($id);

        try {
            $page->call('mountAction', 'block', ['type' => 'phone', 'key' => '+359888123456'])->call('callMountedAction');
        } catch (\Throwable) {
            // refused
        }

        $this->assertSame(0, OrderBlockModel::query()->count());

        $html = (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent();
        $this->assertStringNotContainsString('mountAction(&#039;block&#039;', $html, 'no block button without ORDER_MANAGE');
    }
}
