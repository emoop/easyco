<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Services\MoneyInput;
use DateTimeImmutable;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use Filament\Notifications\Livewire\Notifications;
use Filament\Schemas\Components\Text;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\BuildsRefundableOrders;
use Tests\TestCase;

/**
 * Refunds R3 part 2 follow-up: input limits in the refund dialogs (bots, malicious text, overlong
 * text). An amount is at most 32 characters and 9 integer digits (MoneyInput) and its box holds 20
 * characters; a reason holds 255 (the width of the refund's own varchar(255) columns). Every breach is a FIELD ERROR — never an exception, never a write.
 */
class OrderRefundInputLimitsTest extends TestCase
{
    use BuildsRefundableOrders;
    use RefreshDatabase;

    private const THIRTY_DIGITS = '999999999999999999999999999999';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdministrator();
    }

    private function page(string $orderId): Testable
    {
        return Livewire::test(ViewOrder::class, ['record' => $orderId]);
    }

    private function settledOrder(): array
    {
        return $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: 500);
    }

    private function pendingOrder(): array
    {
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 1000]], shippingMinor: 500, settle: false);
        $payment = Payment::create($order['orderId'], 'cash_on_delivery', $this->eur(5500), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $order;
    }

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications();
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    /** @return list<string> the text of every visible Text component of the mounted dialog (the live total among them) */
    private function texts(Testable $component): array
    {
        $instance = $component->instance();
        $schema = (new ReflectionMethod($instance, 'getMountedActionSchema'))->invoke($instance);
        $texts = [];

        foreach ($schema->getFlatComponents(withActions: false) as $each) {
            if ($each instanceof Text) {
                $texts[] = trim(strip_tags((string) $each->getContent()));
            }
        }

        return $texts;
    }

    private function assertNothingWritten(array $order): void
    {
        $this->assertSame(0, DB::table('payment_refunds')->count());
        $this->assertSame(0, DB::table('operational_sales_sale_lines')->where('type', 'refund')->count());
        $this->assertSame([], $this->eventTypesOf($order['orderId']));
        $this->assertSame(10, $this->stockOf($order['variationIds'][0]));
    }

    // ---- MoneyInput ------------------------------------------------------------------------------

    public function test_money_input_refuses_more_than_32_characters_and_more_than_9_integer_digits_without_throwing(): void
    {
        $this->assertSame(32, MoneyInput::MAX_LENGTH);
        $this->assertSame(9, MoneyInput::MAX_INTEGER_DIGITS);

        $this->assertNull(MoneyInput::parse('1'.str_repeat(' ', 32), 'EUR'), '33 characters');
        $this->assertSame(100, MoneyInput::parse('1'.str_repeat(' ', 31), 'EUR')?->minorValue(), '32 characters are still read');
        $this->assertNull(MoneyInput::parse(self::THIRTY_DIGITS, 'EUR'));
        $this->assertNull(MoneyInput::parse(str_repeat('9', 5000), 'EUR'), 'refused before any regex runs on it');
        $this->assertNull(MoneyInput::parse(str_repeat("\u{00A0}", 5000), 'EUR'));
        $this->assertNull(MoneyInput::parse('1234567890', 'EUR'), '10 integer digits');
        $this->assertNull(MoneyInput::parse('1234567890.50', 'EUR'));
        $this->assertSame(99999999999, MoneyInput::parse('999999999,99', 'EUR')?->minorValue(), '9 integer digits: far below PHP_INT_MAX');
        $this->assertSame(12345678950, MoneyInput::parse('123456789.50', 'EUR')?->minorValue());
        $this->assertNull(MoneyInput::parseOrZero(self::THIRTY_DIGITS, 'EUR'));
    }

    // ---- an amount of 30 digits, in every amount box ---------------------------------------------

    public function test_a_30_digit_amount_in_any_box_of_a_settled_dialog_is_a_field_error_and_writes_nothing(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $base = ['quantity' => [$line => 2], 'restock' => [$line => true]];

        foreach ([
            'shipping' => ['shipping' => self::THIRTY_DIGITS],
            'deduction' => ['deduction' => self::THIRTY_DIGITS, 'deduction_reason' => 'x'],
            "goods.{$line}" => ['goods' => [$line => self::THIRTY_DIGITS]],
        ] as $field => $overrides) {
            $this->page($order['orderId'])->callAction('record_return', data: array_replace($base, $overrides))->assertHasActionErrors([$field]);
        }

        $this->page($order['orderId'])->callAction('cancel', data: ['shipping' => self::THIRTY_DIGITS, 'restock' => [$line => true]])->assertHasActionErrors(['shipping']);

        foreach (['shipping', 'adjustment'] as $field) {
            $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '1', 'adjustment' => '1', 'reason' => 'x', 'channel' => 'cash', $field => self::THIRTY_DIGITS])
                ->assertHasActionErrors([$field]);
        }

        $this->assertNothingWritten($order);
    }

    public function test_a_30_digit_shipping_reduction_in_a_pending_dialog_is_a_field_error_and_writes_nothing(): void
    {
        $order = $this->pendingOrder();
        [$line] = $order['saleLineIds'];

        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true], 'shipping' => self::THIRTY_DIGITS])
            ->assertHasActionErrors(['shipping']);

        $this->assertNothingWritten($order);
        $this->assertCount(1, DB::table('payments')->where('order_id', $order['orderId'])->get(), 'the pending payment was neither voided nor reissued');
    }

    public function test_ten_integer_digits_are_refused_and_nine_are_accepted(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $base = ['quantity' => [$line => 2], 'restock' => [$line => true]];

        // 10 integer digits: short enough for the box, refused by the amount rule as a field error.
        $this->page($order['orderId'])->callAction('record_return', data: $base + ['shipping' => '1234567890'])->assertHasActionErrors(['shipping']);
        $this->assertNothingWritten($order);

        // 9 integer digits: a valid amount — so the SERVICE judges it (it is far above the 5.00 shipping), as a notice.
        $this->page($order['orderId'])->callAction('record_return', data: $base + ['shipping' => '123456789.50'])->assertHasNoActionErrors();
        $this->assertStringContainsString('5.00 EUR', (string) $this->lastNotificationBody());
        $this->assertNothingWritten($order);
    }

    public function test_the_amount_boxes_hold_at_most_20_characters(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        // 21 characters, every one a valid digit/separator and well inside MoneyInput's own limits: the box's limit refuses it.
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 2], 'restock' => [$line => true], 'shipping' => '0.'.str_repeat('0', 19)])
            ->assertHasActionErrors(['shipping']);
        $this->assertNothingWritten($order);
    }

    // ---- free text -------------------------------------------------------------------------------

    public function test_a_5000_character_reason_is_a_field_error_in_each_of_the_three_dialogs(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];
        $long = str_repeat('x', 5000);

        $this->page($order['orderId'])->callAction('cancel', data: ['reason' => $long, 'restock' => [$line => true]])->assertHasActionErrors(['reason']);
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 1], 'restock' => [$line => true], 'reason' => $long])->assertHasActionErrors(['reason']);
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 1], 'restock' => [$line => true], 'deduction' => '1', 'deduction_reason' => $long])
            ->assertHasActionErrors(['deduction_reason']);
        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '1', 'adjustment' => '0', 'reason' => $long, 'channel' => 'cash'])->assertHasActionErrors(['reason']);

        $this->assertNothingWritten($order);

        // The limit is 255, the width of payment_refunds.reason: 256 is refused, exactly 255 is recorded (not a database error).
        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '1', 'adjustment' => '0', 'reason' => str_repeat('x', 256), 'channel' => 'cash'])->assertHasActionErrors(['reason']);
        $this->page($order['orderId'])->callAction('refund_money_only', data: ['shipping' => '1', 'adjustment' => '0', 'reason' => str_repeat('x', 255), 'channel' => 'cash'])->assertHasNoActionErrors();
        $this->assertSame(1, DB::table('payment_refunds')->count());

        // The same two edges through the return dialog, whose reason and deduction reason also land in those columns.
        $this->page($order['orderId'])->callAction('record_return', data: ['quantity' => [$line => 1], 'restock' => [$line => true], 'reason' => str_repeat('y', 255), 'deduction' => '1', 'deduction_reason' => str_repeat('z', 255)])->assertHasNoActionErrors();
        $this->assertSame(2, DB::table('payment_refunds')->count());
    }

    // ---- the live total ----------------------------------------------------------------------------

    public function test_the_live_total_does_not_throw_with_a_30_digit_value_in_any_box(): void
    {
        $order = $this->settledOrder();
        [$line] = $order['saleLineIds'];

        $return = $this->page($order['orderId'])->mountAction('record_return');
        $return->set("mountedActions.0.data.quantity.{$line}", 2);

        foreach (['shipping', 'deduction', "goods.{$line}"] as $field) {
            $return->set("mountedActions.0.data.{$field}", self::THIRTY_DIGITS);
            $this->assertNotEmpty($this->texts($return), "the dialog still renders with {$field} = 30 digits");
        }

        $this->assertContains('Refund total: 20.00 EUR', array_values(array_filter($this->texts($return), fn (string $t): bool => str_starts_with($t, 'Refund total'))), 'an unreadable amount counts as nothing (an unreadable goods box is the computed share), it does not break the sum');

        $moneyOnly = $this->page($order['orderId'])->mountAction('refund_money_only');

        foreach (['shipping', 'adjustment'] as $field) {
            $moneyOnly->set("mountedActions.0.data.{$field}", self::THIRTY_DIGITS);
            $this->assertNotEmpty($this->texts($moneyOnly), "the money-only dialog still renders with {$field} = 30 digits");
        }

        $pending = $this->pendingOrder();
        $pendingReturn = $this->page($pending['orderId'])->mountAction('record_return');
        $pendingReturn->set('mountedActions.0.data.shipping', self::THIRTY_DIGITS);
        $this->assertNotEmpty($this->texts($pendingReturn));
    }
}
