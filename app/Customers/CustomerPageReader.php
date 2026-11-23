<?php

namespace App\Customers;

use App\Enums\OrderBlockType;
use App\Filament\Resources\OrderResource;
use App\Models\OrderBlockModel;
use App\Privacy\OrderConsents;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyNotConfigured;
use App\Services\OrderBlockService;
use App\Services\PriceDisplayFormatter;
use App\Settings\StoreCountry;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md D5, §7): everything the admin customer page shows, read in a CONSTANT number of queries whatever the number of orders, addresses or phones:
 * the latest orders + the totals (2), the account (1), its addresses (1), the consents (1), the note (1), the phones (1), the active blocks (1), the privacy requests (1).
 * Nothing here writes.
 *
 * The privacy parts need PRIVACY_HASH_KEY (the requests are found by `email_hash`): without it outside local and testing `privacyReady` is false, the requests are not read, and the page still renders.
 * NO clear email or phone of a PRIVACY REQUEST is ever selected (the requests card shows kind, status, dates and the staff name only).
 *
 * The lists come from the filter `privacy.customer.lists` (default empty). Filters are not contained by the hook registry, so a filter that throws is caught HERE and logged by class only.
 */
final class CustomerPageReader
{
    public const FILTER = 'privacy.customer.lists';

    public const ORDERS_SHOWN = 20;

    public const CONSENTS_SHOWN = 50;

    public const REQUESTS_SHOWN = 20;

    public const PHONES_SHOWN = 30;

    public function __construct(
        private readonly PrivacyHash $hash,
        private readonly OrderBlockService $blocks,
        private readonly PriceDisplayFormatter $money,
    ) {
    }

