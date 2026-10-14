<?php

namespace App\Services;

use App\Enums\OrderBlockType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Persistence\Eloquent\OrderModel;

/**
 * The «Съвпадения» card (cod-protection-design.md section 5, stage C2-B): earlier and later orders (this one excluded) that share an identity
 * key with the order being looked at. COMPUTED when the page is opened, never stored.
 *
 *  - STRONG keys: the phone, the email and the delivery place (an address or an office). WEAK key: the name, shown greyed as «възможно
 *    съвпадение» and never counted in the summary or the red line. THE IP IS NOT A MATCHING KEY: it is shared by whole households and offices and is
 *    anonymised after 30 days; it is for blocking only.
 *  - ONE query per key type, each by its index, newest first, LIMITED to the 10 most recent, so the card costs at most four small reads (plus
 *    the one block lookup for the red line, shared with the block buttons) however many orders match.
 *  - THE SUMMARY counts the distinct orders matched on a strong key and how many of them were cancelled or refunded («отказани»).
 *  - THE RED LINE appears only when a phone or an email this order SHARES with at least one other order is actively blocked: a name-only match
 *    never raises it, and nothing is read for it when neither key matched.
 *
 * Read-only, and gated like the order view (ORDER_VIEW): it shows only data the viewer can already open order by order.
 */
final class OrderMatchReader
{
    public const LIMIT = 10;

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    public function __construct(
        private readonly OrderBlockLookup $blocks,
    ) {
    }

    /**
     * @return array{
     *     orders: list<array{id: string, placed_at: mixed, name: string, status: string, keys: list<string>, weak: bool}>,
     *     strong: int, refused: int, weak: int, blocked: list<array{type: string, until: ?\DateTimeInterface}>
     * }
     */
    public function forOrder(OrderModel $record): array
    {
        $id = (string) $record->id;

        return $this->memo[$id] ??= $this->read($record);
    }

    /** @return array<string, mixed> */
    private function read(OrderModel $record): array
    {
        $own = ['phone' => $record->phone_key, 'email' => $record->email_key, 'place' => $record->place_key, 'name' => $record->name_key];
        $columns = ['phone' => 'phone_key', 'email' => 'email_key', 'place' => 'place_key', 'name' => 'name_key'];
        $found = [];

        foreach ($columns as $kind => $column) {
            if ($own[$kind] === null) {
                continue;
            }

            $rows = OrderModel::query()
                ->where($column, $own[$kind])
                ->where('id', '!=', $record->id)
                ->orderByDesc('placed_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get(['id', 'placed_at', 'recipient_first_name', 'recipient_last_name', 'status', 'phone_key', 'email_key', 'place_key', 'name_key']);

            foreach ($rows as $row) {
                $found[(string) $row->id] ??= $row;
            }
        }

        $orders = [];
        $strong = 0;
        $refused = 0;
        $weak = 0;
        $sharedPhone = false;
        $sharedEmail = false;

        foreach ($found as $row) {
            $keys = [];

            foreach ($columns as $kind => $column) {
                if ($own[$kind] !== null && $row->{$column} === $own[$kind]) {
                    $keys[] = $kind;
                }
            }

            $isWeak = ! array_intersect($keys, ['phone', 'email', 'place']);
            $sharedPhone = $sharedPhone || in_array('phone', $keys, true);
            $sharedEmail = $sharedEmail || in_array('email', $keys, true);

            if ($isWeak) {
                $weak++;
            } else {
                $strong++;
                $refused += in_array($row->status, [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value], true) ? 1 : 0;
            }

            $orders[] = [
                'id' => (string) $row->id,
                'placed_at' => $row->placed_at,
                'name' => trim($row->recipient_first_name.' '.$row->recipient_last_name),
                'status' => (string) $row->status,
                'keys' => $keys,
                'weak' => $isWeak,
            ];
        }

        // Strong matches first, each group newest first.
        usort($orders, static fn (array $a, array $b): int => [$a['weak'], $b['placed_at']->getTimestamp(), (int) $b['id']] <=> [$b['weak'], $a['placed_at']->getTimestamp(), (int) $a['id']]);

        $blocked = [];

        if ($sharedPhone || $sharedEmail) {
            foreach ([[OrderBlockType::PHONE, $sharedPhone], [OrderBlockType::EMAIL, $sharedEmail]] as [$type, $shared]) {
                $block = $shared ? $this->blocks->blockFor($record, $type) : null;

                if ($block !== null) {
                    $blocked[] = ['type' => $type->value, 'until' => $block->expires_at];
                }
            }
        }

        return ['orders' => $orders, 'strong' => $strong, 'refused' => $refused, 'weak' => $weak, 'blocked' => $blocked];
    }
}
