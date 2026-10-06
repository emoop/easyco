<?php

namespace App\Filament\Resources\OrderResource;

use App\Filament\Resources\OrderResource;
use App\Services\Exceptions\PaymentReceiptRefusedException;
use App\Services\MoneyInput;
use App\Services\OrderAdminReader;
use App\Services\PaymentReceiptRecorder;
use App\Services\PaymentReceiptReader;
use App\Services\PaymentReceiptState;
use App\Services\PaymentReceiptStatus;
use App\Services\PaymentReconcilePolicy;
use App\Settings\StoreTimezone;
use Closure;
use DateTimeImmutable;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Payment\PaymentReceipt;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn as RepeatableTableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use WeakMap;

/**
 * The admin of bank-transfer receipts (refunds R4a-4, shipping-domain-design.md §7.2.20 §6, §7): the three
 * dialogs — record a received transfer, accept the received amount, correct a receipt — and the "bank
 * transfers received" block of the order page. It builds form components and turns a submission into a
 * service call, and DECIDES NOTHING: PaymentReceiptRecorder is the one that refuses, PaymentReceiptReader /
 * PaymentReceiptStatus the one source of every figure the screens show (the live hint reads
 * `withReceipt($typed)`; no sum is added here). A refusal comes back as a field error on the dialog it
 * belongs to, or as a translated notice — never a 500.
 *
 * Conventions copied from RefundDialog: a hidden operation key generated when the dialog opens (a double
 * submit records once), amount boxes through MoneyInput with maxLength 20, text boxes with maxLength equal
 * to the column, days as plain 'Y-m-d' store-timezone days that are never shifted.
 *
 * OrderResource only WIRES these (the actions, the section, and the one catch in runOrderAction).
 */
final class PaymentReceiptDialog
{
    /** An amount box holds at most this many characters (MoneyInput refuses more than 32 anyway). */
    public const AMOUNT_MAX_LENGTH = 20;

    /** payment_receipts.bank_reference is VARCHAR(64). */
    public const REFERENCE_MAX_LENGTH = PaymentReceipt::MAX_REFERENCE_LENGTH;

    /** payments.settlement_reason is VARCHAR(255); a correction's reason is held to the same width. */
    public const REASON_MAX_LENGTH = PaymentReceiptRecorder::MAX_REASON_LENGTH;

    /** @var WeakMap<OrderModel, ?array>|null */
    private static ?WeakMap $views = null;

    // ---- the reads -------------------------------------------------------------------------------

    /** The order's latest payment when it is a bank transfer, else null. No query beyond the page's own order read. */
    public static function bankPayment(OrderModel $record): ?Payment
    {
        $payment = app(OrderAdminReader::class)->forOrder((string) $record->id)->latestPayment;

        return $payment !== null && $payment->method() === PaymentReceiptReader::BANK_TRANSFER ? $payment : null;
    }

    /**
     * What the order page shows about a BANK-TRANSFER latest payment, read once per page (memoised on the
     * record): the status and the effective receipts, in TWO queries (the grouped sum and the rows), and who
     * recorded each one from the history the page already loaded. Null for every other payment — cash on
     * delivery and orders without a payment read nothing at all.
     *
     * The status is composed here exactly as PaymentReceiptReader::forPayment() composes it (sum, count,
     * settled) from the same two repository reads, because that method does not hand back the rows and a
     * third query would be the price; there is no arithmetic in the composition.
     *
     * @return array{payment: Payment, status: PaymentReceiptStatus, receipts: list<PaymentReceipt>, who: array<string, string>}|null
     */
    public static function view(OrderModel $record): ?array
    {
        $payment = self::bankPayment($record);

        if ($payment === null) {
            return null;
        }

        self::$views ??= new WeakMap();

        return self::$views[$record] ??= self::read($record, $payment);
    }