    /** @return array<string, mixed> */
    public function read(CustomerIdentity $who): array
    {
        $emailKey = $who->emailKey;

        $recent = DB::table('orders')->where('email_key', $emailKey)->orderByDesc('id')->limit(self::ORDERS_SHOWN)
            ->get(['id', 'placed_at', 'status', 'total_minor', 'currency', 'recipient_first_name', 'recipient_last_name', 'email', 'phone']);
        $totals = DB::table('orders')->where('email_key', $emailKey)
            ->selectRaw('COUNT(*) AS orders_count, COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 0 ELSE total_minor END), 0) AS spent_minor', array_map(static fn ($s): string => $s->value, CustomerListQuery::NOT_SPENT))
            ->first();
        $account = AccountModel::query()->where('email', $emailKey)->first(['id', 'email', 'created_at']);
        $addresses = $account === null ? collect() : DB::table('addresses')->where('account_id', $account->getKey())->orderByDesc('default_marker')->orderBy('id')->limit(50)->get();
        $consents = DB::table('orders')->where('email_key', $emailKey)->orderByDesc('id')->limit(self::CONSENTS_SHOWN)->get(OrderConsents::COLUMNS);
        $note = DB::table('customer_notes')->where('email_key', $emailKey)->value('body');
        $phones = $this->phones($emailKey, $account === null ? null : (int) $account->getKey());
        $latest = $recent->first();
        $currency = $latest->currency ?? null;

        $privacyReady = true;
        $requests = collect();

        try {
            $emailHash = (string) $this->hash->email($emailKey);
            $requests = PrivacyRequestModel::query()->where('email_hash', $emailHash)->orderByDesc('id')->limit(self::REQUESTS_SHOWN)
                ->get(['id', 'kind', 'status', 'source', 'staff_name', 'created_at', 'confirmed_at', 'completed_at', 'export_path', 'export_expires_at']);
        } catch (PrivacyNotConfigured) {
            $privacyReady = false;
            $emailHash = null;
        }

        $phoneKeys = array_values(array_filter(array_map(static fn (array $phone): ?string => $phone['key'], $phones)));
        $active = $this->blocks->activeForPerson($emailKey, $phoneKeys);

        return [
            'person' => [
                'name' => $latest === null ? null : (trim($latest->recipient_first_name.' '.$latest->recipient_last_name) ?: null),
                'email' => $latest->email ?? $emailKey,
                'phone' => $latest->phone ?? ($phones[0]['display'] ?? null),
                'has_account' => $account !== null,
                'account_since' => $account?->created_at,
            ],
            'addresses' => $addresses->map(static fn (object $row): array => [
                'name' => trim($row->recipient_first_name.' '.$row->recipient_last_name),
                'phone' => $row->phone,
                'default' => (bool) $row->default_marker,
                'lines' => array_values(array_filter([$row->address_line_1, $row->address_line_2, trim($row->postal_code.' '.$row->city), $row->region, $row->country, $row->pickup_point_name, $row->pickup_point_address], static fn ($v): bool => is_string($v) && trim($v) !== '')),
            ])->all(),
            'orders' => $recent->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'placed_at' => Carbon::parse($row->placed_at),
                'status' => $row->status,
                'status_label' => $this->statusLabel((string) $row->status),
                'status_color' => OrderResource::statusColor((string) $row->status),
                'total' => $this->format((int) $row->total_minor, (string) $row->currency),
            ])->all(),
            'orders_count' => (int) ($totals->orders_count ?? 0),
            'spent' => $currency === null ? null : $this->format((int) ($totals->spent_minor ?? 0), (string) $currency),
            'consents' => $consents->map(static fn (object $row): array => OrderConsents::row($row))->all(),
            'note' => is_string($note) ? $note : '',
            'phones' => $phones,
            'blocks' => [
                'email' => $this->blockOf($active, OrderBlockType::EMAIL, $emailKey),
                'phones' => array_map(fn (array $phone): array => $phone + ['block' => $phone['key'] === null ? null : $this->blockOf($active, OrderBlockType::PHONE, $phone['key'])], $phones),
            ],
            'lists' => $this->lists($emailKey),
            'privacy_ready' => $privacyReady,
            'requests' => $requests,
            'processing' => $requests->contains(fn (PrivacyRequestModel $row): bool => $row->status === PrivacyRequestModel::STATUS_PROCESSING),
        ];
    }

    /**
     * Every phone of the person, deduplicated by the KEY the blocks use (OrderBlockService::keyFor): the phones of the account's addresses and of the orders with this email key, in ONE query.
     * A phone with no usable key is shown (key null) and cannot be blocked.
     *
     * @return list<array{display: string, key: ?string}>
     */
    public function phones(string $emailKey, ?int $accountId): array
    {
        $query = DB::table('orders')->where('email_key', $emailKey)->whereNotNull('phone')->where('phone', '!=', '')->select('phone')->distinct();

        if ($accountId !== null) {
            $query->union(DB::table('addresses')->where('account_id', $accountId)->whereNotNull('phone')->where('phone', '!=', '')->select('phone')->distinct());
        }

        $phones = [];

        foreach ($query->limit(self::PHONES_SHOWN)->pluck('phone') as $value) {
            $key = OrderBlockService::keyFor(OrderBlockType::PHONE, (string) $value);
            $phones[$key ?? 'raw:'.$value] ??= ['display' => (string) $value, 'key' => $key];
        }

        return array_values($phones);
    }

    /** @return array{until: ?Carbon, forever: bool, reason: ?string, order: ?int}|null the active block of this type and key, if any */
    private function blockOf(\Illuminate\Support\Collection $active, OrderBlockType $type, string $key): ?array
    {
        /** @var OrderBlockModel|null $block */
        $block = $active->first(static fn (OrderBlockModel $block): bool => $block->type === $type->value && $block->value_key === $key);

        return $block === null ? null : [
            'id' => (int) $block->getKey(),
            'forever' => $block->expires_at === null,
            'until' => $block->expires_at,
            'reason' => $block->reason,
            'order' => $block->source_order_id === null ? null : (int) $block->source_order_id,
        ];
    }

    /** @return list<array{title: string, items: list<string>}> */
    private function lists(string $emailKey): array
    {
        try {
            $result = Hook::apply(self::FILTER, [], $emailKey);
        } catch (Throwable $e) {
            // never the email: the class and where, only
            Log::error('privacy.customer_lists_failed', ['filter' => self::FILTER, 'exception' => $e::class]);

            return [];
        }

        if (! is_array($result)) {
            return [];
        }

        $lists = [];

        foreach ($result as $list) {
            if (is_array($list) && isset($list['title']) && is_string($list['title']) && isset($list['items']) && is_array($list['items'])) {
                $lists[] = ['title' => $list['title'], 'items' => array_values(array_filter($list['items'], 'is_string'))];
            }
        }

        return $lists;
    }

    private function statusLabel(string $status): string
    {
        return Lang::has('orders.status_options.'.$status) ? (string) __('orders.status_options.'.$status) : $status;
    }

    private function format(int $minor, string $currency): string
    {
        return $this->money->format(Money::fromMinorUnits($minor, $currency)->decimalValue(), Currency::of($currency));
    }
}
