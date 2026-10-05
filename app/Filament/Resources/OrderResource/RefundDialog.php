<?php

namespace App\Filament\Resources\OrderResource;

use App\Services\MoneyInput;
use App\Services\OrderAdminSaleLineView;
use App\Services\OrderReturnFactsReader;
use App\Services\RefundFormContext;
use App\Services\RefundFormReader;
use App\Services\RefundRequest;
use App\Settings\StoreTimezone;
use Closure;
use DateTimeImmutable;
use EasyCo\Payment\Enums\RefundChannel;
use EasyCo\Pricing\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The money part of the cancel / return dialogs and the whole money-only dialog (refunds R3 part 2,
 * shipping-domain-design.md §7.2.1, §7.2.4, §7.2.6, §7.2.11). It builds form components and turns a
 * submission into a RefundRequest — and decides NOTHING:
 *
 *  - every room it shows comes from RefundFormReader, which reads RefundCapGuard (the service's own
 *    source). No cap arithmetic here; a refusal is the service's, shown as a notice by the page;
 *  - a blank goods box means "the computed share" (what the service does with no entered amount), so
 *    a submission without the box is exactly the dialog of before;
 *  - all arithmetic is in minor units through Money; a comma or a dot separates decimals (MoneyInput).
 *
 * The two kinds: CANCEL returns every remaining unit (the quantity is fixed), RETURN lets the
 * merchant pick quantities (the goods box follows the quantity).
 */
final class RefundDialog
{
    public const CANCEL = 'cancel';

    public const RETURN = 'return';

    /** An amount box holds at most this many characters (MoneyInput refuses more than 32 anyway; this is the form's own, tighter, limit). */
    public const AMOUNT_MAX_LENGTH = 20;

    /**
     * A free-text box (a reason) holds at most this many characters: a longer text is a field error. 255, not 1000:
     * `payment_refunds.reason` and `.deduction_reason` are varchar(255), and a longer text would be a database
     * error (a 500) when the refund is written. Widening those columns is a migration, not part of this pass.
     */
    public const TEXT_MAX_LENGTH = 255;

    // ---- the components ------------------------------------------------------------------------

    /** The validation every amount box shares: blank is fine, anything typed must be an amount. */
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

    /**
     * The goods box of ONE returned line. Settled: prefilled (cancel) or following the quantity (return)
     * with the computed share, editable, with the room left on the line. Pending: the same figure,
     * read-only and never submitted (nothing was paid, §7.2.4).
     */
    public static function goodsField(OrderAdminSaleLineView $line, RefundFormContext $context, string $kind, RefundFormReader $reader): TextInput
    {
        $default = $kind === self::CANCEL ? $reader->computedShare($line, $line->remainingReturnable)?->decimalValue() : null;

        return TextInput::make("goods.{$line->id}")
            ->label(__('orders.refund_dialog.goods_label'))
            ->suffix($context->currency)
            ->inputMode('decimal')
            ->live(onBlur: true)
            ->default($default)
            ->disabled($context->isPending())
            ->dehydrated($context->isSettled())
            ->maxLength(self::AMOUNT_MAX_LENGTH)
            ->rules(self::amountRules($context->currency))
            ->helperText(match (true) {
                $context->isPending() => __('orders.refund_dialog.goods_readonly'),
                isset($context->lineRooms[$line->id]) => __('orders.refund_dialog.goods_hint', ['room' => self::format($context->lineRooms[$line->id])]),
                default => __('orders.refund_dialog.goods_hint_free'),
            });
    }

    /** A quantity box holds at most this many digits, so a 30-digit value cannot reach an (int) cast. */
    public const QUANTITY_MAX_LENGTH = 6;

