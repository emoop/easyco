<?php

namespace Tests\Feature\Customers;

use App\Filament\Pages\CustomerPage;
use App\Filament\Pages\Customers;
use App\Mail\SendAccountMailJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyHash;
use App\Services\OrderBlockService;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-2 (acc-4-gdpr-design.md section 4, 10): the «Забрави» action of the customer page: the permission on the server, the required reason, the dialog's facts, and the full effect through the page.
 */
class CustomerForgetTest extends TestCase
{
    use BuildsShippingZones;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    /** @var list<string> */
    private array $hookEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        app(HookRegistry::class)->clear('customer.erased');
        $this->hookEmails = [];
        Hook::action('customer.erased', function ($customer): void {
            $this->hookEmails[] = $customer->email;
        });
    }

    protected function tearDown(): void
    {
        app(HookRegistry::class)->clear('customer.erased');
        parent::tearDown();
    }

    /** an account with an address, a cart, a note, a pending email change, a delivered order, and a block on the phone; returns the order id */
    private function person(string $email = 'me@example.com', bool $openOrder = false, bool $block = false): int
    {
        $account = $this->insertAccount($email);
        DB::table('addresses')->insert(['account_id' => $account, 'delivery_type' => 'street_address', 'recipient_first_name' => 'Mem', 'recipient_last_name' => 'Ber', 'phone' => '+359888111111', 'country' => 'BG', 'city' => 'X', 'postal_code' => '1', 'address_line_1' => 'Y', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('carts')->insert(['account_id' => $account, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_notes')->insert(['email_key' => $email, 'body' => 'VIP', 'created_at' => now(), 'updated_at' => now()]);
        $other = $this->insertAccount('other@example.com');
        DB::table('account_email_changes')->insert(['account_id' => $other, 'new_email' => $email, 'token_hash' => str_repeat('c', 64), 'expires_at' => now()->addDay(), 'created_at' => now()]);
        $id = $this->insertOrder(['email' => $email, 'phone' => '+359888000000', 'status' => 'delivered', 'account_id' => $account]);
        $openOrder && $this->insertOrder(['email' => $email, 'phone' => '+359888000000', 'status' => 'confirmed']);
        $block && app(OrderBlockService::class)->block(\App\Enums\OrderBlockType::PHONE, '+359888000000', 90, 'chargeback');

        return $id;
    }

    private function page(int $orderId): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(CustomerPage::class, ['key' => 'o-'.$orderId]);
    }

    private function hash(string $email = 'me@example.com'): string
    {
        return (string) app(PrivacyHash::class)->email($email);
    }

    /** @return list<array<string, mixed>> every order row without the account link the database sets to NULL */
    private function orderRows(): array
    {
        return DB::table('orders')->orderBy('id')->get()->map(function ($row): array {
            $row = (array) $row;
            unset($row['account_id']);

            return $row;
        })->all();
    }

    /** the body of the dialog, rendered exactly as the action's modalContent does (the reader's summary into the view) */
    private function dialog(int $orderId): string
    {
        $reader = app(\App\Customers\CustomerPageReader::class);
        $who = \App\Customers\CustomerIdentity::resolve('o-'.$orderId);

        return view('filament.customers.forget-summary', ['s' => $reader->forgetSummary($who, $reader->read($who))])->render();
    }

    /** the part of a lang text before its first :placeholder, to look for in the dialog */
    private function lead(string $key, string $placeholder = 'x'): string
    {
        return trim(Str::before(__($key, [$placeholder => '@@']), '@@'));
    }

    // ---- the permission, called directly -------------------------------------------------------------------------------------------

    /** ★ without PRIVACY_MANAGE the action called directly erases nothing */
    public function test_forget_without_privacy_manage_is_refused_on_the_server(): void
    {
        $id = $this->person();
        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW, Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);
        Queue::fake();

        try {
            $this->page($id)->call('mountAction', 'forget')->set('mountedActions.0.data.reason', 'Customer asked')->call('callMountedAction');
        } catch (\Throwable) {
            // refused (403)
        }

        $this->assertSame(2, DB::table('accounts')->count(), 'both accounts are still there');
        $this->assertSame(1, DB::table('customer_notes')->count());
        $this->assertSame(0, PrivacyRequestModel::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame([], $this->hookEmails);
    }

    public function test_the_button_is_only_in_the_header_of_a_user_with_privacy_manage(): void
    {
        $id = $this->person();

        $this->actingAsStaff('Administrator');
        $this->assertStringContainsString(__('privacy_admin.forget.action'), (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent());

        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);
        $this->assertStringNotContainsString('mountAction(&#039;forget&#039;', (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent());
    }

    // ---- the reason ----------------------------------------------------------------------------------------------------------------

    /** ★ an empty, whitespace-only, too short, over-long, control-character or bidi reason is refused and nothing is erased */
    public function test_a_bad_reason_is_refused_and_nothing_is_erased(): void
    {
        $id = $this->person();
        $this->actingAsStaff('Administrator');
        Queue::fake();

        foreach (['', '   ', "\n\t ", 'ab', str_repeat('x', 501), "bell\x07here", "bidi \u{202E}override", "zero\u{200B}width"] as $reason) {
            try {
                $this->page($id)->callAction('forget', ['reason' => $reason])->assertHasActionErrors(['reason']);
            } catch (\Throwable $e) {
                $this->fail('reason '.json_encode($reason).': '.$e->getMessage());
            }
        }

        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertSame(0, PrivacyRequestModel::query()->count());
        $this->assertSame([], $this->hookEmails);
        Queue::assertNothingPushed();
    }

    public function test_the_reason_is_kept_as_one_clean_line_between_3_and_500_characters(): void
    {
        $id = $this->person();
        $this->actingAsStaff('Administrator');
        Queue::fake();

        $this->page($id)->callAction('forget', ['reason' => "  Asked by email\n  on Monday  "])->assertHasNoActionErrors();

        $this->assertSame('Asked by email on Monday', PrivacyRequestModel::query()->sole()->reason);

        // the limits themselves: 3 and 500 characters are fine
        $this->assertSame('abc', CustomerPage::cleanReason('abc'));
        $this->assertSame(500, mb_strlen((string) CustomerPage::cleanReason(str_repeat('я', 500))));
        $this->assertNull(CustomerPage::cleanReason('ab'));
        $this->assertNull(CustomerPage::cleanReason(str_repeat('я', 501)));
    }

    // ---- the dialog ----------------------------------------------------------------------------------------------------------------

    public function test_the_dialog_shows_what_goes_and_what_stays_and_the_conditional_lines_only_when_they_apply(): void
    {
        $id = $this->person();
        $this->actingAsStaff('Administrator');
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('privacy.order_retention_years', '7');

        $this->page($id)->mountAction('forget')->assertActionMounted('forget');
        $quiet = $this->dialog($id);

        $this->assertStringContainsString(e(__('privacy_admin.forget.lead')), $quiet);
        $this->assertStringContainsString(__('privacy_admin.forget.admin_only'), $quiet);
        $this->assertStringContainsString(__('privacy_admin.forget.delete_text'), $quiet);
        $this->assertStringContainsString(trans_choice('privacy_admin.forget.years', 7, ['count' => 7]), $quiet, 'the retention setting');
        $this->assertStringNotContainsString($this->lead('privacy_admin.forget.open_orders', 'orders'), $quiet, 'no order in progress');
        $this->assertStringNotContainsString($this->lead('privacy_admin.forget.stay_block', 'date'), $quiet, 'no active block');
        $this->assertStringNotContainsString($this->lead('privacy_admin.forget.already', 'date'), $quiet, 'not forgotten before');
        $this->assertStringNotContainsString($this->lead('privacy_admin.forget.linked', 'date'), $quiet, 'no confirmed request');
    }

    public function test_the_open_order_warning_lists_the_orders_with_links_and_cuts_at_five(): void
    {
        $id = $this->person();
        $open = [];

        for ($i = 0; $i < 7; $i++) {
            $open[] = $this->insertOrder(['email' => 'me@example.com', 'status' => ['placed', 'confirmed', 'shipped'][$i % 3]]);
        }

        $this->insertOrder(['email' => 'me@example.com', 'status' => 'cancelled']); // finished: never a warning
        $this->actingAsStaff('Administrator');

        $html = $this->dialog($id);

        $this->assertStringContainsString($this->lead('privacy_admin.forget.open_orders', 'orders'), $html);
        $this->assertStringContainsString('/admin/orders/'.$open[0], $html);
        $this->assertStringContainsString('/admin/orders/'.$open[4], $html);
        $this->assertStringNotContainsString('/admin/orders/'.$open[5].'"', $html, 'at most five');
        $this->assertStringContainsString(__('privacy_admin.forget.open_orders_more', ['count' => 2]), $html);
    }

    public function test_the_block_line_the_forgotten_line_and_the_linked_line_each_appear_only_with_their_fact(): void
    {
        $id = $this->person(block: true);
        $this->actingAsStaff('Administrator');
        DB::table('privacy_requests')->insert(['kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email' => null, 'email_hash' => $this->hash(), 'reason' => 'earlier', 'completed_at' => now()->subDays(40), 'created_at' => now()->subDays(40), 'updated_at' => now()]);
        DB::table('privacy_requests')->insert(['kind' => 'erase', 'source' => 'admin', 'status' => 'confirmed', 'email' => 'me@example.com', 'email_hash' => $this->hash(), 'confirmed_at' => now()->subDay(), 'created_at' => now()->subDay(), 'updated_at' => now()]);

        $html = $this->dialog($id);

        $this->assertStringContainsString(__('privacy_admin.forget.stay_block', ['date' => now()->addDays(90)->format('d.m.Y')]), $html);
        $this->assertStringContainsString(__('privacy_admin.forget.already', ['date' => now()->subDays(40)->format('d.m.Y')]), $html);
        $this->assertStringContainsString(__('privacy_admin.forget.linked', ['date' => now()->subDay()->format('d.m.Y')]), $html);

        // a permanent block reads «безсрочно»
        OrderBlockModelHelper::makePermanent();
        $forever = $this->dialog($id);
        $this->assertStringContainsString(__('privacy_admin.forget.stay_block_forever'), $forever);
    }

    // ---- the full effect -----------------------------------------------------------------------------------------------------------

    public function test_the_full_effect_through_the_page(): void
    {
        $id = $this->person(block: true);
        $staff = $this->actingAsStaff('Administrator');
        Queue::fake();
        $ordersBefore = $this->orderRows();

        $this->page($id)->callAction('forget', ['reason' => 'Customer asked by email'])->assertNotified(__('privacy_admin.forget.done'));

        $this->assertSame(0, DB::table('accounts')->where('email', 'me@example.com')->count(), 'the account is gone');
        $this->assertSame(0, DB::table('addresses')->count());
        $this->assertSame(0, DB::table('carts')->count());
        $this->assertSame(0, DB::table('customer_notes')->count());
        $this->assertSame(0, DB::table('account_email_changes')->count(), 'the pending email change');
        $this->assertSame(1, DB::table('privacy_suppressions')->where('kind', 'email')->count());
        $this->assertSame(2, DB::table('privacy_suppressions')->where('kind', 'phone')->count(), 'the address phone and the order phone');
        $this->assertSame(1, \App\Models\OrderBlockModel::query()->active(now())->count(), 'the COD block stays (D7)');
        $this->assertSame($ordersBefore, $this->orderRows(), 'the orders are untouched (the database only unlinks account_id)');
        $this->assertSame(['me@example.com'], $this->hookEmails, 'customer.erased fired once');
        Queue::assertPushed(SendAccountMailJob::class, fn (SendAccountMailJob $job): bool => $job->templateKey === 'privacy.erased' && $job->email === 'me@example.com');

        $row = PrivacyRequestModel::query()->sole();
        $this->assertSame('done', $row->status);
        $this->assertSame('erase', $row->kind);
        $this->assertSame('admin', $row->source);
        $this->assertSame('Customer asked by email', $row->reason);
        $this->assertSame((int) $staff->getKey(), (int) $row->staff_id);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $row->clear_until->getTimestamp(), 5);
        $this->assertTrue($row->actions['account_deleted']);
        $this->assertSame(1, $row->actions['addresses_deleted']);
        $this->assertArrayHasKey('cod_blocks_kept', $row->actions);
    }

    public function test_after_the_erase_the_page_stays_and_shows_no_account_and_no_note(): void
    {
        $id = $this->person();
        $this->actingAsStaff('Administrator');
        Queue::fake();

        $page = $this->page($id)->callAction('forget', ['reason' => 'Customer asked'])->assertNoRedirect();

        $page->assertSee(__('customers.badges.guest'))->assertSee(__('customers.addresses.none'));
        $this->assertSame('', $page->get('noteBody'));
        $this->get('/admin/customers/o-'.$id)->assertOk();
    }

    public function test_an_account_only_person_goes_back_to_the_list(): void
    {
        $account = $this->insertAccount('lonely@example.com');
        $this->actingAsStaff('Administrator');
        Queue::fake();

        Livewire::test(CustomerPage::class, ['key' => 'a-'.$account])->callAction('forget', ['reason' => 'Customer asked'])->assertRedirect(Customers::getUrl());

        $this->assertSame(0, DB::table('accounts')->where('email', 'lonely@example.com')->count());
    }

    public function test_the_list_shows_the_forgotten_badge_for_a_person_erased_through_the_page(): void
    {
        $id = $this->person();
        $this->insertOrder(['email' => 'kept@example.com']);
        $this->actingAsStaff('Administrator');
        Queue::fake();

        $this->page($id)->callAction('forget', ['reason' => 'Customer asked']);

        $html = Livewire::test(Customers::class)->loadTable()->html();
        $this->assertSame(1, substr_count($html, __('customers.badges.forgotten')), 'one «Забравен»');
    }
}

/** the one place this file makes the existing block permanent */
final class OrderBlockModelHelper
{
    public static function makePermanent(): void
    {
        \App\Models\OrderBlockModel::query()->update(['expires_at' => null]);
    }
}