    /** @return array{payment: Payment, status: PaymentReceiptStatus, receipts: list<PaymentReceipt>, who: array<string, string>} */
    private static function read(OrderModel $record, Payment $payment): array
    {
        $repository = app(PaymentReceiptRepository::class);
        $paymentId = (string) $payment->id();
        $receipts = $repository->findEffectiveByPaymentId($paymentId);
        $status = new PaymentReceiptStatus(
            $payment->amount(),
            $repository->effectiveSum($paymentId, $payment->amount()->currency()->code()),
            count($receipts),
            $payment->isSettled(),
        );

        $who = [];

        foreach (app(OrderAdminReader::class)->forOrder((string) $record->id)->events as $event) {
            if ($event->paymentReceiptId !== null && in_array($event->type, ['payment_receipt_recorded', 'payment_receipt_corrected'], true)) {
                $who[$event->paymentReceiptId] = $event->staffName ?? __('orders.system_actor');
            }
        }

        return ['payment' => $payment, 'status' => $status, 'receipts' => $receipts, 'who' => $who];
    }

    // ---- visibility ----------------------------------------------------------------------------------

    /** The record dialog: a confirmable bank-transfer payment (the one-click confirm of cash on delivery is OrderResource's own). */
    public static function canRecord(OrderModel $record): bool
    {
        return self::bankPayment($record)?->isConfirmable() ?? false;
    }

    /** Accept: a bank payment that is partial or over, for a staff member the service itself would let through. */
    public static function canAccept(OrderModel $record): bool
    {
        $view = self::view($record);

        return $view !== null && $view['status']->isUnreconciled() && app(PaymentReconcilePolicy::class)->mayReconcile();
    }

    /** Correct: ORDER_MANAGE and at least one effective receipt. */
    public static function canCorrect(OrderModel $record): bool
    {
        $view = self::view($record);

        return $view !== null
            && $view['status']->effectiveCount() >= 1
            && ! $view['payment']->isVoided()
            && OrderResource::staffHasPermission(Permission::ORDER_MANAGE);
    }

    // ---- the actions (OrderResource wires them) ------------------------------------------------------