    /**
     * The quantity box of a RETURN, on blur: a whole number above what remains becomes what remains, a negative or
     * non-numeric value becomes empty (0), and the goods box follows the CORRECTED quantity — it is never blank just
     * because the value was too high. A decimal is left as typed (the integer rule refuses it at submit — never
     * silently cut) and the goods box is cleared, since no share is computed for a quantity that is not whole.
     * maxValue() stays the submit-time guard and ReturnExceedsRemainingQuantityException the service's final one.
     */
    public static function followQuantity(TextInput $quantity, OrderAdminSaleLineView $line, RefundFormReader $reader): TextInput
    {
        return $quantity
            ->live(onBlur: true)
            ->afterStateUpdated(function (TextInput $component, Set $set) use ($line, $reader): void {
                // The RAW value as typed: the state a numeric box hands a hook (and Get) is already cast ('1.5' -> 1, 'abc' -> 0).
                $typed = $component->getRawState();
                $raw = is_scalar($typed) ? trim((string) $typed) : '';

                if (! preg_match('/^-?\d{1,'.self::QUANTITY_MAX_LENGTH.'}$/', $raw)) {
                    // Empty, a decimal, or text: a decimal stays for the integer rule; anything else is emptied.
                    if (! is_numeric($raw)) {
                        $set("quantity.{$line->id}", null);
                    }

                    $set("goods.{$line->id}", null);

                    return;
                }

                $quantity = (int) $raw;

                if ($quantity < 0) {
                    $set("quantity.{$line->id}", null);
                    $set("goods.{$line->id}", null);

                    return;
                }

                if ($quantity > $line->remainingReturnable) {
                    $quantity = $line->remainingReturnable;
                    $set("quantity.{$line->id}", $quantity);
                }

                $set("goods.{$line->id}", $reader->computedShare($line, $quantity)?->decimalValue());
            });
    }

