<?php

namespace App\Services;

use App\Enums\OrderBlockType;
use App\Models\OrderBlockModel;
use App\Settings\StoreCountry;
use Carbon\CarbonInterface;
use EasyCo\Order\IdentityKeys;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * THE ONE WRITER and the ONE active-block reader of `order_blocks` (cod-protection-design.md section 3, stage C2-B). A block stops CASH ON
 * DELIVERY (a payment method flagged `blockable`) for an IP, a phone or an email: never the whole site, and never automatically (staff decide).
 *
 *  - The identifier is reduced to its KEY by EasyCo\Order\IdentityKeys (the same class the matcher uses) before it is compared or stored; a value
 *    that has no key (a phone of fewer than nine digits, an unreadable IP) CANNOT be blocked and is refused loudly.
 *  - ACTIVE = not revoked and (permanent or not yet expired). At most ONE active block per (type, key): blocking an identifier that is already
 *    actively blocked EXTENDS / REPLACES that block (the new duration wins, the reason is kept unless a new one is given), never adds a row.
 *  - Duration: a number of days (1 to 3650; the admin form defaults to 180) or NULL = permanent.
 *  - Every block, extension and revocation is written to the activity log with the TYPE, the MASKED value, who and the duration, never the
 *    full IP (order-context-design.md section 7.2). The log itself is the merchant's opt-in setting; the row also records `created_by` and
 *    `revoked_by`.
 *  - THE RETENTION: prune() deletes a row that ENDED more than 30 days ago (revoked, or expired), so staff can still see "this was blocked
 *    until last week"; a permanent block stays until revoked.
 *
 * No permission check lives here (the project's services never have one): the admin actions and the list page gate on ORDER_MANAGE.
 */
final class OrderBlockService
{
    public const DEFAULT_DAYS = 180;

    public const MAX_DAYS = 3650;

    public const PRUNE_AFTER_DAYS = 30;

    public function __construct(
        private readonly ActivityLogger $audit,
        private readonly PanelStaffActor $staffActor,
    ) {
    }

    /** The key of a raw identifier of this type, or null when it has none (and so cannot be blocked or matched). */
    public static function keyFor(OrderBlockType $type, ?string $value): ?string
    {
        return match ($type) {
            // A phone typed by hand is read with the STORE's country (an order's own phone is already E.164): stage PH-1.
            OrderBlockType::PHONE => $value === null ? null : PhoneNumbers::identityKey($value, app(StoreCountry::class)->currentOrNull()),
            OrderBlockType::EMAIL => IdentityKeys::email($value),
            OrderBlockType::IP => IdentityKeys::ip($value),
        };
    }

    /**
     * Blocks an identifier (or extends the block it already has). `$days` NULL = permanent.
     *
     * @throws InvalidArgumentException the value has no key, or the duration is out of range
     */
    public function block(OrderBlockType $type, string $value, ?int $days, ?string $reason = null, ?string $sourceOrderId = null, ?CarbonInterface $now = null): OrderBlockModel
    {
        $now ??= Date::now();
        $key = self::keyFor($type, $value);

        if ($key === null) {
            throw new InvalidArgumentException('That value cannot be blocked: it has no usable key.');
        }

        $expiresAt = $this->expiryFor($days, $now);
        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($type, $value, $key, $expiresAt, $reason, $sourceOrderId, $now): OrderBlockModel {
            $existing = OrderBlockModel::query()->active($now)->where('type', $type->value)->where('value_key', $key)->lockForUpdate()->first();

            if ($existing !== null) {
                $before = $this->describe($existing);
                $existing->update([
                    'expires_at' => $expiresAt,
                    'value_display' => $value,
                    'reason' => $reason ?? $existing->reason,
                ]);
                $this->audit->logFieldChanged('order_block', (string) $existing->id, 'extended', $before, $this->describe($existing));

                return $existing;
            }

            $block = OrderBlockModel::query()->create([
                'type' => $type->value,
                'value_key' => $key,
                'value_display' => $value,
                'reason' => $reason,
                'source_order_id' => $sourceOrderId === null ? null : (int) $sourceOrderId,
                'created_by' => $this->staffActor->current()?->id,
                'expires_at' => $expiresAt,
            ]);
            $this->audit->logFieldChanged('order_block', (string) $block->id, 'blocked', null, $this->describe($block));

            return $block;
        });
    }

    /** Replaces the end of a block that is still active: a number of days from now, or NULL = permanent. */
    public function extend(OrderBlockModel $block, ?int $days, ?CarbonInterface $now = null): OrderBlockModel
    {
        $now ??= Date::now();

        if (! $block->isActive($now)) {
            throw new InvalidArgumentException('Only an active block can be extended.');
        }

        $before = $this->describe($block);
        $block->update(['expires_at' => $this->expiryFor($days, $now)]);
        $this->audit->logFieldChanged('order_block', (string) $block->id, 'extended', $before, $this->describe($block));

        return $block;
    }

    public function revoke(OrderBlockModel $block, ?CarbonInterface $now = null): void
    {
        $now ??= Date::now();

        if ($block->revoked_at !== null) {
            return;
        }

        $before = $this->describe($block);
        $block->update(['revoked_at' => $now, 'revoked_by' => $this->staffActor->current()?->id]);
        $this->audit->logFieldChanged('order_block', (string) $block->id, 'revoked', $before, null);
    }

    /**
     * THE ACTIVE BLOCKS that match any of these keys, in ONE query (none at all when there is no key to ask about).
     *
     * @return Collection<int, OrderBlockModel>
     */
    public function activeFor(?string $ipKey, ?string $phoneKey, ?string $emailKey, ?CarbonInterface $now = null): Collection
    {
        $pairs = array_filter(
            [OrderBlockType::IP->value => $ipKey, OrderBlockType::PHONE->value => $phoneKey, OrderBlockType::EMAIL->value => $emailKey],
            static fn (?string $key): bool => $key !== null,
        );

        if ($pairs === []) {
            return new Collection();
        }

        return OrderBlockModel::query()
            ->active($now ?? Date::now())
            ->where(function ($query) use ($pairs): void {
                foreach ($pairs as $type => $key) {
                    $query->orWhere(fn ($inner) => $inner->where('type', $type)->where('value_key', $key));
                }
            })
            ->get();
    }

    /** Deletes the rows that ended more than PRUNE_AFTER_DAYS ago (revoked, or expired); permanent and recent ones stay. */
    public function prune(?CarbonInterface $now = null): int
    {
        $cutoff = Carbon::instance($now ?? Date::now())->copy()->subDays(self::PRUNE_AFTER_DAYS);

        return OrderBlockModel::query()
            ->where(fn ($query) => $query->where('revoked_at', '<', $cutoff)->orWhere(fn ($inner) => $inner->whereNotNull('expires_at')->where('expires_at', '<', $cutoff)))
            ->delete();
    }

    /** "phone *** *** 456 · until 2026-12-01" / "· permanent": the MASKED, loggable description of a block. */
    public function describe(OrderBlockModel $block): string
    {
        $type = OrderBlockType::from($block->type);

        return $type->value.' '.OrderBlockMasker::mask($type, (string) $block->value_display).' · '.($block->expires_at === null ? 'permanent' : 'until '.$block->expires_at->format('Y-m-d'));
    }

    private function expiryFor(?int $days, CarbonInterface $now): ?CarbonInterface
    {
        if ($days === null) {
            return null;
        }

        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException('A block lasts from 1 to '.self::MAX_DAYS.' days, or forever.');
        }

        return Carbon::instance($now)->copy()->addDays($days);
    }

    private function cleanReason(?string $reason): ?string
    {
        $reason = $reason === null ? null : trim($reason);

        return $reason === null || $reason === '' ? null : mb_substr($reason, 0, 500);
    }
}