    /** @param  Closure(OrderModel, mixed, Closure, string): void  $run  OrderResource::runOrderAction() */
    public static function acceptAction(Closure $run): Action
    {
        return Action::make('accept_mismatch')
            ->label(__('orders.receipt.accept.label'))
            ->modalSubmitActionLabel(__('orders.modal.submit.accept_mismatch'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->color('warning')
            ->icon('heroicon-o-scale')
            ->modalHeading(fn (OrderModel $record): string => __('orders.receipt.accept.heading', ['id' => $record->id]))
            ->modalDescription(__('orders.receipt.accept.description'))
            ->schema(fn (OrderModel $record): array => self::acceptSchema($record))
            ->visible(fn (OrderModel $record): bool => self::canAccept($record))
            ->action(fn (array $data, OrderModel $record, $livewire) => self::accept($data, $record, $livewire, $run));
    }

    /** @param  Closure(OrderModel, mixed, Closure, string): void  $run  OrderResource::runOrderAction() */
    public static function correctAction(Closure $run): Action
    {
        return Action::make('correct_receipt')
            ->label(__('orders.receipt.correct.label'))
            ->modalSubmitActionLabel(__('orders.modal.submit.correct_receipt'))
            ->modalCancelActionLabel(__('orders.modal.close'))
            ->color('gray')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(fn (OrderModel $record): string => __('orders.receipt.correct.heading', ['id' => $record->id]))
            ->modalDescription(__('orders.receipt.correct.description'))
            ->schema(fn (OrderModel $record): array => self::correctSchema($record))
            ->visible(fn (OrderModel $record): bool => self::canCorrect($record))
            ->action(fn (array $data, OrderModel $record, $livewire) => self::correct($data, $record, $livewire, $run));
    }

    // ---- the schemas ---------------------------------------------------------------------------------

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function recordSchema(OrderModel $record): array
    {
        $payment = self::bankPayment($record);

        if ($payment === null) {
            return [];
        }

        $currency = $payment->amount()->currency()->code();
        // What the form shows is read when the dialog opens: the one reader, both reads under it.
        $status = app(PaymentReceiptReader::class)->forPayment($payment);
        $remaining = $status->difference()->isNegative() ? Money::zero($currency)->subtract($status->difference()) : null;
        $timezone = app(StoreTimezone::class);
        $placedDay = $timezone->dayOf($record->placed_at->toDateTimeImmutable());

        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('amount')
                    ->label(__('orders.receipt.record.amount'))
                    ->suffix($currency)
                    ->inputMode('decimal')
                    ->required()
                    ->default($remaining?->decimalValue())
                    ->live(onBlur: true)
                    ->maxLength(self::AMOUNT_MAX_LENGTH)
                    ->rules(self::amountRules($currency)),
                self::dayField('received_on', __('orders.receipt.record.received_on'), $placedDay)
                    ->default(fn (): string => app(StoreTimezone::class)->today()),
            ]),
            TextInput::make('bank_reference')
                ->label(__('orders.receipt.record.bank_reference'))
                ->required()
                ->maxLength(self::REFERENCE_MAX_LENGTH),
            // The live hint comes from the status object only: expected, recorded so far, and what the typed
            // transfer would make of it. Nothing is hinted while the amount cannot be read.
            Text::make(fn (Get $get): string => self::hint($status, $currency, $get('amount'))),
            Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function acceptSchema(OrderModel $record): array
    {
        $payment = self::bankPayment($record);

        if ($payment === null) {
            return [];
        }

        $status = app(PaymentReceiptReader::class)->forPayment($payment);
        $received = $status->received();

        return [
            Text::make(__('orders.receipt.accept.expected', ['amount' => self::format($status->expected())])),
            Text::make(__('orders.receipt.accept.received', ['amount' => self::format($received)]))->weight('bold'),
            Text::make(self::differenceText($status, 'accept')),
            TextInput::make('reason')
                ->label(__('orders.receipt.accept.reason'))
                ->required()
                ->maxLength(self::REASON_MAX_LENGTH),
            Text::make(__('orders.receipt.accept.warning', ['amount' => self::format($received)]))->weight('bold'),
            Text::make(__('orders.receipt.accept.help')),
            // THE FIGURE THE MERCHANT SAW when the dialog opened: sent to acceptMismatch(), which refuses it as
            // changed if a receipt arrived in between.
            Hidden::make('accepted_amount')->default($received->decimalValue()),
            Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function correctSchema(OrderModel $record): array
    {
        $payment = self::bankPayment($record);

        if ($payment === null) {
            return [];
        }

        // Newest first. Read when the dialog opens.
        $receipts = array_reverse(app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $payment->id()));

        if ($receipts === []) {
            return [];
        }

        $currency = $payment->amount()->currency()->code();
        $settled = $payment->isSettled();
        $byId = [];
        $options = [];

        foreach ($receipts as $receipt) {
            $byId[(string) $receipt->id()] = $receipt;
            $options[(string) $receipt->id()] = __('orders.receipt.correct.option', [
                'day' => $receipt->receivedOn(),
                'amount' => self::format($receipt->amount()),
                'reference' => $receipt->bankReference(),
            ]);
        }

        $first = $receipts[0];
        $placedDay = app(StoreTimezone::class)->dayOf($record->placed_at->toDateTimeImmutable());

        return [
            Select::make('receipt_id')
                ->label(__('orders.receipt.correct.receipt'))
                ->options($options)
                ->default((string) $first->id())
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, Set $set) use ($byId): void {
                    $receipt = $byId[(string) $state] ?? null;

                    if ($receipt !== null) {
                        $set('amount', $receipt->amount()->decimalValue());
                        $set('received_on', $receipt->receivedOn());
                        $set('bank_reference', $receipt->bankReference());
                    }
                }),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('amount')
                    ->label(__('orders.receipt.record.amount'))
                    ->suffix($currency)
                    ->inputMode('decimal')
                    ->required()
                    ->default($first->amount()->decimalValue())
                    // After settlement the amount cannot be corrected: the box is read-only and the same amount is submitted.
                    ->readOnly($settled)
                    ->maxLength(self::AMOUNT_MAX_LENGTH)
                    ->rules(self::amountRules($currency))
                    ->helperText($settled ? __('orders.receipt.correct.amount_locked') : null),
                self::dayField('received_on', __('orders.receipt.record.received_on'), $placedDay)
                    ->default($first->receivedOn()),
            ]),
            TextInput::make('bank_reference')
                ->label(__('orders.receipt.record.bank_reference'))
                ->required()
                ->default($first->bankReference())
                ->maxLength(self::REFERENCE_MAX_LENGTH),
            TextInput::make('reason')
                ->label(__('orders.receipt.correct.reason'))
                ->required()
                ->maxLength(self::REASON_MAX_LENGTH),
            Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** The day picker: a calendar day, no time; today and the placement day are the STORE's, never the app's (UTC). */
    private static function dayField(string $name, string $label, string $placedDay): DatePicker
    {
        return DatePicker::make($name)
            ->label($label)
            ->required()
            ->format('Y-m-d')
            // The picker compares the typed 'Y-m-d' with these as plain text, so they are given in the merchant's
            // calendar (the same caveat as the paid-out-date picker and the announced-return day).
            ->minDate($placedDay)
            ->maxDate(fn (): string => app(StoreTimezone::class)->today());
    }