    /**
     * Everything after the line blocks: shipping, deduction, channel, the notices and the live total.
     *
     * @param  list<OrderAdminSaleLineView>  $lines  the lines the dialog shows (remainingReturnable > 0)
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function moneySection(RefundFormContext $context, array $lines, string $kind, RefundFormReader $reader): array
    {
        if ($context->mode === RefundFormContext::NONE) {
            return [];
        }

        $currency = $context->currency;
        $components = [];

        if ($context->isPending()) {
            // Nothing is refunded: only a partial RETURN may reduce the shipping the customer still owes
            // (a cancel is always "everything", which voids the payment — the reduction would be moot).
            $components[] = Text::make(__('orders.refund_dialog.pending_total'));

            if ($kind === self::RETURN && $context->shippingRoom !== null && $context->shippingRoom->isPositive()) {
                $components[] = TextInput::make('shipping')
                    ->label(__('orders.refund_dialog.shipping_reduction_label'))
                    ->suffix($currency)
                    ->inputMode('decimal')
                    ->default('0')
                    ->maxLength(self::AMOUNT_MAX_LENGTH)->rules(self::amountRules($currency))
                    ->helperText(__('orders.refund_dialog.shipping_reduction_hint', ['room' => self::format($context->shippingRoom)]));
            }

            return [Section::make(__('orders.refund_dialog.section'))->schema($components)->compact()];
        }

        // SETTLED.
        if ($context->hasNoChannel()) {
            return [Section::make(__('orders.refund_dialog.section'))->schema([
                Text::make(__('orders.refund_dialog.no_channel'))->color('danger'),
            ])->compact()];
        }

        $components[] = Grid::make(['default' => 1, 'sm' => 2])->schema([
            TextInput::make('shipping')
                ->label(__('orders.refund_dialog.shipping_label'))
                ->suffix($currency)
                ->inputMode('decimal')
                ->default('0')
                ->live(onBlur: true)
                ->maxLength(self::AMOUNT_MAX_LENGTH)->rules(self::amountRules($currency))
                ->helperText(__('orders.refund_dialog.shipping_hint', ['room' => self::format($context->shippingRoom)])),
            TextInput::make('deduction')
                ->label(__('orders.refund_dialog.deduction_label'))
                ->suffix($currency)
                ->inputMode('decimal')
                ->default('0')
                ->live(onBlur: true)
                ->maxLength(self::AMOUNT_MAX_LENGTH)->rules(self::amountRules($currency))
                ->helperText(__('orders.refund_dialog.deduction_hint')),
        ]);

        // The neutral notice (§7.2.1): the shipping is never added by itself and never suggested.
        if ($context->orderShipping->isPositive()) {
            $components[] = Text::make(__('orders.refund_dialog.shipping_not_included', ['amount' => self::format($context->orderShipping)]))
                ->visible(fn (Get $get): bool => (MoneyInput::parseOrZero((string) $get('shipping'), $currency) ?? Money::fromMinorUnits(1, $currency))->isZero());
        }

        $components[] = Textarea::make('deduction_reason')
            ->label(__('orders.refund_dialog.deduction_reason_label'))
            ->rows(2)
            ->maxLength(self::TEXT_MAX_LENGTH)
            ->visible(fn (Get $get): bool => self::isPositive($get('deduction'), $currency))
            ->required(fn (Get $get): bool => self::isPositive($get('deduction'), $currency));

        $components[] = Select::make('channel')
            ->label(__('orders.refund_dialog.channel_label'))
            ->options(collect($context->channels)->mapWithKeys(fn (RefundChannel $channel): array => [$channel->value => __('orders.refund_dialog.channel_options.'.$channel->value)])->all())
            ->default($context->defaultChannel?->value)
            ->required()
            // One possible channel: nothing to choose — the box is hidden, the value is still submitted.
            ->hidden(count($context->channels) < 2)
            ->dehydratedWhenHidden();

        $totalOf = function (Get $get) use ($lines, $kind, $reader, $context): Money {
            $value = static fn (string $path): mixed => $get($path);
            $goods = Money::zero($context->currency);

            foreach (self::returnedGoods($value, $lines, $kind, $context->currency, $reader) as $entry) {
                $goods = $goods->add($entry['entered'] ?? $entry['computed'] ?? Money::zero($context->currency));
            }

            return $goods
                ->add(MoneyInput::parseOrZero((string) $get('shipping'), $context->currency) ?? Money::zero($context->currency))
                ->subtract(MoneyInput::parseOrZero((string) $get('deduction'), $context->currency) ?? Money::zero($context->currency));
        };

        $components[] = Text::make(fn (Get $get): string => __('orders.refund_dialog.total', ['total' => self::format($totalOf($get))]))->weight('bold');
        $components[] = Text::make(__('orders.refund_dialog.still_refundable', ['room' => self::format($context->totalRoom)]));
        $components[] = Text::make(fn (): string => __('orders.refund_dialog.over_total', ['room' => self::format($context->totalRoom)]))
            ->color('danger')
            ->visible(fn (Get $get): bool => $totalOf($get)->subtract($context->totalRoom)->isPositive());

        return [Section::make(__('orders.refund_dialog.section'))->schema($components)->compact()];
    }

    /** The optional announced-return day: a calendar day, no time, no timezone (§7.2.6). Returns only. */
    public static function announcedDayField(): DatePicker
    {
        return DatePicker::make('announced_return_on')
            ->label(__('orders.refund_dialog.announced_label'))
            ->helperText(__('orders.refund_dialog.announced_help'))
            ->format('Y-m-d')
            // "Today" is the STORE's today, computed in the store timezone — not the app's (UTC): the picker
            // compares the typed 'Y-m-d' with this one as plain text, so it must be given in the merchant's calendar.
            ->maxDate(fn (): string => app(StoreTimezone::class)->today());
    }

