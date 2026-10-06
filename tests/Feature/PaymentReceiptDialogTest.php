<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\Resources\OrderResource\PaymentReceiptDialog;
use App\Services\Exceptions\PaymentReceiptRefusedException;
use App\Services\PaymentReceiptRecorder;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Features\SupportTesting\Testable;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * Refunds R4a-4 (shipping-domain-design.md §7.2.20 §6, §7, §10): the three dialogs of bank-transfer receipts
 * — record, accept, correct — and the order page's "bank transfers received" block. The form decides nothing:
 * every refusal below is the SERVICE's, shown on the field it belongs to or as a notice.
 * 100.00 EUR is expected; the order was placed 2026-09-28 09:00 UTC; the staff member is an Administrator.
 */
class PaymentReceiptDialogTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function page(array $order): Testable
    {
        // OrderAdminReader is scoped and memoises per instance: a page opened after a write must read afresh.
        $this->app->forgetScopedInstances();

        return Livewire::test(ViewOrder::class, ['record' => $order['orderId']]);
    }

    private function recorder(): PaymentReceiptRecorder
    {
        return app(PaymentReceiptRecorder::class);
    }

    private function receive(array $order, int $minor, string $reference = 'BG-REF-1', string $day = '2026-09-28'): void
    {
        $this->recorder()->record((string) $order['payment']->id(), $this->eur($minor), $day, $reference, new \DateTimeImmutable(), null);
    }

    /** @return array<string, mixed> a valid record-dialog submission */
    private function submission(string $amount, string $reference = 'BG-REF-1', ?string $day = null, ?string $key = null): array
    {
        return [
            'amount' => $amount,
            'received_on' => $day ?? app(\App\Settings\StoreTimezone::class)->today(),
            'bank_reference' => $reference,
            'operation_key' => $key ?? (string) Str::uuid(),
        ];
    }

    private function actingAsRole(string $roleName): StaffModel
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            $this->seed(StaffSystemRolesSeeder::class);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create('dlg-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** @param list<\EasyCo\Staff\Enums\Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffModel
    {
        $role = \EasyCo\Staff\Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffModel::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function counts(array $order): array
    {
        return [
            DB::table('payment_receipts')->count(),
            DB::table('order_events')->where('order_id', $order['orderId'])->count(),
            (array) $this->paymentRow($order['payment']),
        ];
    }

    // =====================================================================================================
    // The record dialog
    // =====================================================================================================

    public function test_the_record_dialog_prefills_what_is_still_expected(): void
    {
        $order = $this->bankOrder();

        $this->page($order)->mountAction('mark_as_received')->assertActionDataSet(['amount' => '100.00']);

        $this->receive($order, 6000);
        $this->page($order)->mountAction('mark_as_received')->assertActionDataSet(['amount' => '40.00']);

        $this->receive($order, 5000, 'OVER');
        $this->page($order)->mountAction('mark_as_received')->assertActionDataSet(['amount' => null]);
    }

    public function test_the_day_defaults_to_today_in_the_store_timezone_and_the_limits_are_store_days(): void
    {
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
        $order = $this->bankOrder();
        // Placed 2026-09-27 21:30 UTC = 2026-09-28 00:30 in Sofia: the placement DAY is the 28th, not the UTC 27th.
        DB::table('orders')->where('id', $order['orderId'])->update(['placed_at' => '2026-09-27 21:30:00']);
        $today = now('Europe/Sofia')->format('Y-m-d');

        $page = $this->page($order)->mountAction('mark_as_received');
        $page->assertActionDataSet(['received_on' => $today]);

        // The picker's limits are in its own markup: the lower one is the placement DAY in Sofia (the 28th, not the UTC 27th),
        // the upper one is today in Sofia.
        $modal = preg_replace('/\s+/', ' ', $page->getMountedActionModalHtml());
        $this->assertMatchesRegularExpression('/min[^<>]{0,40}2026-09-28/i', $modal);
        $this->assertDoesNotMatchRegularExpression('/min[^<>]{0,40}2026-09-27/i', $modal);
        $this->assertMatchesRegularExpression('/max[^<>]{0,40}'.$today.'/i', $modal);
        $this->assertSame($today, app(\App\Settings\StoreTimezone::class)->today());
    }

    public function test_an_exact_transfer_settles_and_the_payment_block_shows_it(): void
    {
        $order = $this->bankOrder();

        $this->page($order)
            ->callAction('mark_as_received', data: $this->submission('100.00', 'BG-EXACT'))
            ->assertHasNoActionErrors()
            ->assertNotified(__('orders.receipt.record.done'));

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(1, DB::table('payment_receipts')->count());

        $this->page($order)
            ->assertSee(__('orders.receipt.section.heading'))
            ->assertSee('BG-EXACT')
            ->assertSee('100.00 EUR')
            ->assertSee('Test Administrator');
    }

    public function test_a_short_transfer_is_recorded_does_not_settle_and_shows_the_neutral_difference_line(): void
    {
        $order = $this->bankOrder();

        $this->page($order)->callAction('mark_as_received', data: $this->submission('90.00', 'BG-SHORT'))->assertHasNoActionErrors();

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(1, DB::table('payment_receipts')->count());

        $this->page($order)
            ->assertSee('BG-SHORT')
            ->assertSee(__('orders.receipt.section.short_by', ['difference' => '10.00 EUR']))
            ->assertDontSee(__('orders.receipt.section.over_by', ['difference' => '10.00 EUR']));
    }

    public function test_an_over_transfer_is_recorded_does_not_settle_and_shows_the_neutral_difference_line(): void
    {
        $order = $this->bankOrder();

        $this->page($order)->callAction('mark_as_received', data: $this->submission('110.00', 'BG-OVER'))->assertHasNoActionErrors();

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
        $this->page($order)->assertSee(__('orders.receipt.section.over_by', ['difference' => '10.00 EUR']));
    }

    public function test_the_live_hint_says_matches_short_or_over_and_is_empty_for_an_unreadable_amount(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 6000);

        $page = $this->page($order)->mountAction('mark_as_received');

        $matches = __('orders.receipt.record.hint', ['expected' => '100.00 EUR', 'recorded' => '60.00 EUR', 'this' => '40.00 EUR']).' '.__('orders.receipt.record.hint_matches');
        $short = __('orders.receipt.record.hint', ['expected' => '100.00 EUR', 'recorded' => '60.00 EUR', 'this' => '30.00 EUR']).' '.__('orders.receipt.record.hint_short', ['difference' => '10.00 EUR']);
        $over = __('orders.receipt.record.hint', ['expected' => '100.00 EUR', 'recorded' => '60.00 EUR', 'this' => '50.00 EUR']).' '.__('orders.receipt.record.hint_over', ['difference' => '10.00 EUR']);

        $page->setActionData(['amount' => '40.00'])->assertMountedActionModalSee($matches);
        $page->setActionData(['amount' => '30.00'])->assertMountedActionModalSee($short);
        $page->setActionData(['amount' => '50,00'])->assertMountedActionModalSee($over);

        foreach (['abc', '', '1000000000.00', '-5'] as $unreadable) {
            $page->setActionData(['amount' => $unreadable])
                ->assertMountedActionModalDontSee('100.00 EUR · ')
                ->assertMountedActionModalDontSee(__('orders.receipt.record.hint_matches'));
        }
    }

    public function test_the_live_hint_is_in_bulgarian_in_bg(): void
    {
        $order = $this->bankOrder();
        App::setLocale('bg');

        $this->page($order)->mountAction('mark_as_received')
            ->setActionData(['amount' => '100.00'])
            ->assertMountedActionModalSee('съвпада, плащането ще бъде уредено');
    }

    public function test_a_future_day_and_a_day_before_the_placement_day_land_on_the_day_field_and_write_nothing(): void
    {
        $order = $this->bankOrder();
        $before = $this->counts($order);

        $this->page($order)
            ->callAction('mark_as_received', data: $this->submission('100.00', day: now()->addDays(2)->format('Y-m-d')))
            ->assertHasActionErrors(['received_on']);
        $this->page($order)
            ->callAction('mark_as_received', data: $this->submission('100.00', day: '2026-09-27'))
            ->assertHasActionErrors(['received_on']);

        $this->assertSame($before, $this->counts($order));
    }

    public function test_a_blank_and_a_non_ascii_blank_and_a_65_character_reference_land_on_the_reference_field(): void
    {
        $order = $this->bankOrder();
        $before = $this->counts($order);

        foreach (['', "\u{00A0}\u{00A0}", str_repeat('A', 65)] as $bad) {
            $this->page($order)
                ->callAction('mark_as_received', data: $this->submission('100.00', $bad))
                ->assertHasActionErrors(['bank_reference']);
        }

        $this->assertSame($before, $this->counts($order));
    }

    public function test_a_10_integer_digit_and_a_30_digit_and_an_unreadable_amount_land_on_the_amount_field(): void
    {
        $order = $this->bankOrder();
        $before = $this->counts($order);

        foreach (['1000000000.00', str_repeat('9', 30), 'abc', '12.345', '-5'] as $bad) {
            $this->page($order)
                ->callAction('mark_as_received', data: $this->submission($bad))
                ->assertHasActionErrors(['amount']);
        }

        $this->assertSame($before, $this->counts($order));
    }

    public function test_a_service_refusal_with_a_field_lands_on_that_field_and_one_without_is_a_notice(): void
    {
        // The mapping the dialog applies to every refusal of the service.
        $map = [
            PaymentReceiptRefusedException::AMOUNT_TOO_LARGE => 'amount',
            PaymentReceiptRefusedException::AMOUNT_NOT_POSITIVE => 'amount',
            PaymentReceiptRefusedException::DAY_IN_FUTURE => 'received_on',
            PaymentReceiptRefusedException::DAY_BEFORE_PLACEMENT => 'received_on',
            PaymentReceiptRefusedException::DAY_MALFORMED => 'received_on',
            PaymentReceiptRefusedException::REFERENCE_BLANK => 'bank_reference',
            PaymentReceiptRefusedException::REFERENCE_TOO_LONG => 'bank_reference',
            PaymentReceiptRefusedException::REFERENCE_INVALID => 'bank_reference',
            PaymentReceiptRefusedException::REASON_BLANK => 'reason',
            PaymentReceiptRefusedException::REASON_TOO_LONG => 'reason',
            PaymentReceiptRefusedException::REASON_INVALID => 'reason',
            // A notice, not a field: the currency is the box's own suffix, and the accepted figure is a hidden field.
            PaymentReceiptRefusedException::CURRENCY_MISMATCH => null,
            PaymentReceiptRefusedException::ACCEPTED_AMOUNT_CHANGED => null,
            PaymentReceiptRefusedException::PAYMENT_SETTLED => null,
            PaymentReceiptRefusedException::NOTHING_TO_CORRECT => null,
            PaymentReceiptRefusedException::TOO_MANY_RECEIPT_ROWS => null,
        ];

        foreach ($map as $reason => $field) {
            $this->assertSame($field, PaymentReceiptDialog::fieldFor(new PaymentReceiptRefusedException($reason)), $reason);
        }
    }

    public function test_a_service_refusal_without_a_field_is_a_translated_notice_and_never_a_500(): void
    {
        $order = $this->bankOrder();
        // The payment is settled behind the dialog's back (another operator): the service refuses by name.
        $page = $this->page($order)->mountAction('mark_as_received')->setActionData($this->submission('100.00'));
        $this->recorder()->record((string) $order['payment']->id(), $this->eur(10000), '2026-09-28', 'OTHER', new \DateTimeImmutable());
        $before = $this->counts($order);

        $page->callMountedAction()->assertNotified(__('orders.actions.refused_title'));

        $this->assertSame($before, $this->counts($order));
    }

    public function test_a_double_submit_records_once(): void
    {
        $order = $this->bankOrder();
        $data = $this->submission('90.00', 'BG-ONCE', key: 'dialog-key-1');

        $this->page($order)->callAction('mark_as_received', data: $data)->assertHasNoActionErrors();
        $this->page($order)->callAction('mark_as_received', data: $data)->assertHasNoActionErrors();

        $this->assertSame(1, DB::table('payment_receipts')->count());
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order['orderId'])->where('type', 'payment_receipt_recorded')->count());
    }

    public function test_the_same_key_with_other_contents_is_a_translated_notice(): void
    {
        $order = $this->bankOrder();
        $this->page($order)->callAction('mark_as_received', data: $this->submission('90.00', 'ONE', key: 'dialog-key-2'));

        $this->page($order)
            ->callAction('mark_as_received', data: $this->submission('80.00', 'ONE', key: 'dialog-key-2'))
            ->assertNotified(__('orders.actions.refused_title'));

        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_cash_on_delivery_keeps_the_one_click_confirm_and_shows_no_receipts_block(): void
    {
        $order = $this->bankOrder(method: 'cash_on_delivery');

        $page = $this->page($order);
        $page->assertActionVisible('mark_as_received')->assertDontSee(__('orders.receipt.section.heading'));

        $page->callAction('mark_as_received')->assertNotified(__('orders.actions.mark_as_received_done'));

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(0, DB::table('payment_receipts')->count());
    }

    public function test_a_settled_bank_payment_has_no_record_dialog(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 10000);

        $this->page($order)->assertActionHidden('mark_as_received');
    }

    public function test_the_record_dialog_needs_order_manage(): void
    {
        $order = $this->bankOrder();
        $this->actingAsCustomRole([\EasyCo\Staff\Enums\Permission::ORDER_VIEW]);

        $this->page($order)->assertActionHidden('mark_as_received');
    }

    // =====================================================================================================
    // Accept the received amount
    // =====================================================================================================

    public function test_accept_is_hidden_without_payment_reconcile_and_on_an_exact_or_an_empty_payment(): void
    {
        $short = $this->bankOrder();
        $this->receive($short, 9000);
        $empty = $this->bankOrder();
        $exact = $this->bankOrder();
        $this->receive($exact, 10000);

        $this->page($empty)->assertActionHidden('accept_mismatch');
        $this->page($exact)->assertActionHidden('accept_mismatch');

        $this->actingAsRole('Manager');
        $this->page($short)->assertActionHidden('accept_mismatch');
    }

    public function test_accept_is_visible_for_an_administrator_on_a_short_and_on_an_over_transfer(): void
    {
        $short = $this->bankOrder();
        $this->receive($short, 9000);
        $over = $this->bankOrder();
        $this->receive($over, 11000);

        $this->page($short)->assertActionVisible('accept_mismatch');
        $this->page($over)->assertActionVisible('accept_mismatch');
    }

    public function test_accept_shows_expected_received_the_difference_the_warning_and_the_help(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $this->page($order)->mountAction('accept_mismatch')
            ->assertMountedActionModalSee(__('orders.receipt.accept.expected', ['amount' => '100.00 EUR']))
            ->assertMountedActionModalSee(__('orders.receipt.accept.received', ['amount' => '90.00 EUR']))
            ->assertMountedActionModalSee(__('orders.receipt.accept.short', ['difference' => '10.00 EUR']))
            ->assertMountedActionModalSee(__('orders.receipt.accept.warning', ['amount' => '90.00 EUR']))
            ->assertMountedActionModalSee(__('orders.receipt.accept.help'))
            ->assertActionDataSet(['accepted_amount' => '90.00']);
    }

    public function test_the_help_text_is_the_agreed_bulgarian_sentence_in_bg(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 11000);
        App::setLocale('bg');

        $this->page($order)->mountAction('accept_mismatch')
            ->assertMountedActionModalSee('Приемането на по-малък или по-голям превод е и начинът да откажете или върнете поръчка със несверена сума; можете да го посочите в причината, за да се чете правилно историята.')
            ->assertMountedActionModalSee(__('orders.receipt.accept.over', ['difference' => '10.00 EUR']));
    }

    public function test_accept_settles_for_exactly_the_shown_sum(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $this->page($order)
            ->mountAction('accept_mismatch')
            ->setActionData(['reason' => 'agreed by phone'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('orders.receipt.accept.done'));

        $row = $this->paymentRow($order['payment']);
        $this->assertSame(9000, (int) $row->settled_amount_minor);
        $this->assertSame('agreed by phone', $row->settlement_reason);
        $this->assertSame(10000, (int) $row->amount_minor);
        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
    }

    public function test_an_accepted_overpayment_settles_for_110(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 11000);

        $this->page($order)->mountAction('accept_mismatch')->setActionData(['reason' => 'surplus'])->callMountedAction()->assertHasNoActionErrors();

        $this->assertSame(11000, (int) $this->paymentRow($order['payment'])->settled_amount_minor);
    }

    public function test_accepted_amount_changed_after_a_receipt_arrives_while_the_dialog_is_open_is_a_translated_notice_and_writes_nothing(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $page = $this->page($order)->mountAction('accept_mismatch')->setActionData(['reason' => 'agreed']);
        $this->receive($order, 500, 'ARRIVED-LATER');
        $before = $this->counts($order);

        $page->callMountedAction()->assertNotified(__('orders.actions.refused_title'));

        $this->assertSame($before, $this->counts($order));
        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
    }

    public function test_the_accept_reason_is_mandatory_and_limited_to_255_characters(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $this->page($order)->callAction('accept_mismatch', data: ['reason' => '', 'accepted_amount' => '90.00'])->assertHasActionErrors(['reason' => 'required']);
        $this->page($order)->callAction('accept_mismatch', data: ['reason' => str_repeat('x', 256), 'accepted_amount' => '90.00'])->assertHasActionErrors(['reason']);
        // The service's own cleaning: invisible characters and line breaks land on the same field.
        $this->page($order)->callAction('accept_mismatch', data: ['reason' => "two\nlines", 'accepted_amount' => '90.00'])->assertHasActionErrors(['reason']);
        $this->page($order)->callAction('accept_mismatch', data: ['reason' => "\u{00A0}", 'accepted_amount' => '90.00'])->assertHasActionErrors(['reason']);

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());

        $this->page($order)->callAction('accept_mismatch', data: ['reason' => str_repeat('x', 255), 'accepted_amount' => '90.00'])->assertHasNoActionErrors();
        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
    }

    public function test_a_tampered_accepted_amount_is_refused_by_the_service_not_trusted(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);

        $this->page($order)->callAction('accept_mismatch', data: ['reason' => 'agreed', 'accepted_amount' => '10.00'])->assertNotified(__('orders.actions.refused_title'));
        $this->page($order)->callAction('accept_mismatch', data: ['reason' => 'agreed', 'accepted_amount' => 'abc'])->assertNotified(__('orders.actions.refused_title'));

        $this->assertFalse($this->freshPayment($order['payment'])->isSettled());
    }

    public function test_after_an_accepted_underpayment_the_cancel_dialog_offers_90_and_shows_its_over_total_warning(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $this->page($order)->mountAction('accept_mismatch')->setActionData(['reason' => 'agreed'])->callMountedAction();

        $this->page($order)->mountAction('cancel')
            ->assertMountedActionModalSee(__('orders.refund_dialog.still_refundable', ['room' => '90.00 EUR']))
            ->assertMountedActionModalSee(__('orders.refund_dialog.over_total', ['room' => '90.00 EUR']));
    }

    // =====================================================================================================
    // Correct a receipt
    // =====================================================================================================

    public function test_correct_is_visible_only_with_an_effective_receipt_and_order_manage(): void
    {
        $order = $this->bankOrder();
        $this->page($order)->assertActionHidden('correct_receipt');

        $this->receive($order, 9000);
        $this->page($order)->assertActionVisible('correct_receipt');

        $this->actingAsCustomRole([\EasyCo\Staff\Enums\Permission::ORDER_VIEW]);
        $this->page($order)->assertActionHidden('correct_receipt');
    }

    public function test_the_receipt_select_lists_only_effective_receipts_newest_first(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 3000, 'REF-A');
        $this->receive($order, 2000, 'REF-B');
        $b = app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id())[1];
        $this->recorder()->correct((string) $b->id(), $this->eur(2500), '2026-09-28', 'REF-B2', 'it was 25', new \DateTimeImmutable());

        $html = preg_replace('/\s+/', ' ', $this->page($order)->mountAction('correct_receipt')->getMountedActionModalHtml());

        $this->assertStringContainsString('REF-A </option>', $html);
        $this->assertStringContainsString('REF-B2 </option>', $html);
        $this->assertStringNotContainsString('REF-B </option>', $html, 'the superseded receipt is not offered');
        $this->assertLessThan(strpos($html, 'REF-A </option>'), strpos($html, 'REF-B2 </option>'), 'newest first');
    }

    public function test_before_settlement_the_amount_may_change_and_a_correction_that_makes_the_sum_equal_settles_the_payment(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'REF-TYPO');
        $receipt = app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id())[0];

        $this->page($order)
            ->mountAction('correct_receipt')
            ->assertActionDataSet(['receipt_id' => (string) $receipt->id(), 'amount' => '90.00', 'bank_reference' => 'REF-TYPO'])
            ->setActionData(['amount' => '100.00', 'reason' => 'typed 90 instead of 100'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('orders.receipt.correct.done'));

        $this->assertTrue($this->freshPayment($order['payment'])->isSettled());
        $this->assertSame(['payment_receipt_recorded', 'payment_receipt_corrected', 'payment_confirmed'], DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->pluck('type')->all());
    }

    public function test_after_settlement_the_amount_box_says_it_is_locked_and_the_day_and_the_reference_can_be_corrected(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 10000, 'REF-TYPO');
        $paymentRow = (array) $this->paymentRow($order['payment']);

        $page = $this->page($order)->mountAction('correct_receipt');
        $modal = $page->getMountedActionModalHtml();
        $this->assertStringContainsString(e(__('orders.receipt.correct.amount_locked')), $modal);
        $this->assertStringContainsString('readonly', $modal);

        $page->setActionData(['bank_reference' => 'REF-FIXED', 'reason' => 'typo in the reference'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('orders.receipt.correct.done'));

        $this->assertSame($paymentRow, (array) $this->paymentRow($order['payment']), 'nothing money-wise changed');
        $this->assertSame(['payment_receipt_recorded', 'payment_confirmed', 'payment_receipt_corrected'], DB::table('order_events')->where('order_id', $order['orderId'])->orderBy('id')->pluck('type')->all());
        $this->assertSame('REF-FIXED', app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id())[0]->bankReference());
    }

    public function test_a_tampered_amount_after_settlement_is_refused_by_name_on_the_amount_field(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 10000);

        $this->page($order)
            ->mountAction('correct_receipt')
            ->setActionData(['amount' => '90.00', 'reason' => 'tampering'])
            ->callMountedAction()
            ->assertHasActionErrors(['amount']);

        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_nothing_to_correct_and_too_many_rows_are_translated_notices(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'REF');

        $this->page($order)
            ->mountAction('correct_receipt')
            ->setActionData(['reason' => 'no change at all'])
            ->callMountedAction()
            ->assertNotified(__('orders.actions.refused_title'));

        $this->assertSame(1, DB::table('payment_receipts')->count());

        // A chain of 50 rows: the next correction is the 51st.
        $chain = $this->bankOrder();
        $previous = null;

        for ($i = 1; $i <= 50; $i++) {
            $previous = $this->appendReceipt($chain['payment'], 100, 'CHAIN-'.$i, supersedes: $previous);
        }

        $before = DB::table('payment_receipts')->count();
        $this->page($chain)
            ->mountAction('correct_receipt')
            ->setActionData(['bank_reference' => 'CHAIN-51', 'reason' => 'one too many'])
            ->callMountedAction()
            ->assertNotified(__('orders.actions.refused_title'));

        $this->assertSame($before, DB::table('payment_receipts')->count());
    }

    public function test_the_correction_reason_is_mandatory_and_limited_to_255_characters(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'REF');

        $this->page($order)->mountAction('correct_receipt')->setActionData(['amount' => '80.00', 'reason' => ''])->callMountedAction()->assertHasActionErrors(['reason' => 'required']);
        $this->page($order)->mountAction('correct_receipt')->setActionData(['amount' => '80.00', 'reason' => str_repeat('x', 256)])->callMountedAction()->assertHasActionErrors(['reason']);

        $this->assertSame(1, DB::table('payment_receipts')->count());
    }

    public function test_a_receipt_id_of_another_order_cannot_be_corrected_from_this_page(): void
    {
        $mine = $this->bankOrder();
        $this->receive($mine, 9000, 'MINE');
        $theirs = $this->bankOrder();
        $this->receive($theirs, 9000, 'THEIRS');
        $theirReceipt = app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $theirs['payment']->id())[0];

        $this->page($mine)
            ->mountAction('correct_receipt')
            ->setActionData(['receipt_id' => (string) $theirReceipt->id(), 'bank_reference' => 'HIJACKED', 'reason' => 'tampering'])
            ->callMountedAction()
            ->assertHasActionErrors(['receipt_id']); // refused at the form: the select only accepts this order's receipts (the handler checks again)

        $this->assertSame(2, DB::table('payment_receipts')->count(), 'the other order\'s receipt was not touched');
    }

    // =====================================================================================================
    // The order page
    // =====================================================================================================

    public function test_the_receipts_block_is_shown_for_a_bank_transfer_only(): void
    {
        $bank = $this->bankOrder();
        $cod = $this->bankOrder(method: 'cash_on_delivery');

        $this->page($bank)->assertSee(__('orders.receipt.section.heading'))->assertSee(__('orders.receipt.section.none_yet'));
        $this->page($cod)->assertDontSee(__('orders.receipt.section.heading'));
    }

    public function test_a_settled_bank_payment_without_receipts_shows_the_legacy_line(): void
    {
        $legacy = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'bank_transfer');

        Livewire::test(ViewOrder::class, ['record' => $legacy['orderId']])
            ->assertSee(__('orders.receipt.section.legacy'))
            ->assertDontSee(__('orders.receipt.section.short_by', ['difference' => '0.00 EUR']));
    }

    public function test_an_accepted_mismatch_shows_the_settled_of_expected_and_the_reason(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'BG-ACC');
        $this->page($order)->mountAction('accept_mismatch')->setActionData(['reason' => 'agreed by phone'])->callMountedAction();

        $this->page($order)->assertSee(__('orders.receipt.section.accepted', ['settled' => '90.00 EUR', 'expected' => '100.00 EUR', 'reason' => 'agreed by phone']));
    }

    public function test_the_receipts_list_shows_day_amount_reference_and_who_and_marks_no_severity(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'BG-LIST');

        $html = $this->page($order)->html();

        foreach (['2026-09-28', '90.00 EUR', 'BG-LIST', 'Test Administrator', __('orders.receipt.section.short_by', ['difference' => '10.00 EUR'])] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        foreach (['overdue', 'Overdue', 'просрочен'] as $severity) {
            $this->assertStringNotContainsString($severity, $html);
        }
    }

    public function test_the_history_shows_reason_and_actor_for_the_three_new_event_types(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000, 'BG-HIST');
        $receipt = app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $order['payment']->id())[0];
        $this->recorder()->correct((string) $receipt->id(), $this->eur(9000), '2026-09-28', 'BG-HIST-2', 'reference typo', new \DateTimeImmutable());
        $this->page($order)->mountAction('accept_mismatch')->setActionData(['reason' => 'accepted by phone'])->callMountedAction();

        $html = $this->page($order)->html();

        foreach (['BG-HIST', 'reference typo', 'accepted by phone', 'Test Administrator'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        foreach (['payment_receipt_recorded', 'payment_receipt_corrected', 'payment_mismatch_accepted'] as $type) {
            foreach (['en', 'bg'] as $locale) {
                $label = trans('orders.event_type_options.'.$type, [], $locale);
                $this->assertNotSame('orders.event_type_options.'.$type, $label, "{$type} has a {$locale} label");
            }

            $this->assertStringContainsString(__('orders.event_type_options.'.$type), $html);
        }
    }

    public function test_the_bank_page_gains_two_queries_at_most_and_they_do_not_grow_with_the_number_of_receipts(): void
    {
        $few = $this->bankOrder();
        $this->receive($few, 100, 'ONE');
        $many = $this->bankOrder();

        for ($i = 1; $i <= 15; $i++) {
            $this->appendReceipt($many['payment'], 100, 'R-'.$i);
        }

        $queries = function (array $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($order);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame($queries($few), $queries($many), 'the receipts read does not grow with the number of receipts');

        $receiptQueries = function (array $order): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->page($order);
            $count = count(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'payment_receipts')));
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame(2, $receiptQueries($many), 'the sum and the rows');

        $cod = $this->bankOrder(method: 'cash_on_delivery');
        $this->assertSame(0, $receiptQueries($cod), 'a cash-on-delivery page reads no receipts');
    }
}