    /** The validation every amount box shares: a typed value must be an amount (MoneyInput's limits). */
    private static function amountRules(string $currency): array
    {
        return [
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($currency): void {
                if (filled($value) && MoneyInput::parse((string) $value, $currency) === null) {
                    $fail(__('orders.refund_dialog.invalid_amount'));
                }
            },
        ];
    }

    // ---- the live hint -------------------------------------------------------------------------------

    /** "Expected … · recorded so far … · this transfer … → matches / short / over"; '' while the amount cannot be read. */
    private static function hint(PaymentReceiptStatus $status, string $currency, mixed $typed): string
    {
        $amount = is_scalar($typed) ? MoneyInput::parse((string) $typed, $currency) : null;

        if ($amount === null || ! $amount->isPositive()) {
            return '';
        }

        $after = $status->withReceipt($amount);

        $text = __('orders.receipt.record.hint', [
            'expected' => self::format($status->expected()),
            'recorded' => self::format($status->received()),
            'this' => self::format($amount),
        ]);

        if ($after->matches()) {
            return $text.' '.__('orders.receipt.record.hint_matches');
        }

        return $text.' '.__('orders.receipt.record.hint_'.($after->difference()->isNegative() ? 'short' : 'over'), [
            'difference' => self::format(self::magnitude($after->difference())),
        ]);
    }

    /** "Short by X" / "Over by X" — neutral words, no colour, no severity. */
    private static function differenceText(PaymentReceiptStatus $status, string $group): string
    {
        $key = $status->difference()->isNegative() ? 'short' : 'over';
        $prefix = $group === 'accept' ? 'orders.receipt.accept.' : 'orders.receipt.section.';

        return __($prefix.($group === 'accept' ? $key : $key.'_by'), ['difference' => self::format(self::magnitude($status->difference()))]);
    }

    // ---- the submissions -----------------------------------------------------------------------------