    /**
     * The facts a shop's own policy depends on (§7.2.6): delivery date with the days since, and the
     * earlier returns. FACTS ONLY — never "late", "overdue" or "within the window". A never-delivered
     * order shows no numbers; zero and negative counts are printed as they are.
     */
    public static function factsPanel(string $orderId): Section
    {
        $facts = app(OrderReturnFactsReader::class)->forOrder($orderId);
        $zone = $facts->zone;
        $day = static fn (DateTimeImmutable $instant): string => $instant->setTimezone($zone)->format('Y-m-d');

        $lines = [];

        if ($facts->deliveredAt === null) {
            $lines[] = e(__('orders.refund_dialog.facts_not_delivered'));
        } else {
            $lines[] = e(__('orders.refund_dialog.facts_delivered', [
                'date' => $day($facts->deliveredAt),
                'days' => (string) $facts->daysSinceDelivery(new DateTimeImmutable('now')),
            ]));
        }

        foreach ($facts->returns as $return) {
            $line = __('orders.refund_dialog.facts_return', [
                'recorded' => $day($return->recordedAt),
                'announced' => $return->announcedOn ?? __('orders.refund_dialog.facts_announced_none'),
            ]);

            if ($return->daysFromDeliveryToRecorded !== null) {
                $line .= __('orders.refund_dialog.facts_return_days', [
                    'recorded' => (string) $return->daysFromDeliveryToRecorded,
                    'announced' => $return->daysFromDeliveryToAnnounced === null ? '—' : (string) $return->daysFromDeliveryToAnnounced,
                ]);
            }

            $lines[] = e($line);
        }

        return Section::make(__('orders.refund_dialog.facts_heading'))
            ->schema([Text::make(new HtmlString(implode('<br>', $lines)))])
            ->compact();
    }

    // ---- the submission ------------------------------------------------------------------------

    /**
     * What each returned line asks for, from a state reader (`$value('goods.12')`) — the live form and
     * the submitted data are read through the same function, so the total on screen is the total sent.
     *
     * @param  list<OrderAdminSaleLineView>  $lines
     * @return array<string, array{quantity: int, computed: ?Money, entered: ?Money, blank: bool, invalid: bool}>
     */
    public static function returnedGoods(Closure $value, array $lines, string $kind, string $currency, RefundFormReader $reader): array
    {
        $entries = [];

        foreach ($lines as $line) {
            $quantity = $kind === self::CANCEL ? $line->remainingReturnable : (int) ($value("quantity.{$line->id}") ?? 0);

            if ($quantity <= 0 || $quantity > $line->remainingReturnable) {
                continue;
            }

            $raw = $value("goods.{$line->id}");
            $entered = filled($raw) ? MoneyInput::parse((string) $raw, $currency) : null;

            $entries[(string) $line->id] = [
                'quantity' => $quantity,
                'computed' => $reader->computedShare($line, $quantity),
                'entered' => $entered,
                'blank' => ! filled($raw),
                'invalid' => filled($raw) && $entered === null,
            ];
        }

        return $entries;
    }

    /**
     * A submitted cancel / return as a RefundRequest. Settled: the entered goods amounts (a blank box is the
     * computed share, so it sends nothing for that line), shipping, deduction with its reason, the channel
     * (the chosen one, or the only one the staff member may use). Pending: only a shipping reduction on a
     * RETURN. The operation key travels with every shape.
     *
     * @param  list<OrderAdminSaleLineView>  $lines
     *
     * @throws InvalidArgumentException a typed amount that is not an amount (the form refuses it first)
     */
    public static function requestFrom(array $data, array $lines, RefundFormContext $context, string $kind, RefundFormReader $reader): RefundRequest
    {
        $key = $data['operation_key'] ?? null;
        $key = is_string($key) && $key !== '' ? $key : null;
        $announced = $kind === self::RETURN && filled($data['announced_return_on'] ?? null) ? (string) $data['announced_return_on'] : null;
        $currency = $context->currency;
        $amount = static fn (mixed $raw): Money => MoneyInput::parseOrZero(is_scalar($raw) ? (string) $raw : '', $currency)
            ?? throw new InvalidArgumentException('RefundDialog: a typed amount is not an amount.');

        if ($context->isPending()) {
            return new RefundRequest(
                shipping: $kind === self::RETURN ? $amount($data['shipping'] ?? null) : null,
                operationKey: $key,
                announcedReturnOn: $announced,
            );
        }

        if (! $context->isSettled()) {
            return new RefundRequest(operationKey: $key, announcedReturnOn: $announced);
        }

        $value = static fn (string $path): mixed => data_get($data, $path);
        $entered = [];

        foreach (self::returnedGoods($value, $lines, $kind, $currency, $reader) as $id => $entry) {
            if ($entry['invalid']) {
                throw new InvalidArgumentException('RefundDialog: a typed amount is not an amount.');
            }

            if ($entry['entered'] !== null) {
                $entered[$id] = $entry['entered'];
            }
        }

        $deduction = $amount($data['deduction'] ?? null);
        $channel = filled($data['channel'] ?? null) ? RefundChannel::from((string) $data['channel']) : $context->defaultChannel;

        return new RefundRequest(
            enteredGoodsByLine: $entered,
            shipping: $amount($data['shipping'] ?? null),
            deduction: $deduction,
            deductionReason: $deduction->isPositive() ? (string) ($data['deduction_reason'] ?? '') : null,
            channel: $channel,
            operationKey: $key,
            announcedReturnOn: $announced,
        );
    }

