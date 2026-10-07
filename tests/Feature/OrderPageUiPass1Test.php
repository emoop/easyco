<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\OrderStatusChanger;
use App\Services\PaymentReceiptRecorder;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Persistence\Eloquent\StaffModel;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Features\SupportTesting\Testable;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * UI pass 1: clear dialog buttons on every order-page action, one "Actions" menu, one merchant-facing payment
 * state in the header. Labels and structure only: no dialog field, rule or behaviour changes.
 */
class OrderPageUiPass1Test extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    /** action => [bg submit, en submit]: the wording the owner asked for (the confirmation modals derive theirs from the action's name). */
    private const SUBMIT = [
        'confirm' => ['Потвърди поръчката', 'Confirm the order'],
        'ship' => ['Изпрати поръчката', 'Ship the order'],
        'deliver' => ['Маркирай като доставена', 'Mark as delivered'],
        'mark_as_received' => ['Запиши получаването', 'Record the receipt'],
        'accept_mismatch' => ['Приеми сумата', 'Accept the amount'],
        'correct_receipt' => ['Запази корекцията', 'Save the correction'],
        'record_return' => ['Запиши връщането', 'Record the return'],
        'refund_money_only' => ['Запиши връщането на сума', 'Record the money-only refund'],
        'cancel' => ['Откажи поръчката', 'Cancel the order'],
        'add_note' => ['Добави бележката', 'Add the note'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function page(array $order): Testable
    {
        $this->app->forgetScopedInstances();

        return Livewire::test(ViewOrder::class, ['record' => $order['orderId']]);
    }

    private function receive(array $order, int $minor, string $reference = 'BG-REF-1'): void
    {
        app(PaymentReceiptRecorder::class)->record((string) $order['payment']->id(), $this->eur($minor), '2026-09-28', $reference, new DateTimeImmutable());
    }

    private function actingAsCustomRole(array $permissions): void
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('ui-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('irrelevant'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffModel::find($staff->id()), 'staff');
    }

    /** @return array<string, \Filament\Actions\Action> */
    private function actionsByName(): array
    {
        $byName = [];

        foreach (OrderResource::orderActionList() as $action) {
            $byName[$action->getName()] = $action;
        }

        return $byName;
    }

    // =====================================================================================================
    // 1. Dialog labels
    // =====================================================================================================

    public function test_every_order_page_action_has_an_explicit_submit_label_and_the_close_label_in_en_and_bg(): void
    {
        foreach (['bg' => 0, 'en' => 1] as $locale => $column) {
            App::setLocale($locale);
            $close = $locale === 'bg' ? 'Затвори' : 'Close';
            $actions = $this->actionsByName();

            $this->assertEqualsCanonicalizing(array_keys(self::SUBMIT), array_keys($actions), 'every action of the page is covered');

            // Filament's own defaults, in this locale: what the buttons used to say.
            $defaults = array_map(
                'strval',
                [__('filament-actions::modal.actions.submit.label'), __('filament-actions::modal.actions.cancel.label'), __('filament-actions::modal.actions.confirm.label')],
            );

            foreach (self::SUBMIT as $name => $labels) {
                $action = $actions[$name];

                $this->assertSame($labels[$column], $action->getModalSubmitActionLabel(), "{$name} submit ({$locale})");
                $this->assertSame($close, $action->getModalCancelActionLabel(), "{$name} dismiss ({$locale})");
                $this->assertNotContains($action->getModalSubmitActionLabel(), $defaults, "{$name}: not a Filament default");
                $this->assertNotContains($action->getModalCancelActionLabel(), $defaults, "{$name}: not a Filament default");
            }
        }
    }

    public function test_the_dismiss_label_is_one_shared_key_and_never_cancel(): void
    {
        $this->assertSame('Close', trans('orders.modal.close', [], 'en'));
        $this->assertSame('Затвори', trans('orders.modal.close', [], 'bg'));

        foreach (['en', 'bg'] as $locale) {
            App::setLocale($locale);

            foreach ($this->actionsByName() as $name => $action) {
                $this->assertSame(__('orders.modal.close'), $action->getModalCancelActionLabel(), "{$name} ({$locale})");
            }
        }
    }

    /**
     * The three dialogs of this page that the header menu does NOT hold: §10 stage 4b-ii moved the edit button
     * into the items section's own header, and the two refund buttons sit on a refund's own row. They are the
     * other half of "every dialog on the order page has buttons that say what they do".
     */
    private const DIALOGS_OUTSIDE_THE_MENU = [
        'edit_order' => ['Запази редакцията', 'Save the edit'],
        'mark_refund_paid_out' => ['Запиши изплащането', 'Record the payout'],
        'cancel_refund' => ['Отмени възстановяването', 'Cancel the refund'],
    ];

    /** @return array<string, \Filament\Actions\Action> the three dialogs above, built exactly as the page builds them */
    private function dialogsOutsideTheMenu(string $refundId): array
    {
        return [
            'edit_order' => OrderResource::editAction(),
            'mark_refund_paid_out' => OrderResource::markRefundPaidOutAction($refundId, 'bank', 2000, 'EUR'),
            'cancel_refund' => OrderResource::cancelRefundAction($refundId, 'bank'),
        ];
    }

    /** A settled order with one OWED refund — the only state in which the two refund buttons exist. */
    private function owedRefund(string $method = 'bank_transfer'): array
    {
        $order = $this->refundableOrder([['quantity' => 4, 'unit' => 1000]], method: $method);
        app(OrderStatusChanger::class)->recordReturn($order['orderId'], [['originatingSaleLineId' => $order['saleLineIds'][0], 'quantityReturned' => 2, 'restock' => true]], $this->at());

        return ['order' => $order, 'refund' => $this->refundsOf($order['payment'])[0]];
    }

    /** One of a refund row's own two buttons, addressed as the page mounts it. */
    private function refundAction(string $name, string $refundId): TestAction
    {
        return TestAction::make($name)->schemaComponent('refund_actions_'.$refundId);
    }

    public function test_the_three_dialogs_outside_the_menu_have_explicit_buttons_too(): void
    {
        $refund = $this->owedRefund()['refund'];

        foreach (['bg' => 0, 'en' => 1] as $locale => $column) {
            App::setLocale($locale);
            $close = $locale === 'bg' ? 'Затвори' : 'Close';
            $dialogs = $this->dialogsOutsideTheMenu($refund->id());

            $this->assertEqualsCanonicalizing(array_keys(self::DIALOGS_OUTSIDE_THE_MENU), array_keys($dialogs), 'every dialog outside the menu is covered');

            // Filament's own defaults, in this locale: what those buttons used to say.
            $defaults = array_map(
                'strval',
                [__('filament-actions::modal.actions.submit.label'), __('filament-actions::modal.actions.cancel.label'), __('filament-actions::modal.actions.confirm.label')],
            );

            foreach (self::DIALOGS_OUTSIDE_THE_MENU as $name => $labels) {
                $action = $dialogs[$name];

                $this->assertSame($labels[$column], $action->getModalSubmitActionLabel(), "{$name} submit ({$locale})");
                $this->assertSame($close, $action->getModalCancelActionLabel(), "{$name} dismiss ({$locale})");
                $this->assertNotContains($action->getModalSubmitActionLabel(), $defaults, "{$name}: not a Filament default");
                $this->assertNotContains($action->getModalCancelActionLabel(), $defaults, "{$name}: not a Filament default");
            }
        }
    }

    public function test_a_refund_s_own_dialog_renders_those_buttons_on_the_page(): void
    {
        ['order' => $order, 'refund' => $refund] = $this->owedRefund();

        foreach (['bg' => 0, 'en' => 1] as $locale => $column) {
            App::setLocale($locale);
            $close = $locale === 'bg' ? 'Затвори' : 'Close';

            foreach (['mark_refund_paid_out', 'cancel_refund'] as $name) {
                $modal = preg_replace('/\s+/', ' ', $this->page($order)->mountAction($this->refundAction($name, $refund->id()))->getMountedActionModalHtml());

                $this->assertStringContainsString(self::DIALOGS_OUTSIDE_THE_MENU[$name][$column], $modal, "{$name} ({$locale})");
                $this->assertStringContainsString($close, $modal, "{$name} dismiss ({$locale})");
            }
        }
    }

    public function test_a_rendered_dialog_shows_the_new_buttons_and_not_the_bulgarian_defaults(): void
    {
        $order = $this->bankOrder();
        App::setLocale('bg');

        $modal = preg_replace('/\s+/', ' ', $this->page($order)->mountAction('mark_as_received')->getMountedActionModalHtml());

        $this->assertStringContainsString('Запиши получаването', $modal);
        $this->assertStringContainsString('Затвори', $modal);
        $this->assertDoesNotMatchRegularExpression('/>\s*Изпрати\s*</u', $modal);
        $this->assertDoesNotMatchRegularExpression('/>\s*Откажи\s*</u', $modal);

        $cancel = preg_replace('/\s+/', ' ', $this->page($order)->mountAction('cancel')->getMountedActionModalHtml());
        $this->assertStringContainsString('Откажи поръчката', $cancel);
        $this->assertStringContainsString('Затвори', $cancel);
        $this->assertDoesNotMatchRegularExpression('/>\s*Откажи\s*</u', $cancel);
    }

    public function test_the_record_dialog_description_and_the_live_hint_use_the_new_wording(): void
    {
        $order = $this->bankOrder();

        foreach (['en', 'bg'] as $locale) {
            App::setLocale($locale);

            $description = __('orders.receipt.record.description');
            $this->assertStringContainsString($locale === 'bg'
                ? 'Това само записва превода; не изпраща и не потвърждава поръчката.'
                : 'This only records the transfer; it does not ship or confirm the order.', $description);

            $ending = $locale === 'bg'
                ? 'плащането остава неуредено, докато не дойде остатъкът или не приемете получената сума'
                : 'the payment stays unsettled until the rest arrives or you accept the received amount';

            foreach (['hint_short', 'hint_over'] as $key) {
                $hint = __('orders.receipt.record.'.$key, ['difference' => '10.00 EUR']);
                $this->assertStringEndsWith($ending, $hint);
                $this->assertStringNotContainsString('Needs attention', $hint);
                $this->assertStringNotContainsString('Изискват внимание', $hint);
            }
        }

        App::setLocale('en');
        $this->page($order)->mountAction('mark_as_received')
            ->setActionData(['amount' => '90.00'])
            ->assertMountedActionModalSee('the payment stays unsettled until the rest arrives or you accept the received amount')
            ->assertMountedActionModalSee('This only records the transfer; it does not ship or confirm the order.');
    }

    // =====================================================================================================
    // 2. One "Actions" menu
    // =====================================================================================================

    /** @return array{0: list<\Filament\Actions\Action>, 1: ?ActionGroup, 2: list<list<string>>} top-level buttons, the menu, the visible names per section */
    private function header(Testable $page): array
    {
        $buttons = [];
        $menu = null;

        foreach ($page->instance()->getCachedHeaderActions() as $entry) {
            if ($entry instanceof ActionGroup) {
                $menu = $entry;
            } else {
                $buttons[] = $entry;
            }
        }

        $sections = [];

        foreach ($menu?->getActions() ?? [] as $section) {
            $sections[] = array_values(array_map(
                static fn ($action): string => $action->getName(),
                array_filter($section->getActions(), static fn ($action): bool => $action->isVisible()),
            ));
        }

        return [$buttons, $menu, $sections];
    }

    public function test_the_header_is_one_button_add_note_and_one_actions_menu_with_the_visible_actions_in_three_sections(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 7000);
        $page = $this->page($order);

        [$buttons, $menu, $sections] = $this->header($page);

        $this->assertSame(['add_note'], array_map(static fn ($button): string => $button->getName(), $buttons), 'the only top-level button');
        $this->assertNotNull($menu);
        $this->assertSame(__('orders.actions_menu'), $menu->getLabel());
        $this->assertSame('Actions', $menu->getLabel());
        $this->assertCount(3, $sections, 'the order flow, the payment, returns and cancellation');
        $this->assertSame([['confirm'], ['mark_as_received', 'accept_mismatch', 'correct_receipt'], ['cancel']], $sections);

        // The rendered menu: a trigger with the label, and one divided list per section that has an action.
        $html = $page->html();
        $this->assertStringContainsString('Actions', $html);
        $this->assertSame(3, preg_match_all('/class="fi-dropdown-list"/', $html), 'one list (divider) per visible section');
    }

    public function test_a_section_with_no_visible_action_is_not_rendered_and_the_order_keeps_every_action_by_name(): void
    {
        $order = $this->bankOrder(OrderStatus::SHIPPED);
        $page = $this->page($order);

        [, , $sections] = $this->header($page);

        // A shipped order with a pending bank payment: deliver; the receipt; the return and the cancel (no money-only refund:
        // nothing is settled). A hidden action is never listed.
        $this->assertSame([['deliver'], ['mark_as_received'], ['record_return', 'cancel']], $sections);

        // callAction by name still reaches an action inside the menu.
        $page->assertActionVisible('mark_as_received')->assertActionHidden('confirm');
    }

    public function test_the_menu_is_absent_when_nothing_in_it_is_visible(): void
    {
        $order = $this->bankOrder();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        $page = $this->page($order);

        [$buttons, $menu, $sections] = $this->header($page);

        $this->assertTrue($menu->isHidden(), 'the menu has nothing visible');
        $this->assertSame([[], [], []], $sections);
        $this->assertSame(0, preg_match_all('/class="fi-dropdown-list"/', $page->html()));
        $this->assertFalse($buttons[0]->isVisible(), 'and neither is the add-note button, without ORDER_MANAGE');
    }

    public function test_the_items_sections_edit_button_is_not_part_of_the_menu(): void
    {
        $this->assertNotContains('edit_order', array_keys($this->actionsByName()));
    }

    // =====================================================================================================
    // 3. One merchant-facing payment state
    // =====================================================================================================

    public function test_the_header_payment_state_for_each_of_the_five_bank_states_in_en_and_bg(): void
    {
        $cases = [];

        $none = $this->bankOrder();
        $cases[] = [$none, 'Awaiting payment', 'Чака плащане'];

        $partial = $this->bankOrder();
        $this->receive($partial, 7000);
        $cases[] = [$partial, 'Partly received (70.00 of 100.00 EUR)', 'Получено частично (70.00 от 100.00 EUR)'];

        $over = $this->bankOrder();
        $this->receive($over, 12000);
        $cases[] = [$over, 'Over-received (120.00 of 100.00 EUR)', 'Получено повече (120.00 от 100.00 EUR)'];

        $paid = $this->bankOrder();
        $this->receive($paid, 10000);
        $cases[] = [$paid, 'Paid', 'Платено'];

        $accepted = $this->bankOrder();
        $this->receive($accepted, 9000);
        $this->page($accepted)->mountAction('accept_mismatch')->setActionData(['reason' => 'agreed by phone'])->callMountedAction();
        $cases[] = [$accepted, 'Paid, mismatch accepted (90.00 of 100.00 EUR)', 'Платено, прието разминаване (90.00 от 100.00 EUR)'];

        foreach ($cases as $i => [$order, $en, $bg]) {
            App::setLocale('en');
            $this->assertSame($en, $this->state($order), "case {$i} (en)");
            App::setLocale('bg');
            $this->assertSame($bg, $this->state($order), "case {$i} (bg)");
        }
    }

    private function state(array $order): string
    {
        $this->app->forgetScopedInstances();

        return \App\Filament\Resources\OrderResource\PaymentReceiptDialog::paymentState(
            \EasyCo\Order\Persistence\Eloquent\OrderModel::findOrFail($order['orderId']),
            fn (string $status): string => 'adapter:'.$status,
        );
    }

    public function test_the_accepted_state_shows_in_the_rendered_header_and_the_page_still_has_no_contradiction(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 9000);
        $this->page($order)->mountAction('accept_mismatch')->setActionData(['reason' => 'agreed by phone'])->callMountedAction();

        $this->page($order)
            ->assertSee('Paid, mismatch accepted (90.00 of 100.00 EUR)')
            ->assertSee(__('orders.fields.payment_method_status'))
            ->assertSee(__('orders.fields.payment_settled'));
    }

    public function test_the_state_of_cash_on_delivery_and_of_a_failed_or_voided_payment(): void
    {
        $pending = $this->bankOrder(method: 'cash_on_delivery');
        $settled = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'cash_on_delivery');

        foreach (['en' => ['Awaiting payment', 'Paid'], 'bg' => ['Чака плащане', 'Платено']] as $locale => [$awaiting, $paid]) {
            App::setLocale($locale);
            $this->assertSame($awaiting, $this->state($pending), "cod pending ({$locale})");
            $this->assertSame($paid, $this->state($settled), "cod settled ({$locale})");
        }

        // A failed attempt keeps the adapter's label.
        $failedOrder = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]], method: 'bank_transfer', settle: false);
        $failed = Payment::create($failedOrder['orderId'], 'bank_transfer', $this->eur(10000), PaymentStatus::PENDING);
        $failed->recordAttemptResult(PaymentStatus::FAILED, null, 'declined', new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($failed);
        $this->assertSame('adapter:failed', $this->state($failedOrder));
    }

    public function test_the_payment_section_has_the_technical_status_and_money_received_yes_or_no(): void
    {
        $bank = $this->bankOrder();
        $paid = $this->bankOrder();
        $this->receive($paid, 10000);

        foreach (['en' => ['Status from the payment method', 'Money received', 'Yes', 'No'], 'bg' => ['Статус от платежния метод', 'Парите са получени', 'Да', 'Не']] as $locale => [$technical, $received, $yes, $no]) {
            App::setLocale($locale);

            $this->assertSame($technical, __('orders.fields.payment_method_status'));
            $this->assertSame($received, __('orders.fields.payment_settled'));
            $this->assertSame($yes, __('orders.payment_settled_yes'));
            $this->assertSame($no, __('orders.payment_settled_no'));

            $notReceived = preg_replace('/\s+/', ' ', $this->page($bank)->html());
            $this->assertStringContainsString($technical, $notReceived);
            $this->assertMatchesRegularExpression('/'.preg_quote($received, '/').'.{0,600}?>\s*'.preg_quote($no, '/').'\s*</us', $notReceived);

            $isReceived = preg_replace('/\s+/', ' ', $this->page($paid)->html());
            $this->assertMatchesRegularExpression('/'.preg_quote($received, '/').'.{0,600}?>\s*'.preg_quote($yes, '/').'\s*</us', $isReceived);
        }
    }

    public function test_the_header_state_is_a_plain_badge_without_a_severity_colour(): void
    {
        $order = $this->bankOrder();
        $this->receive($order, 7000);

        $html = $this->page($order)->html();
        $at = strpos($html, 'Partly received (70.00 of 100.00 EUR)');
        $this->assertNotFalse($at);

        $badge = substr($html, max(0, $at - 600), 700);
        foreach (['fi-color-danger', 'fi-color-warning', 'fi-color-success'] as $color) {
            $this->assertStringNotContainsString($color, $badge);
        }
    }

    public function test_the_payment_state_adds_no_query_to_the_header(): void
    {
        $bank = $this->bankOrder();
        $this->receive($bank, 7000);
        $cod = $this->bankOrder(method: 'cash_on_delivery');

        $receiptQueries = function (array $order): int {
            $this->app->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ViewOrder::class, ['record' => $order['orderId']]);
            $count = count(array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'payment_receipts')));
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame(2, $receiptQueries($bank), 'the header shares the receipts block\'s two reads');
        $this->assertSame(0, $receiptQueries($cod));
    }
}