    /** @param  Closure(OrderModel, mixed, Closure, string): void  $run */
    public static function record(array $data, OrderModel $record, $livewire, Closure $run): void
    {
        $payment = self::bankPayment($record);

        if ($payment === null || ! $payment->isConfirmable()) {
            self::notice(__('orders.actions.no_eligible_payment'));

            return;
        }

        $amount = self::amountFrom($data['amount'] ?? null, $payment, $livewire);
        $day = is_string($data['received_on'] ?? null) ? $data['received_on'] : '';
        $reference = is_string($data['bank_reference'] ?? null) ? $data['bank_reference'] : '';
        $key = self::keyFrom($data);

        $run(
            $record,
            $livewire,
            fn () => app(PaymentReceiptRecorder::class)->record((string) $payment->id(), $amount, $day, $reference, new DateTimeImmutable(), $key),
            __('orders.receipt.record.done'),
        );
    }

    /** @param  Closure(OrderModel, mixed, Closure, string): void  $run */
    public static function accept(array $data, OrderModel $record, $livewire, Closure $run): void
    {
        $payment = self::bankPayment($record);

        if ($payment === null) {
            self::notice(__('orders.actions.no_eligible_payment'));

            return;
        }

        $accepted = MoneyInput::parse(is_scalar($data['accepted_amount'] ?? null) ? (string) $data['accepted_amount'] : '', $payment->amount()->currency()->code());

        if ($accepted === null) {
            self::notice(__('orders.receipt.accept.changed'));

            return;
        }

        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
        $key = self::keyFrom($data);

        $run(
            $record,
            $livewire,
            fn () => app(PaymentReceiptRecorder::class)->acceptMismatch((string) $payment->id(), $accepted, $reason, new DateTimeImmutable(), $key),
            __('orders.receipt.accept.done'),
        );
    }

    /** @param  Closure(OrderModel, mixed, Closure, string): void  $run */
    public static function correct(array $data, OrderModel $record, $livewire, Closure $run): void
    {
        $payment = self::bankPayment($record);
        $receiptId = is_scalar($data['receipt_id'] ?? null) ? (string) $data['receipt_id'] : '';

        // The receipt id comes from the browser: it must be one of THIS order's payment's effective receipts, or
        // another order's receipt could be corrected from this page.
        $mine = $payment === null ? [] : array_map(
            static fn (PaymentReceipt $receipt): ?string => $receipt->id(),
            app(PaymentReceiptRepository::class)->findEffectiveByPaymentId((string) $payment->id()),
        );

        if ($payment === null || ! in_array($receiptId, $mine, true)) {
            self::notice(__('orders.receipt.correct.not_found'));

            return;
        }

        $amount = self::amountFrom($data['amount'] ?? null, $payment, $livewire);
        $day = is_string($data['received_on'] ?? null) ? $data['received_on'] : '';
        $reference = is_string($data['bank_reference'] ?? null) ? $data['bank_reference'] : '';
        $reason = is_string($data['reason'] ?? null) ? $data['reason'] : '';
        $key = self::keyFrom($data);

        $run(
            $record,
            $livewire,
            fn () => app(PaymentReceiptRecorder::class)->correct($receiptId, $amount, $day, $reference, $reason, new DateTimeImmutable(), $key),
            __('orders.receipt.correct.done'),
        );
    }

    // ---- refusals ------------------------------------------------------------------------------------

    /**
     * The form field a service refusal belongs to, or null when it is a notice. `accepted_amount` is a hidden
     * field and a currency mismatch cannot be fixed in a box that carries the currency as its suffix: both are
     * notices.
     */
    public static function fieldFor(PaymentReceiptRefusedException $exception): ?string
    {
        if (in_array($exception->reason, [PaymentReceiptRefusedException::ACCEPTED_AMOUNT_CHANGED, PaymentReceiptRefusedException::CURRENCY_MISMATCH], true)) {
            return null;
        }

        return $exception->field();
    }