    // ---- the money-only dialog -----------------------------------------------------------------

    /** @return list<\Filament\Schemas\Components\Component> */
    public static function moneyOnlySchema(RefundFormContext $context): array
    {
        $currency = $context->currency;
        $zero = Money::zero($currency);
        $totalOf = static fn (Get $get): Money => (MoneyInput::parseOrZero((string) $get('shipping'), $currency) ?? $zero)
            ->add(MoneyInput::parseOrZero((string) $get('adjustment'), $currency) ?? $zero);

        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                TextInput::make('shipping')
                    ->label(__('orders.money_only.shipping'))
                    ->suffix($currency)
                    ->inputMode('decimal')
                    ->default('0')
                    ->live(onBlur: true)
                    ->maxLength(self::AMOUNT_MAX_LENGTH)->rules(self::amountRules($currency)),
                TextInput::make('adjustment')
                    ->label(__('orders.money_only.adjustment'))
                    ->suffix($currency)
                    ->inputMode('decimal')
                    ->default('0')
                    ->live(onBlur: true)
                    ->maxLength(self::AMOUNT_MAX_LENGTH)->rules(self::amountRules($currency)),
            ]),
            Textarea::make('reason')
                ->label(__('orders.money_only.reason'))
                ->rows(2)
                ->maxLength(self::TEXT_MAX_LENGTH)
                ->required(),
            Select::make('channel')
                ->label(__('orders.refund_dialog.channel_label'))
                ->options(collect($context->channels)->mapWithKeys(fn (RefundChannel $channel): array => [$channel->value => __('orders.refund_dialog.channel_options.'.$channel->value)])->all())
                ->default($context->defaultChannel?->value)
                ->required()
                ->hidden(count($context->channels) < 2)
                ->dehydratedWhenHidden(),
            Text::make(__('orders.money_only.hint', [
                'shipping' => self::format($context->shippingRoom),
                'total' => self::format($context->totalRoom),
            ])),
            Text::make(fn (Get $get): string => __('orders.money_only.total', ['total' => self::format($totalOf($get))]))->weight('bold'),
            Text::make(fn (): string => __('orders.refund_dialog.over_total', ['room' => self::format($context->totalRoom)]))
                ->color('danger')
                ->visible(fn (Get $get): bool => $context->totalRoom !== null && $totalOf($get)->subtract($context->totalRoom)->isPositive()),
            Hidden::make('operation_key')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private static function isPositive(mixed $raw, string $currency): bool
    {
        $money = MoneyInput::parseOrZero(is_scalar($raw) ? (string) $raw : '', $currency);

        return $money !== null && $money->isPositive();
    }

    private static function format(?Money $money): string
    {
        if ($money === null) {
            return '—';
        }

        return $money->decimalValue().' '.$money->currency()->code();
    }
}
