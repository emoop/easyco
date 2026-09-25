<?php

namespace App\Services;

use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Persistence\Eloquent\SaleLineMapper;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Pricing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
 * READ SHAPES, ALL SNAPSHOT-ONLY (D2) — NEVER RE-RESOLVED THROUGH
 * CATALOG OR PRICING:
 *  - The Orders list's quick filter (the order's LATEST payment method):
 *    adds ONE condition to the query Filament already built from
 *    OrderModel — still one query for the whole page regardless of row
 *    count (D7), never an N+1 loop. The list itself needs no computed
 *    column: it shows the order's own columns only, so the old
 *    applyListAggregates() correlated-subquery select list was removed
 *    together with the client/channel/item-count/latest-payment columns
 *    that used it.
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
     * The Orders list's payment-method filter options: the methods that
     * are some order's LATEST payment — the very same "latest payment
     * row" definition the filter itself matches on (D6), so every option
     * offered is guaranteed to match at least one order. A method that
     * only ever appeared on a superseded (earlier) attempt is deliberately
     * NOT offered.
     *
     * Built by selecting latestPaymentColumnSubquery() ITSELF — one
     * query, reused rather than re-derived: the correlated subquery is
     * evaluated once per order row and DISTINCT collapses the results.
     * NULL (an order with no payment row at all) is dropped in PHP rather
     * than in SQL, so the "latest" rule stays expressed in exactly one
     * place. Sorting is done in PHP too, so the read does not depend on
     * ordering by a select alias.
     *
     * Labels are not this class's concern — the Resource maps each value
     * through its own optionLabel().
     *
     * @return string[]
     */
    public function paymentMethodOptions(): array
    {
        return DB::table('orders')
            ->selectSub($this->latestPaymentColumnSubquery('method'), 'latest_payment_method')
            ->distinct()
            ->get()
            ->pluck('latest_payment_method')
            ->filter(fn (?string $method): bool => filled($method))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * D2's payment-method filter — BY THE SAME "latest payment row" RULE
     * (D6) forOrder() applies, reusing latestPaymentColumnSubquery()
     * itself rather than a second definition of "latest": an order whose
     * earlier attempt was method A and whose latest attempt is method B
     * matches B only, never A.
     */
    public function applyLatestPaymentMethodFilter(Builder $query, string $method): Builder
    {
        return $query->where($this->latestPaymentColumnSubquery('method'), '=', $method);
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
            // A raw DB::table() read, so SaleLineModel's own SoftDeletes
            // scope (softDeletes() column — "never hard-deleted...
            // historical record") does not apply automatically here; a
            // soft-deleted line must be excluded explicitly or a
            // correction would keep rendering next to its own original.
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
     *
     * STAGE 5: also maps the §3.13 sale-line snapshot columns into the
     * same view DTO — still one query, never a query per line (the row
     * object already carries every column). A half-populated money pair
     * is corruption, not legacy, and is made to fail loudly through
     * SaleLineMapper::moneyOrNull() — the SAME rule the write path's own
     * mapper enforces (D2), reused rather than copied. netPaidAmount is
     * resolved before the constructor call because isLegacy (D1) is
     * derived from it.
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

        $netPaidAmount = SaleLineMapper::moneyOrNull($row->net_paid_amount_minor, $row->net_paid_amount_currency, 'netPaidAmount');

        return new OrderAdminSaleLineView(
            productName: $row->product_name,
            sku: $row->sku,
            quantity: $quantity,
            lineTotal: $lineTotal,
            unitPrice: $unitPrice,
            regularUnitPrice: SaleLineMapper::moneyOrNull($row->regular_unit_price_minor, $row->regular_unit_price_currency, 'regularUnitPrice'),
            finalUnitPrice: SaleLineMapper::moneyOrNull($row->final_unit_price_minor, $row->final_unit_price_currency, 'finalUnitPrice'),
            promotionDiscountShare: SaleLineMapper::moneyOrNull($row->promotion_discount_share_minor, $row->promotion_discount_share_currency, 'promotionDiscountShare'),
            discretionaryDiscount: SaleLineMapper::moneyOrNull($row->discretionary_discount_minor, $row->discretionary_discount_currency, 'discretionaryDiscount'),
            netPaidAmount: $netPaidAmount,
            unitCost: SaleLineMapper::moneyOrNull($row->unit_cost_minor, $row->unit_cost_currency, 'unitCost'),
            soldAttributes: self::decodeSoldAttributes($row->sold_attributes, $netPaidAmount === null, (string) $row->id),
            isLegacy: $netPaidAmount === null,
        );
    }

    /**
     * `sold_attributes` is a JSON column read here through a raw
     * DB::table() query, so it arrives as the stored JSON string (or NULL
     * for a legacy row) — not as an already-decoded array, and not via
     * SaleLineModel's own array cast.
     *
     * LEGACY AND CORRUPT ARE DIFFERENT CASES — the same distinction D2
     * draws for the money pairs, applied to this column. A line written
     * before §3.13's snapshot migration has NULL here and NO attributes
     * were ever recorded, so [] is the correct answer (the same shape a
     * SIMPLE line legitimately has). A NON-legacy line is always written by
     * SaleLineSnapshotBuilder, which always stores a real JSON list ([] for
     * a SIMPLE line) — so NULL, '', invalid JSON, or JSON that is not a
     * list on such a line is corruption, not "no attributes", and is made
     * to fail loudly (§3.13 stage 5 D2's own posture) rather than silently
     * rendering a line with its attributes quietly missing.
     *
     * @return array<int, array{definitionId: string, definitionCode: string, definitionName: string, valueId: string, value: string}>
     *
     * @throws RuntimeException If a non-legacy line's snapshot is missing or malformed.
     */
    private static function decodeSoldAttributes(mixed $raw, bool $isLegacy, string $saleLineId): array
    {
        if ($isLegacy) {
            return is_array($raw) ? $raw : [];
        }

        if (is_array($raw)) {
            // Already decoded — a driver that hands a JSON column back as a
            // PHP array rather than the raw JSON string.
            if (array_is_list($raw)) {
                return $raw;
            }

            throw self::corruptSoldAttributes($saleLineId, 'not a list');
        }

        if (! is_string($raw) || $raw === '') {
            throw self::corruptSoldAttributes($saleLineId, $raw === null ? 'NULL' : 'empty');
        }

        // Decoded WITHOUT associative casting first: a JSON object must be
        // rejected as "not a list", and with assoc=true an empty object
        // would be indistinguishable from an empty array ([]).
        $shape = json_decode($raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw self::corruptSoldAttributes($saleLineId, 'invalid JSON');
        }

        if (! is_array($shape)) {
            throw self::corruptSoldAttributes($saleLineId, 'not a list');
        }

        return json_decode($raw, true);
    }

    private static function corruptSoldAttributes(string $saleLineId, string $reason): RuntimeException
    {
        return new RuntimeException(
            "OrderAdminReader: sale line \"{$saleLineId}\" is not a legacy line but its sold_attributes snapshot is missing or malformed ({$reason}) — ".
            'no legitimate write path produces that.'
        );
    }
}