    /**
     * A service refusal on the dialog: an error on THAT field (the dialog stays open and nothing was written),
     * or a translated notice when it belongs to no field.
     *
     * @throws ValidationException the field error
     */
    public static function refuse(PaymentReceiptRefusedException $exception, $livewire): void
    {
        $field = self::fieldFor($exception);

        if ($field !== null) {
            throw ValidationException::withMessages([self::statePath($livewire, $field) => [$exception->getMessage()]]);
        }

        self::notice($exception->reason === PaymentReceiptRefusedException::ACCEPTED_AMOUNT_CHANGED
            ? __('orders.receipt.accept.changed')
            : $exception->getMessage());
    }

    public static function notice(string $body): void
    {
        Notification::make()
            ->title(__('orders.actions.refused_title'))
            ->body($body)
            ->danger()
            ->send();
    }

    // ---- the header state ----------------------------------------------------------------------------

    /**
     * The ONE payment state of the order header (UI pass 1), in a merchant's words, derived only from what the page
     * already read: the latest payment and, for a bank transfer, its receipts (view(), memoised — no query of its
     * own; cash on delivery reads nothing). A failed or voided payment keeps the label of the adapter's status.
     * Facts, not severity: the caller shows it as a plain badge.
     *
     * @param  Closure(string): string  $adapterLabel  the label of a payment status (OrderResource::optionLabel)
     */
    public static function paymentState(OrderModel $record, Closure $adapterLabel): string
    {
        $payment = app(OrderAdminReader::class)->forOrder((string) $record->id)->latestPayment;

        if ($payment === null) {
            return __('orders.no_payment');
        }

        if ($payment->status() === PaymentStatus::FAILED || $payment->isVoided()) {
            return $adapterLabel($payment->status()->value);
        }

        if ($payment->method() !== PaymentReceiptReader::BANK_TRANSFER) {
            return $payment->isSettled() ? __('orders.payment_state.paid') : __('orders.payment_state.awaiting');
        }

        $view = self::view($record);
        $status = $view['status'];
        $figures = fn (Money $received): array => [
            'received' => $received->decimalValue(),
            'expected' => self::format($payment->amount()),
        ];

        if ($payment->isSettled()) {
            return $payment->settlementReason() !== null
                ? __('orders.payment_state.paid_accepted', $figures($payment->settledAmount()))
                : __('orders.payment_state.paid');
        }

        return match ($status->state()) {
            PaymentReceiptState::NONE => __('orders.payment_state.awaiting'),
            PaymentReceiptState::PARTIAL => __('orders.payment_state.partial', $figures($status->received())),
            default => __('orders.payment_state.over', $figures($status->received())),
        };
    }

    // ---- the order page ------------------------------------------------------------------------------

