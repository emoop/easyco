<?php

namespace App\Services;

use App\Enums\OrderBlockType;
use App\Models\OrderBlockModel;
use EasyCo\Order\IdentityKeys;
use EasyCo\Order\Persistence\Eloquent\OrderModel;

/**
 * The ACTIVE blocks that touch ONE order on the admin page (cod-protection-design.md 3.2, 5), read ONCE per order per request: the block buttons
 * next to the phone, the email and the IP, and the red line of the «Съвпадения» card all share this one answer (ONE query, none when the order
 * has no key to ask about).
 *
 * THE IP KEY comes only from OrderContextReader::fullClientAddress(), which answers only for ORDER_MANAGE and only while the address is not
 * anonymised: a viewer without that permission never causes the address to be read, and sees the phone and email blocks only.
 */
final class OrderBlockLookup
{
    /** @var array<string, array<string, OrderBlockModel>> order id => type value => the active block */
    private array $memo = [];

    public function __construct(
        private readonly OrderBlockService $blocks,
    ) {
    }

    /** @return array<string, OrderBlockModel> the active blocks by type value (`ip`, `phone`, `email`); a type with none is absent */
    public function forOrder(OrderModel $record): array
    {
        $id = (string) $record->id;

        if (! array_key_exists($id, $this->memo)) {
            $ipKey = IdentityKeys::ip(app(OrderContextReader::class)->fullClientAddress($id));
            $found = [];

            foreach ($this->blocks->activeFor($ipKey, $record->phone_key, $record->email_key) as $block) {
                $found[$block->type] = $block;
            }

            $this->memo[$id] = $found;
        }

        return $this->memo[$id];
    }

    public function blockFor(OrderModel $record, OrderBlockType $type): ?OrderBlockModel
    {
        return $this->forOrder($record)[$type->value] ?? null;
    }

    /** A block was made, extended or revoked on this order: the next read asks again. */
    public function forget(OrderModel|string $order): void
    {
        unset($this->memo[(string) ($order instanceof OrderModel ? $order->id : $order)]);
    }
}
