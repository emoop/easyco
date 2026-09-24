<?php

namespace App\Services;

use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Pricing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place every cross-table read for the read-only Orders admin
 * section lives — admin-panel-design.md §14, D5. `orders`/
 * `operational_sales_sale_lines`/`operational_sales_transactions`/
 * `operational_sales_clients`/`payments`/`promotion_redemptions` are
 * five separate packages that deliberately never reference each other's
 * Eloquent models (§0's own "package isolation" note — OrderModel has
 * no relations, and none are added here either); this class is the
 * app-layer composition point CLAUDE.md rule 9 sanctions for exactly
 * this kind of cross-domain read.
 *
 * TWO READ SHAPES, BOTH SNAPSHOT-ONLY (D2) — NEVER RE-RESOLVED THROUGH
 * CATALOG OR PRICING:
 *  - applyListAggregates(): adds the computed columns the Orders list
 *    page needs (client name, channel, item count, latest payment
 *    method/status) to a query Filament already built from OrderModel,
 *    the same "layer extra correlated-subquery selects onto the
 *    Resource's own query" shape ProductResource::table()'s own
 *    thumbnail_path subquery already establishes — one query for the
 *    whole page regardless of row count (D7), never an N+1 loop.
 *  - forOrder(): the single-order View page's full read, assembled from
 *    several small, targeted reads (never a loop over many rows, so the
 *    same query-count discipline does not apply the same way here — a
 *    handful of queries for exactly one order is the real, accepted
 *    cost, same posture ProductResource's own ViewProduct infolist
 *    already takes for a single record's price/stock entries).
 *    MEMOIZED PER INSTANCE AND BOUND scoped() (AppServiceProvider),
 *    same real reasoning as PriceDisplayFormatter's own identical
 *    posture: the View page's infolist has no single place to build
 *    every Section from one upfront read (each TextEntry/RepeatableEntry
 *    resolves its own state independently, via Filament's own per-
 *    component closure injection), so several of them call
 *    app(OrderAdminReader::class)->forOrder() with the SAME order id —
 *    without this, that would be several complete re-reads of the same
 *    order per page view.
 *
 * WHY SALE LINES ARE READ VIA A RAW QUERY, NOT
 * TransactionRepository::findByIdWithSaleLines() — A REAL, CONFIRMED
 * GAP FOUND WHILE BUILDING THIS, FLAGGED PER THIS TASK'S OWN
 * INSTRUCTION, NOT FIXED HERE: SaleLine's own constructor (via
 * assertProductNameAndSkuMatchType(), run unconditionally by BOTH
 * create() and reconstituteFromStorage() — confirmed against the
 * installed source) throws InvalidArgumentException for any SALE-type
 * line whose productName/sku is null. product_name/sku were only added
 * to operational_sales_sale_lines on 2026-09-17 (see that migration's
 * own docblock, and §0's own note that "older lines have NULL") — so
 * findByIdWithSaleLines() would genuinely crash reconstructing any
 * pre-2026-09-17 SALE line, the exact opposite of this admin page's own
 * D2/D8 requirements ("snapshot, never live", "fail-soft... never an
 * exception", "a NULL product_name/sku renders '—'"). Reading
 * operational_sales_sale_lines directly sidesteps that constructor
 * entirely — also a more literal reading of D2 itself ("come from
 * orders / operational_sales_sale_lines ONLY"), not an ad hoc
 * workaround.
 *
 * order_id/payments.order_id CASTING (D6) — payments.order_id is a
 * plain VARCHAR (payment-domain-design.md §6: the Order domain did not
 * exist yet when Payment was built), carrying its own real index
 * (pay_payments_order_id_index). Every comparison below casts
 * orders.id (the correlated, effectively-constant side, per row) to
 * CHAR — `CAST(orders.id AS CHAR)` — rather than touching
 * payments.order_id itself, so MySQL can still use that column's own
 * index for the lookup; casting the INDEXED column instead would defeat
 * it.
 */
final class OrderAdminReader
{
    /** @var array<string, ?OrderAdminOrderView> */
    private array $orderViewCache = [];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ClientRepository $clients,
        private readonly PaymentRepository $payments,
    ) {
    }

    /**
     * Adds the Orders list page's own computed columns to a query
     * Filament already built from OrderModel — mirrors
     * ProductResource::table()'s own ->modifyQueryUsing() shape exactly
     * (see this class's own docblock). Every added column is a single
     * correlated subquery, so the whole page still costs one query
     * regardless of row count (D7) — confirmed by a real query-count
     * test, not assumed.
     */
    public function applyListAggregates(Builder $query): Builder
    {
        return $query->addSelect([
            'client_name' => DB::table('operational_sales_clients')
                ->select('operational_sales_clients.name')
                ->whereColumn('operational_sales_clients.id', 'orders.client_id')
                ->limit(1),
            'channel' => DB::table('operational_sales_transactions')
                ->select('operational_sales_transactions.channel')
                ->whereColumn('operational_sales_transactions.id', 'orders.transaction_id')
                ->limit(1),
            // COALESCE: an order whose Transaction genuinely has zero
            // COMPLETED SALE lines (should not happen via Checkout, but
            // D8's own fail-soft posture applies here too) must show 0,
            // not a blank/NULL cell.
            'item_count' => DB::table('operational_sales_sale_lines')
                ->selectRaw('COALESCE(SUM(operational_sales_sale_lines.quantity), 0)')
                ->whereColumn('operational_sales_sale_lines.transaction_id', 'orders.transaction_id')
                ->where('operational_sales_sale_lines.type', SaleLineType::SALE->value)
                ->where('operational_sales_sale_lines.status', SaleLineStatus::COMPLETED->value)
                // These are raw DB::table() reads, not Eloquent — SaleLineModel's
                // own SoftDeletes global scope (softDeletes() migration column,
                // "never hard-deleted... historical record", that model's own
                // docblock) never applies here, so a soft-deleted line must be
                // excluded explicitly or it would double-count against its own
                // correction.
                ->whereNull('operational_sales_sale_lines.deleted_at'),
            'payment_method' => $this->latestPaymentColumnSubquery('method'),
            'payment_status' => $this->latestPaymentColumnSubquery('status'),
            'payment_attempt_count' => DB::table('payments')
                ->selectRaw('COUNT(*)')
                ->whereRaw('payments.order_id = CAST(orders.id AS CHAR)'),
        ]);
    }

    /**
     * D6: "the most recent payment row (by attempted_at, then id)".
     * MySQL's own DESC ordering already puts NULL attempted_at rows
     * last (NULL sorts lowest) — a still-unanswered attempt never
     * outranks an already-answered one under this same rule, matching
     * exactly what forOrder() below does via a real ORDER BY for the
     * single-order case, so list and view never disagree about which
     * payment is "the" one shown.
     */
    private function latestPaymentColumnSubquery(string $column): \Illuminate\Database\Query\Builder
    {
        return DB::table('payments')
            ->select("payments.{$column}")
            ->whereRaw('payments.order_id = CAST(orders.id AS CHAR)')
            ->orderByDesc('payments.attempted_at')
            ->orderByDesc('payments.id')
            ->limit(1);
    }

    /**
     * The single Order View page's full read. Returns null only when
     * the order id itself does not resolve — every OTHER piece of
     * missing/optional data (no payment row, no promotion, a pickup-
     * point order with no street fields) renders '—' inside the
     * returned DTO's own fields rather than another null/exception
     * (D8), so the View page itself never needs its own per-field
     * fallback logic.
     */
    public function forOrder(string $orderId): ?OrderAdminOrderView
    {
        if (array_key_exists($orderId, $this->orderViewCache)) {
            return $this->orderViewCache[$orderId];
        }

        $order = $this->orders->findById($orderId);

        if ($order === null) {
            return $this->orderViewCache[$orderId] = null;
        }

        $client = $this->clients->findById($order->clientId());
        $clientName = $client?->name() ?? '—';

        $channel = DB::table('operational_sales_transactions')
            ->where('id', $order->transactionId())
            ->value('channel') ?? '—';

        $lines = DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->where('type', SaleLineType::SALE->value)
            ->where('status', SaleLineStatus::COMPLETED->value)
            // Same reasoning as applyListAggregates()'s own item_count
            // subquery above — a raw DB::table() read, so SaleLineModel's
            // SoftDeletes scope does not apply automatically here.
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): OrderAdminSaleLineView => $this->buildLineView($row))
            ->all();

        $hasPromotionRedemption = DB::table('promotion_redemptions')
            ->where('order_id', $orderId)
            ->exists();

        // Same "by attempted_at, then id" rule as
        // latestPaymentColumnSubquery() above, applied directly (one
        // order, not a correlated subquery) — findById() afterward goes
        // through the real domain Payment (safe; unlike SaleLine,
        // Payment's own reconstitution has no similarly brittle
        // unconditional constructor check for older rows — confirmed
        // against its installed source).
        $latestPaymentId = DB::table('payments')
            ->where('order_id', $orderId)
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->limit(1)
            ->value('id');

        $latestPayment = $latestPaymentId !== null
            ? $this->payments->findById((string) $latestPaymentId)
            : null;

        $paymentAttemptCount = DB::table('payments')->where('order_id', $orderId)->count();

        return $this->orderViewCache[$orderId] = new OrderAdminOrderView(
            order: $order,
            clientName: $clientName,
            channel: $channel,
            lines: $lines,
            hasPromotionRedemption: $hasPromotionRedemption,
            latestPayment: $latestPayment,
            paymentAttemptCount: $paymentAttemptCount,
        );
    }

    /**
     * D3: unitPrice = amount_minor / quantity, asserted exact rather
     * than trusted — see OrderAdminSaleLineView's own docblock for why
     * this is provably exact for any line CheckoutLinePricer actually
     * produced, and why this still checks rather than assumes.
     */
    private function buildLineView(object $row): OrderAdminSaleLineView
    {
        $quantity = (int) $row->quantity;
        $amountMinor = (int) $row->amount_minor;
        $lineTotal = Money::fromMinorUnits($amountMinor, $row->amount_currency);
        $unitPrice = null;

        if ($quantity > 0 && $amountMinor % $quantity === 0) {
            $unitPrice = Money::fromMinorUnits(intdiv($amountMinor, $quantity), $row->amount_currency);
        } else {
            Log::warning('OrderAdminReader: sale line amount_minor is not evenly divisible by quantity; rendering unit price as unavailable.', [
                'sale_line_id' => $row->id,
                'amount_minor' => $amountMinor,
                'quantity' => $quantity,
            ]);
        }

        return new OrderAdminSaleLineView(
            productName: $row->product_name,
            sku: $row->sku,
            quantity: $quantity,
            lineTotal: $lineTotal,
            unitPrice: $unitPrice,
        );
    }
}
