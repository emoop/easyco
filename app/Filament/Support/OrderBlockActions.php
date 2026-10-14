<?php

namespace App\Filament\Support;

use App\Enums\OrderBlockType;
use App\Filament\Resources\OrderResource;
use App\Rules\PlainText;
use App\Services\OrderBlockLookup;
use App\Services\OrderBlockService;
use App\Services\OrderContextReader;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * The «Блокирай» / «Отблокирай» buttons next to the phone, the email and the IP of an order (cod-protection-design.md 3.2, stage C2-B), and the
 * pieces the «Блокирания» list shares with them (the duration form, the permission, the state text).
 *
 * EVERY ONE of them needs ORDER_MANAGE (visible AND re-checked when mounted: Filament evaluates ->visible() again at click time), and the IP one
 * additionally needs the address itself, which only OrderContextReader::fullClientAddress() gives, and only to ORDER_MANAGE: a viewer without that
 * permission is never even asked for it. The modal says plainly that only cash on delivery is stopped. Every action goes through
 * OrderBlockService (one writer; the activity-log row with the MASKED value is its job).
 */
final class OrderBlockActions
{
    public const CUSTOM = 'custom';

    public const FOREVER = 'forever';

    public static function mayManage(): bool
    {
        return OrderResource::staffHasPermission(Permission::ORDER_MANAGE);
    }

    /** The «Блокирай» button of one identifier of an order. */
    public static function block(OrderBlockType $type): Action
    {
        return Action::make('block_'.$type->value)
            ->label(__('blocks.actions.block'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->size('xs')
            ->modalHeading(__('blocks.actions.block_heading', ['type' => mb_strtolower(__('blocks.type_options.'.$type->value))]))
            ->modalDescription(__('blocks.form.note'))
            ->modalSubmitActionLabel(__('blocks.actions.block'))
            ->schema(self::durationFields())
            ->visible(fn (OrderModel $record): bool => self::mayManage() && self::hasValue($record, $type) && app(OrderBlockLookup::class)->blockFor($record, $type) === null)
            ->action(function (OrderModel $record, array $data) use ($type): void {
                $value = self::valueOf($record, $type);

                try {
                    if ($value === null) {
                        throw new InvalidArgumentException('no value');
                    }

                    app(OrderBlockService::class)->block($type, $value, self::daysFrom($data), $data['reason'] ?? null, (string) $record->id);
                } catch (InvalidArgumentException) {
                    Notification::make()->title(__('blocks.notice.cannot_block'))->danger()->send();

                    return;
                } finally {
                    app(OrderBlockLookup::class)->forget($record);
                }

                Notification::make()->title(__('blocks.notice.blocked'))->success()->send();
            });
    }

    /** The «Отблокирай» button: only while that identifier is actively blocked. */
    public static function unblock(OrderBlockType $type): Action
    {
        return Action::make('unblock_'.$type->value)
            ->label(__('blocks.actions.unblock'))
            ->icon('heroicon-m-lock-open')
            ->color('gray')
            ->size('xs')
            ->requiresConfirmation()
            ->modalHeading(__('blocks.actions.unblock_heading', ['type' => mb_strtolower(__('blocks.type_options.'.$type->value))]))
            ->modalDescription(__('blocks.actions.unblock_description', ['type' => mb_strtolower(__('blocks.type_options.'.$type->value))]))
            ->visible(fn (OrderModel $record): bool => self::mayManage() && app(OrderBlockLookup::class)->blockFor($record, $type) !== null)
            ->action(function (OrderModel $record) use ($type): void {
                $block = app(OrderBlockLookup::class)->blockFor($record, $type);

                if ($block !== null) {
                    app(OrderBlockService::class)->revoke($block);
                }

                app(OrderBlockLookup::class)->forget($record);
                Notification::make()->title(__('blocks.notice.unblocked'))->success()->send();
            });
    }

    /** "Blocked until <date>" / "Blocked permanently" / "Not blocked" for the state line next to an identifier. */
    public static function stateText(OrderModel $record, OrderBlockType $type): string
    {
        $block = app(OrderBlockLookup::class)->blockFor($record, $type);

        if ($block === null) {
            return (string) __('blocks.not_blocked');
        }

        return $block->expires_at === null
            ? (string) __('blocks.blocked_forever')
            : (string) __('blocks.blocked_until', ['date' => $block->expires_at->timezone(\Filament\Support\Facades\FilamentTimezone::get())->format('M j, Y')]);
    }

    /** @return list<\Filament\Schemas\Components\Component> the duration and the reason: 180 days (default) / forever / another number of days */
    public static function durationFields(): array
    {
        return [
            Radio::make('duration')
                ->label(__('blocks.form.duration'))
                ->options([
                    '180' => __('blocks.form.days_180'),
                    self::FOREVER => __('blocks.form.forever'),
                    self::CUSTOM => __('blocks.form.custom'),
                ])
                ->default('180')
                ->required()
                ->live(),
            TextInput::make('custom_days')
                ->label(__('blocks.form.custom_days'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(OrderBlockService::MAX_DAYS)
                ->visible(fn ($get): bool => $get('duration') === self::CUSTOM)
                ->required(fn ($get): bool => $get('duration') === self::CUSTOM),
            Textarea::make('reason')
                ->label(__('blocks.form.reason'))
                ->rows(2)
                ->maxLength(500)
                ->rules([new PlainText()]),
        ];
    }

    /** The number of days the form asked for; NULL = forever. @param array<string, mixed> $data */
    public static function daysFrom(array $data): ?int
    {
        return match ($data['duration'] ?? '180') {
            self::FOREVER => null,
            self::CUSTOM => (int) ($data['custom_days'] ?? OrderBlockService::DEFAULT_DAYS),
            default => (int) $data['duration'],
        };
    }

    /** Is there something to block? A short phone has no key; the IP needs the address, which only ORDER_MANAGE may read. */
    private static function hasValue(OrderModel $record, OrderBlockType $type): bool
    {
        return match ($type) {
            OrderBlockType::PHONE => $record->phone_key !== null,
            OrderBlockType::EMAIL => $record->email_key !== null,
            OrderBlockType::IP => self::valueOf($record, $type) !== null,
        };
    }

    private static function valueOf(OrderModel $record, OrderBlockType $type): ?string
    {
        return match ($type) {
            OrderBlockType::PHONE => $record->phone,
            OrderBlockType::EMAIL => $record->email,
            OrderBlockType::IP => app(OrderContextReader::class)->fullClientAddress((string) $record->id),
        };
    }
}