    /**
     * The "bank transfers received" block: only for a BANK-TRANSFER latest payment. Expected, received so far,
     * one neutral line for a partial / over total ("short by X" / "over by X": no colour, no severity), the
     * effective receipts (day · amount · reference · who), "received amount not recorded" for a payment settled
     * before receipts were kept, and "settled for X of Y, accepted: reason" for an accepted mismatch.
     */
    public static function section(): Section
    {
        $view = static fn (OrderModel $record): ?array => self::view($record);

        return Section::make(__('orders.receipt.section.heading'))
            ->compact()
            ->visible(fn (OrderModel $record): bool => $view($record) !== null)
            ->schema([
                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    TextEntry::make('receipts_expected')
                        ->label(__('orders.receipt.section.expected'))
                        ->getStateUsing(fn (OrderModel $record): ?string => ($v = $view($record)) === null ? null : self::format($v['status']->expected())),
                    TextEntry::make('receipts_received')
                        ->label(__('orders.receipt.section.received'))
                        ->visible(fn (OrderModel $record): bool => ($v = $view($record)) !== null && ! self::isLegacy($v))
                        ->getStateUsing(fn (OrderModel $record): ?string => ($v = $view($record)) === null ? null : self::format($v['status']->received())),
                    TextEntry::make('receipts_difference')
                        ->hiddenLabel()
                        ->visible(fn (OrderModel $record): bool => ($v = $view($record)) !== null && $v['status']->isUnreconciled())
                        ->getStateUsing(fn (OrderModel $record): ?string => ($v = $view($record)) === null ? null : self::differenceText($v['status'], 'section')),
                ]),
                TextEntry::make('receipts_note')
                    ->hiddenLabel()
                    ->visible(fn (OrderModel $record): bool => ($v = $view($record)) !== null && self::note($v) !== null)
                    ->getStateUsing(fn (OrderModel $record): ?string => ($v = $view($record)) === null ? null : self::note($v)),
                RepeatableEntry::make('receipts_rows')
                    ->hiddenLabel()
                    ->visible(fn (OrderModel $record): bool => ($v = $view($record)) !== null && $v['receipts'] !== [])
                    ->getStateUsing(fn (OrderModel $record): array => self::rows($view($record)))
                    ->table([
                        RepeatableTableColumn::make(__('orders.receipt.section.day')),
                        RepeatableTableColumn::make(__('orders.receipt.section.amount')),
                        RepeatableTableColumn::make(__('orders.receipt.section.reference')),
                        RepeatableTableColumn::make(__('orders.receipt.section.who')),
                    ])
                    ->schema([
                        TextEntry::make('day')->hiddenLabel(),
                        TextEntry::make('amount')->hiddenLabel(),
                        TextEntry::make('reference')->hiddenLabel(),
                        TextEntry::make('who')->hiddenLabel(),
                    ]),
            ]);
    }

    /** A settled payment with no receipt and no accepted amount: settled before receipts were kept. */
    private static function isLegacy(array $view): bool
    {
        return $view['payment']->isSettled() && $view['status']->effectiveCount() === 0 && $view['payment']->settlementReason() === null;
    }

    private static function note(array $view): ?string
    {
        $payment = $view['payment'];

        return match (true) {
            $payment->settlementReason() !== null => __('orders.receipt.section.accepted', [
                'settled' => self::format($payment->settledAmount()),
                'expected' => self::format($payment->amount()),
                'reason' => (string) $payment->settlementReason(),
            ]),
            self::isLegacy($view) => __('orders.receipt.section.legacy'),
            ! $payment->isSettled() && $view['status']->effectiveCount() === 0 => __('orders.receipt.section.none_yet'),
            default => null,
        };
    }

    /** @return list<array{day: string, amount: string, reference: string, who: string}> */
    private static function rows(?array $view): array
    {
        if ($view === null) {
            return [];
        }

        return array_map(static fn (PaymentReceipt $receipt): array => [
            'day' => $receipt->receivedOn(),
            'amount' => self::format($receipt->amount()),
            'reference' => $receipt->bankReference(),
            'who' => $view['who'][(string) $receipt->id()] ?? __('orders.system_actor'),
        ], $view['receipts']);
    }

    // ---- helpers ---------------------------------------------------------------------------------------

    /** @param  mixed  $livewire  the page; the dialog's state path is where its field errors must land */
    private static function statePath($livewire, string $field): string
    {
        return 'mountedActions.'.array_key_last($livewire->mountedActions ?? [0 => []]).'.data.'.$field;
    }

    private static function amountFrom(mixed $raw, Payment $payment, $livewire): Money
    {
        $amount = is_scalar($raw) ? MoneyInput::parse((string) $raw, $payment->amount()->currency()->code()) : null;

        if ($amount === null) {
            throw ValidationException::withMessages([self::statePath($livewire, 'amount') => [__('orders.refund_dialog.invalid_amount')]]);
        }

        return $amount;
    }

    private static function keyFrom(array $data): ?string
    {
        $key = $data['operation_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** The size of a signed difference, for "short by X" / "over by X". */
    private static function magnitude(Money $difference): Money
    {
        return $difference->isNegative() ? Money::zero($difference->currency())->subtract($difference) : $difference;
    }

    private static function format(Money $money): string
    {
        return $money->decimalValue().' '.$money->currency()->code();
    }
}
