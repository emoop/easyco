<?php

namespace App\Services;

use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Persistence\Eloquent\SaleLineMapper;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Pricing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use DateTimeImmutable;
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
 *    that used it. "Latest payment" here MEANS the CURRENT payment —
 *    the newest payments row for the order by attempted_at DESC, id DESC
 *    whose voided_at is NULL (order-lifecycle-design.md §7.3) — and
 *    forOrder() below applies the identical rule, so the list's filter
 *    and the View page can never disagree about which payment an order's
 *    obligations live on.
 *  - forOrder(): the single-order View page's full read, assembled from
 *    several small, targeted reads (never a loop over many rows, so the
 *    same query-count discipline does not apply the same way here — a
 *    handful of queries for exactly one order is the real, accepted
 *    cost, same posture ProductResource's own ViewProduct infolist
 *    already takes for a single record's price/stock entries). The
 *    order's own history is one more of those targeted reads —
 *    `order_events`, ONE query, ordered oldest first and tie-broken by
 *    id (order-lifecycle-design.md §6.3, §10 stage 3) — and the Orders
 *    LIST never reads it (§11 item 15: no per-row events query).
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
        // The order's current lines and their returned/edited-away sums
        // (R7's read and §4.4's, order-editing-design.md — stage 4a), shared
        // with OrderEditor rather than re-derived here.
        private readonly OrderCurrentLinesResolver $currentLines,
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
     *
     * THE "CURRENT PAYMENT" DEFINITION, IN FULL
     * (order-lifecycle-design.md §7.3, §11 item 19): the newest payments row
     * for the order by attempted_at DESC, id DESC **whose voided_at is
     * NULL**. The voided_at condition is not an extra rule bolted on — it is
     * the other half of "current": a row whose obligation was called off
     * (voided_at set) is still visible in the order's payment trail but is
     * never the row the order currently owes on, so it must never be the
     * row the list's filter matches or the View page shows.
     */
    private function latestPaymentColumnSubquery(string $column): \Illuminate\Database\Query\Builder
    {
        return DB::table('payments')
            ->select("payments.{$column}")
            ->whereRaw('payments.order_id = CAST(orders.id AS CHAR)')
            ->whereNull('payments.voided_at')
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
     *
     * The order's own history (order-lifecycle-design.md §6.3, §10 stage 3) is
     * read here as ONE more query — never one per event, and never with a join
     * to `staff` (staff_name is the event row's own snapshot). Ordered by
     * occurred_at, then id, so the id breaks the tie between two events
     * recorded in the same second; [] for an order with no events, which is
     * every order placed before this table existed.
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

        // The order's lines as they STAND NOW (order-editing-design.md §4.4),
        // through the one shared resolver: the placement transaction's lines
        // plus those of every earlier edit, minus whatever an edit reversed
        // away. Each entry carries its own returned and edited-away sums,
        // read in one grouped query each — never one per line.
        $entries = $this->currentLines->resolveRows($order);
        $rows = collect(array_column($entries, 'row'));

        // ONE read for every line's thumbnail, never one per line — see
        // imagePathsFor()'s own docblock. The View page's query-count test
        // pins the page's count to the line COUNT, not to how much data a
        // line carries.
        $media = $this->mediaFor($rows->pluck('priceable_id')->filter()->unique()->values()->all());
        $imagePathsByVariationId = $media['paths'];
        $productIdsByVariationId = $media['products'];

        $lines = array_map(
            fn (array $entry): OrderAdminSaleLineView => $this->buildLineView(
                $entry['row'],
                $entry['row']->priceable_id === null ? null : ($imagePathsByVariationId[$entry['row']->priceable_id] ?? null),
                $entry['returned'],
                $entry['editedAway'],
                $entry['row']->priceable_id === null ? null : ($productIdsByVariationId[$entry['row']->priceable_id] ?? null),
            ),
            $entries,
        );

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
        //
        // THE SAME "CURRENT PAYMENT" DEFINITION, voided_at INCLUDED
        // (order-lifecycle-design.md §7.3, §11 item 19): the newest row by
        // attempted_at DESC, id DESC whose voided_at is NULL. Every row
        // stays visible in the payment trail below this one; what a void
        // removes is only the row's claim to be the order's current
        // payment. Both reads therefore carry the identical condition, or
        // the list's filter and this page would disagree about the same
        // order.
        $latestPaymentId = DB::table('payments')
            ->where('order_id', $orderId)
            ->whereNull('voided_at')
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->limit(1)
            ->value('id');

        $latestPayment = $latestPaymentId !== null
            ? $this->payments->findById((string) $latestPaymentId)
            : null;

        // EVERY payment row, the current one and the voided/failed ones, in ONE
        // read (it replaces the old attempt COUNT, which added a customer's
        // genuine retries to the reissues an edit causes and meant nothing).
        // The page splits them by facts the rows already carry: the current
        // payment (above), status FAILED, voidedAt set.
        $payments = $this->payments->findByOrderId($orderId);

        // §6.3's one events read: the whole history in a single query, oldest
        // first, tie-broken by id. No join to `staff` — staff_name is the row's
        // own snapshot — and no derived value: this renders what the writer
        // wrote, never a status recomputed from the events.
        $events = DB::table('order_events')
            ->where('order_id', $orderId)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): OrderAdminEventView => new OrderAdminEventView(
                type: (string) $row->type,
                fromStatus: $row->from_status === null ? null : (string) $row->from_status,
                toStatus: $row->to_status === null ? null : (string) $row->to_status,
                reason: $row->reason === null ? null : (string) $row->reason,
                transactionId: $row->transaction_id === null ? null : (string) $row->transaction_id,
                staffName: $row->staff_name === null ? null : (string) $row->staff_name,
                occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            ))
            ->all();

        $movedLines = $this->movedLinesByTransaction(array_values(array_unique(array_filter(
            array_map(static fn (OrderAdminEventView $event): ?string => $event->transactionId, $events),
        ))));

        $events = array_map(
            static fn (OrderAdminEventView $event): OrderAdminEventView => $event->transactionId === null
                ? $event
                : new OrderAdminEventView(
                    $event->type,
                    $event->fromStatus,
                    $event->toStatus,
                    $event->reason,
                    $event->transactionId,
                    $event->staffName,
                    $event->occurredAt,
                    $movedLines[$event->transactionId] ?? [],
                ),
            $events,
        );

        return $this->orderViewCache[$orderId] = new OrderAdminOrderView(
            order: $order,
            clientName: $clientName,
            channel: $channel,
            lines: $lines,
            hasPromotionRedemption: $hasPromotionRedemption,
            latestPayment: $latestPayment,
            payments: $payments,
            events: $events,
        );
    }

    /**
     * What each of these transactions moved, keyed by transaction id — ONE
     * grouped read for all of them, however many history rows carry one.
     *
     * Past returns' and edits' lines live on their OWN transactions and are
     * not the order's current lines (OrderCurrentLinesResolver resolves
     * those), so this is new data read for the history cell only.
     *
     * `kind` says which of three things a line is: a goods return, units an
     * edit took off the order, or units an edit put on it. THE MOVED QUANTITY
     * IS CHOSEN BY THAT KIND, never by whether quantity_returned is NULL: a
     * refund line and an edit reversal record what they moved in
     * quantity_returned, but an ADDED line's quantity_returned is about a
     * LATER return of those units and says nothing about how many the edit put
     * on. Reading it by null-ness would make the edit's history row report
     * "× 1" instead of "× 3" the moment one added unit is returned, and worse
     * with each further return.
     * Axis values come from the line's own sold_attributes snapshot (no
     * extra read); a missing or unreadable snapshot just yields none — a
     * history page must never throw over a label.
     *
     * @param  list<string>  $transactionIds
     * @return array<string, list<array{kind: string, name: ?string, sku: ?string, quantity: int, attributes: list<string>}>>
     */
    private function movedLinesByTransaction(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $grouped = [];

        // A refund line does not copy the product's name, sku or axis snapshot
        // — it points at the line it returns (originating_sale_line_id) — so
        // those come from the originating line when the line has none of its
        // own. A self-join in the SAME query, not a second read.
        $rows = DB::table('operational_sales_sale_lines as l')
            ->leftJoin('operational_sales_sale_lines as o', 'o.id', '=', 'l.originating_sale_line_id')
            ->whereIn('l.transaction_id', $transactionIds)
            ->whereNull('l.deleted_at')
            ->orderBy('l.id')
            ->get([
                'l.transaction_id',
                'l.type',
                'l.quantity',
                'l.quantity_returned',
                DB::raw('COALESCE(l.product_name, o.product_name) as product_name'),
                DB::raw('COALESCE(l.sku, o.sku) as sku'),
                DB::raw('COALESCE(l.sold_attributes, o.sold_attributes) as sold_attributes'),
            ]);

        foreach ($rows as $row) {
            $decoded = is_string($row->sold_attributes) ? json_decode($row->sold_attributes, true) : $row->sold_attributes;
            $attributes = [];

            if (is_array($decoded)) {
                foreach ($decoded as $attribute) {
                    if (is_array($attribute) && isset($attribute['value']) && is_string($attribute['value'])) {
                        $attributes[] = $attribute['value'];
                    }
                }
            }

            $kind = match ((string) $row->type) {
                'edit_reversal' => 'removed',
                'sale' => 'added',
                'refund' => 'return',
                default => null,   // goods only: any other line type is not something that came back or was taken off
            };

            if ($kind === null) {
                continue;
            }

            $grouped[(string) $row->transaction_id][] = [
                'kind' => $kind,
                'name' => $row->product_name === null ? null : (string) $row->product_name,
                'sku' => $row->sku === null ? null : (string) $row->sku,
                'quantity' => (int) ($kind === 'added' ? $row->quantity : ($row->quantity_returned ?? $row->quantity)),
                'attributes' => $attributes,
            ];
        }

        return $grouped;
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
     *
     * STAGE 7c-1: $returnedQuantity arrives ALREADY SUMMED for this line —
     * forOrder() above read every line's total in one grouped query and
     * hands each line its own, so this method stays a pure mapper with no
     * read of its own (the same reason $imagePath is a parameter here and
     * not a lookup). remainingReturnable is then derived here rather than
     * in the DTO, so there is exactly one place that turns "how many came
     * back" into "how many may still" — the DTO holds facts, not arithmetic
     * (its own docblock).
     *
     * CLAMPED AT 0, one-directionally: the write path's locked R7 read
     * refuses an over-return, so quantity_returned exceeding quantity means
     * corrupted data — and 0 ("nothing left to return") is the safe reading
     * of that, where a negative capacity would reach §8.4's form as a
     * nonsense max. Never rounded up to the quantity either: a line whose
     * units have all come back is legitimately 0.
     */
    private function buildLineView(object $row, ?string $imagePath, int $returnedQuantity, int $editedAwayQuantity, ?string $productId = null): OrderAdminSaleLineView
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
            id: (string) $row->id,
            productName: $row->product_name,
            sku: $row->sku,
            quantity: $quantity,
            // max(), not a conditional: the clamp and the normal case are
            // one expression, and the normal case (a sum within the
            // quantity) is what every legitimate write produces.
            remainingReturnable: max(0, $quantity - $returnedQuantity),
            // Stage 4a: what an EDIT could still take of this line — its
            // quantity less units returned AND units edited away. A separate
            // question from remainingReturnable (whose meaning is unchanged);
            // the same one-directional clamp applies. For a line that is
            // still one of the order's lines editedAway is 0 by construction
            // (a fully edited-away line is not listed at all), but it is
            // subtracted so the number never depends on that.
            remainingEditable: max(0, $quantity - $returnedQuantity - $editedAwayQuantity),
            lineTotal: $lineTotal,
            unitPrice: $unitPrice,
            regularUnitPrice: SaleLineMapper::moneyOrNull($row->regular_unit_price_minor, $row->regular_unit_price_currency, 'regularUnitPrice'),
            finalUnitPrice: SaleLineMapper::moneyOrNull($row->final_unit_price_minor, $row->final_unit_price_currency, 'finalUnitPrice'),
            promotionDiscountShare: SaleLineMapper::moneyOrNull($row->promotion_discount_share_minor, $row->promotion_discount_share_currency, 'promotionDiscountShare'),
            discretionaryDiscount: SaleLineMapper::moneyOrNull($row->discretionary_discount_minor, $row->discretionary_discount_currency, 'discretionaryDiscount'),
            netPaidAmount: $netPaidAmount,
            unitCost: SaleLineMapper::moneyOrNull($row->unit_cost_minor, $row->unit_cost_currency, 'unitCost'),
            imagePath: $imagePath,
            productId: $productId,
            soldAttributes: self::decodeSoldAttributes($row->sold_attributes, $netPaidAmount === null, (string) $row->id),
            isLegacy: $netPaidAmount === null,
        );
    }

    /**
     * The thumbnail path for each of an order's line variations — BOUNDED
     * to one read (two when the first leaves any line without a photo) for
     * the WHOLE order, never a lookup per line, because the lines section
     * must not grow its query count with the number of lines it renders.
     *
     * THE IMAGE IS LIVE DATA, THE ONE DELIBERATE EXCEPTION TO D2's
     * "SNAPSHOT, NEVER LIVE" RULE, and stated here rather than left to be
     * discovered: §3.13's sale-line snapshot stores no image at all, so
     * there is no historical photo this page COULD show. What the merchant
     * sees is the variation's own current first photo, falling back to its
     * product's own first photo — the same "first READY image" rule
     * ProductResource's list subquery and the sandbox's gallery use, read
     * through this class because D5 keeps every cross-table read here.
     *
     * FAIL-SOFT (D8), NEVER LOUD: a variation that no longer exists, a
     * product with no media, an asset that never reached READY, or a
     * priceable_id that is NULL (the pseudo-lines the column's own
     * migration documents) all simply produce no entry in the returned map,
     * and the line renders without a thumbnail rather than with a broken
     * one.
     *
     * @param  list<string>  $variationIds  The order's line priceable ids (a SALE line's priceable_id IS its variation id — see SaleLineSnapshotBuilder's own construction of the snapshot).
     * @return array<string, string> variationId => path, for the ids that HAVE a usable image.
     */
    private function imagePathsFor(array $variationIds): array
    {
        return $this->mediaFor($variationIds)['paths'];
    }

    /**
     * The thumbnail path AND the product id of every line's variation, in ONE query (never one per line, and no
     * query more than the page already paid for its photos — the product id rides along: the order page links each
     * line to its product's edit page). A variation that no longer exists simply has neither.
     *
     * The photo rule is unchanged: the variation's OWN first READY image (by the attachment's sort_order, then the
     * asset id), else its product's first READY image by the same order. It is decided here in PHP over the joined
     * rows, because the variation's media and the product's media are two independent one-to-many joins of the
     * same variation row.
     *
     * @param  list<string>  $variationIds
     * @return array{paths: array<string, string>, products: array<string, string>}
     */
    private function mediaFor(array $variationIds): array
    {
        if ($variationIds === []) {
            return ['paths' => [], 'products' => []];
        }

        $rows = DB::table('catalog_variations as v')
            ->leftJoin('catalog_variation_media as vm', 'vm.variation_id', '=', 'v.id')
            ->leftJoin('catalog_media as vmed', function ($join): void {
                $join->on('vmed.id', '=', 'vm.media_id')
                    ->where('vmed.type', MediaType::IMAGE->value)
                    ->where('vmed.processing_status', ProcessingStatus::READY->value);
            })
            ->leftJoin('catalog_product_media as pm', 'pm.product_id', '=', 'v.product_id')
            ->leftJoin('catalog_media as pmed', function ($join): void {
                $join->on('pmed.id', '=', 'pm.media_id')
                    ->where('pmed.type', MediaType::IMAGE->value)
                    ->where('pmed.processing_status', ProcessingStatus::READY->value);
            })
            ->whereIn('v.id', $variationIds)
            ->get([
                'v.id as variation_id', 'v.product_id',
                'vm.sort_order as vm_sort', 'vmed.id as vm_media', 'vmed.path as vm_path',
                'pm.sort_order as pm_sort', 'pmed.id as pm_media', 'pmed.path as pm_path',
            ]);

        $products = [];
        $own = [];
        $fromProduct = [];

        foreach ($rows as $row) {
            $id = (string) $row->variation_id;
            $products[$id] = (string) $row->product_id;

            if ($row->vm_path !== null) {
                $key = [(int) $row->vm_sort, (int) $row->vm_media];

                if (! isset($own[$id]) || $key < $own[$id]['key']) {
                    $own[$id] = ['key' => $key, 'path' => (string) $row->vm_path];
                }
            }

            if ($row->pm_path !== null) {
                $key = [(int) $row->pm_sort, (int) $row->pm_media];

                if (! isset($fromProduct[$id]) || $key < $fromProduct[$id]['key']) {
                    $fromProduct[$id] = ['key' => $key, 'path' => (string) $row->pm_path];
                }
            }
        }

        $paths = [];

        foreach (array_keys($products) as $id) {
            $best = $own[$id] ?? $fromProduct[$id] ?? null;

            if ($best !== null) {
                $paths[$id] = $best['path'];
            }
        }

        return ['paths' => $paths, 'products' => $products];
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
