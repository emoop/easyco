<?php

namespace App\Services;

use App\Settings\StoreLocale;
use App\Storefront\Orders\OrderReceivedReader;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Money;
use Illuminate\Support\Facades\DB;

/**
 * ACC-2: what a customer sees of HER OWN orders. The list is read from `orders` (one page query + one count + one grouped query for the item counts: constant in
 * the number of orders). The detail is built on the SAME reader as the order-received page and the confirmation mail (OrderReceivedReader: lines, totals,
 * discount, shipping, destination, payment instructions, the customer's note), plus the few facts that page does not carry (status, unit prices, the tracking number).
 *
 * NEVER in an answer: staff notes, the history / audit trail, block / fraud flags, IP, user agent, cost prices or profit, internal ids other than the order `number`.
 * Every query filters by the account: another account's order, a guest order and a number that does not exist are the same «not found» (null).
 */
final class AccountOrderReader
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly OrderReceivedReader $received,
        private readonly TransactionRepository $transactions,
        private readonly PriceDisplayFormatter $prices,
        private readonly StoreLocale $locale,
    ) {
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function list(AccountModel $account, int $page): array
    {
        $paginator = OrderModel::query()
            ->where('account_id', $account->getKey())
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'status', 'placed_at', 'currency', 'total_minor', 'transaction_id'], 'page', max(1, $page));

        $counts = $paginator->isEmpty() ? collect() : DB::table('operational_sales_sale_lines')
            ->whereIn('transaction_id', $paginator->getCollection()->pluck('transaction_id'))
            ->where('type', SaleLineType::SALE->value)
            ->where('status', '!=', SaleLineStatus::CANCELLED->value)
            ->whereNull('deleted_at')
            ->groupBy('transaction_id')
            ->selectRaw('transaction_id, SUM(quantity) AS items')
            ->pluck('items', 'transaction_id');

        $data = $paginator->getCollection()->map(fn (OrderModel $order): array => [
            'number' => (string) $order->id,
            'placed_at' => $order->placed_at->toIso8601String(),
            'status' => (string) $order->status,
            'status_label' => $this->statusLabel((string) $order->status),
            'total' => $this->money((int) $order->total_minor, (string) $order->currency),
            'total_minor' => (int) $order->total_minor,
            'currency' => (string) $order->currency,
            'items_count' => (int) ($counts[$order->transaction_id] ?? 0),
        ])->values()->all();

        return ['data' => $data, 'meta' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]];
    }

    /** @return array<string, mixed>|null null = not found (not hers, a guest order, unknown) */
    public function detail(AccountModel $account, string $number): ?array
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $number) !== 1) {
            return null;
        }

        $row = OrderModel::query()
            ->where('account_id', $account->getKey())
            ->whereKey($number)
            ->first(['id', 'status', 'placed_at', 'currency', 'total_minor', 'transaction_id', 'tracking_number', 'shipping_courier']);

        $shown = $row === null ? null : $this->received->find($number);

        if ($row === null || $shown === null) {
            return null;
        }

        $units = $this->unitPrices((int) $row->transaction_id);
        $lines = [];

        foreach ($shown['lines'] as $i => $line) {
            $lines[] = [
                'name' => $line['name'],
                'attributes' => $line['label'],
                'quantity' => $line['quantity'],
                'unit_price' => $units[$i] ?? null,
                'line_total' => $line['total'],
            ];
        }

        $method = $shown['payment']['method'];
        $methodLabel = (string) __('storefront.payment_methods.'.$method, [], $this->locale->current());

        return [
            'number' => $shown['number'],
            'placed_at' => $row->placed_at->toIso8601String(),
            'status' => (string) $row->status,
            'status_label' => $this->statusLabel((string) $row->status),
            'currency' => (string) $row->currency,
            'total_minor' => (int) $row->total_minor,
            'lines' => $lines,
            'totals' => [
                'subtotal' => $shown['subtotal'],
                'discount' => $shown['discount'],
                'shipping' => $shown['shipping'],
                'total' => $shown['total'],
            ],
            'delivery' => ['method' => $shown['shipping']['name'] ?? null] + $shown['destination'],
            'payment' => [
                'method' => $method,
                'method_label' => $methodLabel === 'storefront.payment_methods.'.$method ? $method : $methodLabel,
                'bank' => $shown['payment']['bank'],
                'cash_on_delivery_due' => $shown['payment']['cash_on_delivery_due'],
            ],
            'tracking' => filled($row->tracking_number) ? ['number' => (string) $row->tracking_number, 'courier' => filled($row->shipping_courier) ? (string) $row->shipping_courier : null] : null,
            'note' => $shown['note'],
        ];
    }

    private function money(int $minor, string $currency): string
    {
        $money = Money::fromMinorUnits($minor, $currency);

        return $this->prices->format($money->decimalValue(), $money->currency());
    }

    private function statusLabel(string $status): string
    {
        $key = 'orders.status_options.'.$status;
        $label = (string) __($key, [], $this->locale->current());

        return $label === $key ? $status : $label;
    }

    /** the formatted unit price of each counted sale line, in the order OrderReceivedReader lists them (same filter) @return list<?string> */
    private function unitPrices(int $transactionId): array
    {
        $prices = [];

        foreach ($this->transactions->findByIdWithSaleLines((string) $transactionId)?->saleLines() ?? [] as $line) {
            if ($line->type() !== SaleLineType::SALE || $line->status() === SaleLineStatus::CANCELLED) {
                continue;
            }

            $unit = $line->finalUnitPrice();
            $prices[] = $unit === null ? null : $this->prices->format($unit->decimalValue(), $unit->currency());
        }

        return $prices;
    }
}
