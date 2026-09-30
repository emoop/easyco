<?php

namespace App\Services;

use DateTimeImmutable;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use InvalidArgumentException;

/**
 * order-editing-design.md §4/§5/§6/§7, stage 3a (D3) — applies an order
 * edit's own line changes, writing §4.2's ledger in full, append-only,
 * for the instructions it is handed:
 *
 *   ADD              one new SALE line, no originating line
 *   REMOVE           one EDIT_REVERSAL for the line's full quantity
 *   CHANGE_QUANTITY  one EDIT_REVERSAL (full old quantity) + one new SALE
 *                    line at the new quantity
 *   DISCOUNT         one EDIT_REVERSAL (full quantity) + one new SALE line
 *                    at the same quantity and the new discount
 *
 * NOTHING IS EVER REWRITTEN IN PLACE: every affected origin is fully
 * reversed and, unless it was removed, replaced by a brand new SALE line
 * (§4.2's own reasoning — a later return divides by the line's own
 * originalQuantity and multiplies by its own netPaidAmount, both fixed at
 * the line's own construction and never correctable afterwards).
 *
 * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION — DOES NOT CALL
 * DB::transaction() ITSELF, exactly like App\Services\ReturnGoodsRecorder
 * (see that class's docblock for the full reasoning): the future caller
 * (stage 3b's OrderEditor) opens one DB::transaction(), locks the order
 * with OrderRepository::findByIdForUpdate(), and calls this class from
 * inside that same lock, so everything this class writes — one new
 * Transaction's lines, plus the stock changes — rolls back together with
 * the caller's own failure. TransactionRepository::save() and
 * StockLevelRepository::decrease()/increase() each still do their own
 * plain-write DB::transaction()/atomic UPDATE internally, which composes as
 * a savepoint because none of them does anything but a write (no hook, no
 * external I/O).
 *
 * EVERY REFUSAL HAPPENS BEFORE THE FIRST WRITE (this class's own version of
 * ReturnGoodsRecorder's "validate every line before writing anything"), so
 * a caller that (wrongly) reaches this class outside a transaction still
 * gets no partial effect: the plan is built and checked in full, then stock
 * is applied (§5 step 7), then the lines are written (§5 step 8).
 *
 * WHAT THIS CLASS DOES NOT DO, AND WHY — ALL OF IT IS STAGE 3b'S, §5: no
 * DB::transaction() of its own, no order lock, no refuse-unknown-order, no
 * edit_revision compare-and-set, no Order::assertEditable(), no payments
 * gate (§6's settled-payment refusal), no promotion validation or
 * redistribution (§7 — the caller computes each written line's own
 * promotionDiscountShare, including an explicit Money::zero() when a code
 * is dropped, and passes it in), no Order::reviseTotals(), no payment
 * void-and-reissue, and no order_events row. It also writes no read model:
 * §4.4's "current lines" are the CALLER's own read (both subtracted sums
 * already applied) and are passed in as $currentLines, which is why this
 * class takes no SaleLineRepository at all. Inside the caller's lock and
 * transaction those lines cannot go stale between the caller's read and
 * this call, so they are trusted as given; a line this call was NOT told
 * about — an unknown id in $changes — is refused loudly, since that means a
 * stale form or a caller bug, never a line to guess at.
 *
 * $changes IS A TAGGED-ARRAY INSTRUCTION SET, DELIBERATELY NOT A VALUE
 * OBJECT PER CHANGE: (a) it is the shape ReturnGoodsRecorder::record()
 * already uses for its own caller-supplied per-line instructions, so the
 * app layer keeps ONE convention for "service, here is what the operator
 * asked for"; (b) one entry per INTENT (rather than one per line) lets a
 * caller ask for "reduce to 3 and discount it" as two entries on the same
 * line, which this class merges into ONE reversal + ONE replacement — with
 * a per-line value object that merge decision (and §8's preview, which must
 * agree with it) would have to be duplicated in the caller; (c) these
 * entries are internal to one call — never persisted, never crossing a
 * layer boundary — which is exactly what the codebase's value objects
 * (CheckoutInput, CheckoutLinePricingResult) exist for and this is not.
 * Unknown keys and unknown "change" values are REFUSED, never ignored: an
 * ignored typo (say "discretionaryDiscounts") would silently apply a zero
 * discount, and a silent fallback is the one thing this codebase never does.
 *
 * @see SaleLine::createEditReversal()  the only writer of an EDIT_REVERSAL line
 * @see OrderLineEditResult  what this class hands back
 */
final class OrderLineEditor
{
    /** The four legal values of a change entry's own "change" key. */
    private const CHANGE_ADD = 'add';

    private const CHANGE_REMOVE = 'remove';

    private const CHANGE_QUANTITY = 'change_quantity';

    private const CHANGE_DISCOUNT = 'discount';

    private const CHANGE_KINDS = [
        self::CHANGE_ADD,
        self::CHANGE_REMOVE,
        self::CHANGE_QUANTITY,
        self::CHANGE_DISCOUNT,
    ];

    /**
     * The exact key set an "add" change's own "pricedLine" carries — the
     * same shape SaleLineSnapshotBuilder::buildForCart() documents, minus
     * nothing and plus nothing (see that method's own @param list).
     */
    private const PRICED_LINE_KEYS = [
        'variationId',
        'quantity',
        'regularUnitPrice',
        'finalUnitPrice',
        'unitCost',
        'productName',
        'sku',
        'promotionDiscountShare',
        'discretionaryDiscount',
    ];

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly StockLevelRepository $stockLevels,
        private readonly SaleLineSnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * @param  array<int, SaleLine>  $currentLines  §4.4's CURRENT lines of the order (remaining > 0), caller-resolved, in the caller's own display order. Never empty: an editable order always has at least one current line (§6 — an edit never empties the order, and a lineless order is a cancel case, not an edit).
     * @param  array<int, array<string, mixed>>  $changes  One entry per intent, each carrying a "change" key naming one of four kinds:
     *                                                     - `['change' => 'add', 'pricedLine' => ['variationId' => string, 'quantity' => int (>= 1), 'regularUnitPrice' => Money, 'finalUnitPrice' => Money, 'unitCost' => ?Money, 'productName' => string, 'sku' => string, 'promotionDiscountShare' => Money, 'discretionaryDiscount' => Money]]`
     *                                                     - `['change' => 'remove', 'originatingLine' => SaleLine]`
     *                                                     - `['change' => 'change_quantity', 'originatingLine' => SaleLine, 'quantity' => int (>= 1 — zero is a "remove")]`
     *                                                     - `['change' => 'discount', 'originatingLine' => SaleLine, 'discretionaryDiscount' => Money, 'promotionDiscountShare' => ?Money]` — the share is OPTIONAL and the origin's own share is carried forward when it is omitted; §7's redistribution passes a share explicitly (including `Money::zero()` to drop a code).
     * @param  string  $clientId  The new lines' own clientId. An order edit has no client of its own, so the caller passes the order's account id — exactly as ReturnGoodsRecorder does for a return's own new lines.
     * @param  DateTimeImmutable  $occurredAt  recordedAt and effectiveAt for every line written, and the caller's own event time.
     * @param  ?string  $editedBy  The staff member who made the edit — stored in the REFUND-shaped returned_by column (§4.1's deliberate narrow reuse), never a customer.
     * @param  ?string  $editedByName  The same, human-readable.
     * @param  ?string  $reason  The operator's own optional note.
     *
     * @throws InvalidArgumentException If $clientId is empty, if $currentLines is empty or holds anything but persisted SaleLines, if $changes is empty or malformed (unknown kind, unknown or missing key, wrong type, quantity below 1, discount above the line's own regular total, Money in another currency), if a change names a line that is not one of $currentLines, if one line is named by contradictory changes, if a line that must be reversed or replaced is a legacy line with no §3.13 snapshot (or no netPaidAmount), or if the edit would leave the order with no lines at all.
     * @throws InsufficientStockException If a decrease for an added or increased unit finds too little stock — the whole edit is refused, and nothing is persisted when this throws (stock is applied before the lines are written, §5 step 7 before step 8).
     */
    public function apply(
        array $currentLines,
        array $changes,
        string $clientId,
        DateTimeImmutable $occurredAt,
        ?string $editedBy,
        ?string $editedByName,
        ?string $reason,
    ): OrderLineEditResult {
        if ($clientId === '') {
            throw new InvalidArgumentException('OrderLineEditor: clientId must not be empty.');
        }

        $this->assertCurrentLines($currentLines);
        $this->assertChangesAreShaped($changes);

        $linesById = $this->indexCurrentLines($currentLines);

        // The order's own denomination, read off its first current line:
        // §3.13 keeps every Money field of one line in one currency, and
        // every line of one order is written in the cart's own currency, so
        // an added line denominated differently would corrupt the order's
        // totals the moment §6 recomputes them from the resulting lines.
        // Refused, never converted.
        $orderAmount = $currentLines[array_key_first($currentLines)]->amount();

        $plan = $this->plan($linesById, $changes, $orderAmount);

        $this->assertEditLeavesAtLeastOneLine($currentLines, $plan);

        // Every line this edit will write is CONSTRUCTED — and every refusal
        // the three construction paths can still raise is raised — BEFORE
        // stock is touched, so this class's own "every refusal happens before
        // the first write" promise holds literally rather than only inside
        // the caller's transaction. The plan above already validated every
        // input these builders read; the one refusal that does not come from
        // the plan is deliberate and loud —
        // SaleLineSnapshotBuilder::buildForCart() throws a LogicException for
        // an ADD naming a variation id that no longer exists — and it is
        // exactly why the added lines are built here and not after the stock
        // calls.
        //
        // One pass per role — every reversal, then every replacement, then
        // every added line — rather than "reversal, then its own
        // replacement" per line. The ledger then reads as two unambiguous
        // blocks ("everything the old state released", then "everything the
        // new state is"), and no replacement's correctness depends on
        // another change's own reversal inside this same batch: every
        // replacement's originatingSaleLineId points at an ALREADY persisted
        // line, never at another line written here.
        $reversals = [];

        foreach ($plan['originIds'] as $originId) {
            $reversals[$originId] = SaleLine::createEditReversal(
                originatingLine: $linesById[$originId],
                transactionId: '',
                editedBy: $editedBy,
                editedByName: $editedByName,
                reason: $reason,
                displayPriceAtEdit: null,
                recordedAt: $occurredAt,
                effectiveAt: $occurredAt,
            );
        }
        $replacements = [];

        foreach ($plan['originIds'] as $originId) {
            if (isset($plan['removeIds'][$originId])) {
                continue;
            }

            $replacements[$originId] = $this->buildReplacement(
                origin: $linesById[$originId],
                carried: $plan['snapshots'][$originId],
                effective: $plan['replacements'][$originId],
                clientId: $clientId,
                occurredAt: $occurredAt,
            );
        }

        // Added lines LAST, and built through the ONE app-layer service that
        // turns priced line data into a full-snapshot SALE line
        // (operational-sales-domain-design.md §3.13 E-D4's own rule: no
        // second implementation of that formula) — D5's batched
        // soldAttributes read therefore happens once for the whole batch,
        // never once per added line.
        $addedLines = $plan['adds'] === [] ? [] : $this->snapshotBuilder->buildForCart(
            lines: $plan['adds'],
            transactionId: '',
            clientId: $clientId,
            status: SaleLineStatus::COMPLETED,
            recordedAt: $occurredAt,
            effectiveAt: $occurredAt,
        );

        // §5 step 7 BEFORE step 8: stock, THEN the rows that record what
        // moved. From here on the only call that can refuse is a decrease
        // finding too little stock (§5 step 7's own InsufficientStockException
        // aborts the whole edit), and nothing is persisted yet when it does.
        $this->applyStock($linesById, $plan);

        // One edit = ONE Transaction (§5) — every line this edit wrote lands
        // in this single one, whose id the caller reads for
        // order_events.transaction_id (and whose saleLines() are reachable
        // the ordinary way, through the aggregate itself).
        //
        // Channel::WEB is this stage's honest value for an admin-initiated
        // write, exactly as ReturnGoodsRecorder's own admin return records
        // it; a POS-side edit is not something this stage designs.
        $transaction = new Transaction(null, Channel::WEB);

        foreach ([...array_values($reversals), ...array_values($replacements), ...$addedLines] as $line) {
            $transaction->addSaleLine($line);
        }

        // Where the rows actually land — safe to nest (see this class's own
        // docblock).
        $this->transactions->save($transaction);

        return OrderLineEditResult::written(
            $transaction,
            $this->resultingLines($currentLines, $plan, $replacements, $addedLines),
        );

    }

    /**
     * @param  array<int, SaleLine>  $currentLines
     */
    private function assertCurrentLines(array $currentLines): void
    {
        if ($currentLines === []) {
            throw new InvalidArgumentException(
                'OrderLineEditor: currentLines must not be empty — an editable order always has at least one current line '.
                '(§4.4/§6: an edit never empties the order, and an order with no lines is a cancel case, not an edit). '.
                'An empty read here is a caller bug, never an empty order.'
            );
        }

        foreach ($currentLines as $index => $line) {
            if (! $line instanceof SaleLine) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: currentLines[{$index}] must be a SaleLine, got ".get_debug_type($line).'.'
                );
            }

            // §4.4's definition, enforced rather than assumed: a "current
            // line" is a SALE line with units remaining. A SHIPPING, REFUND,
            // RESERVATION or INSTALLMENT_PAYMENT line appearing here means the
            // caller filtered its own read wrongly — and none of them is
            // something an edit may reverse-and-replace.
            //
            // NOTE: no per-line STATUS gate is checked here. §5's own edit
            // gates are order-level (step 4's assertEditable(), step 5's
            // payments gate) and belong to the caller; this class refuses only
            // what it can see is structurally not a line to edit.
            if ($line->type() !== SaleLineType::SALE) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: currentLines[{$index}] (line \"{$line->id()}\") is a {$line->type()->value} ".
                    'line — §4.4\'s current lines are SALE lines with units remaining, and only a SALE line can be '.
                    'reversed and replaced.'
                );
            }

            if ($line->priceableId() === null) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: currentLines[{$index}] (line \"{$line->id()}\") is a SALE line with no ".
                    'priceableId — §2 requires one for every type except SHIPPING/INSTALLMENT_PAYMENT, so this is '.
                    'damaged data (or a caller-assembled object rather than a stored one).'
                );
            }
        }
    }

    /**
     * @param  array<int, SaleLine>  $currentLines
     * @return array<string, SaleLine> Keyed by each line's own persisted id — the shape every $changes entry is resolved against.
     */
    private function indexCurrentLines(array $currentLines): array
    {
        $linesById = [];

        foreach ($currentLines as $line) {
            $id = $line->id();

            if ($id === null || $id === '') {
                throw new InvalidArgumentException(
                    'OrderLineEditor: currentLines holds a line with no id — §4.4\'s current lines are read back from '.
                    'storage, so every one of them is persisted by definition; an unpersisted line here means the caller '.
                    'assembled this list by hand instead of reading it, and this class cannot edit a line it cannot name.'
                );
            }

            if (isset($linesById[$id])) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: currentLines names line \"{$id}\" twice — the same line listed twice is a caller ".
                    'bug, and silently keeping one of the two entries would decide by accident which revision this edit '.
                    'was actually planned against.'
                );
            }

            $linesById[$id] = $line;
        }

        return $linesById;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function assertChangesAreShaped(array $changes): void
    {
        if ($changes === []) {
            throw new InvalidArgumentException(
                'OrderLineEditor: changes must not be empty — an edit that changes nothing is a caller bug, not a silent '.
                'no-op: whether the operator actually submitted anything is the caller\'s own decision, made before it '.
                'opens a transaction and takes the order lock.'
            );
        }

        foreach ($changes as $index => $change) {
            $this->assertChangeIsShaped($change, $index);
        }
    }

    /**
     * Structure only — every entry's KEY SET, both ways (never a missing key
     * silently defaulted, never an unknown key silently ignored). Each
     * key's VALUE is checked where it is used (resolveOrigin(),
     * validatePricedLine(), and plan() itself), so every message can name
     * the line or the field it is actually about.
     */
    private function assertChangeIsShaped(mixed $change, int|string $index): void
    {
        if (! is_array($change)) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] must be an array, got ".get_debug_type($change).'.'
            );
        }

        if (! array_key_exists('change', $change)) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] carries no \"change\" key saying what to do — every entry must name one of: ".
                implode(', ', self::CHANGE_KINDS).'.'
            );
        }

        $kind = $change['change'];

        if (! is_string($kind) || ! in_array($kind, self::CHANGE_KINDS, true)) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"change\"] must be one of: ".implode(', ', self::CHANGE_KINDS).
                ', got '.var_export($kind, true).'.'
            );
        }

        $required = match ($kind) {
            self::CHANGE_ADD => ['pricedLine'],
            self::CHANGE_REMOVE => ['originatingLine'],
            self::CHANGE_QUANTITY => ['originatingLine', 'quantity'],
            self::CHANGE_DISCOUNT => ['originatingLine', 'discretionaryDiscount'],
        };

        // The discount's promotion share is the ONE optional key in the whole
        // shape (§7's redistribution either passes a new share, or passes
        // nothing and lets the origin's own share carry forward). "change"
        // itself belongs in the allowed set by construction: it is the
        // discriminator $kind was just read from, so leaving it out would
        // refuse every well-formed entry as an unrecognised key.
        $allowed = $kind === self::CHANGE_DISCOUNT
            ? [...$required, 'change', 'promotionDiscountShare']
            : [...$required, 'change'];

        $missing = array_diff($required, array_keys($change));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] is a \"{$kind}\" change but is missing: ".implode(', ', $missing).
                ' — a missing key would silently become a default, and no default this class could pick would be the '.
                'operator\'s actual intent.'
            );
        }

        $unknown = array_diff(array_keys($change), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] is a \"{$kind}\" change carrying unrecognised key(s): ".
                implode(', ', $unknown).' — refused, never ignored (an ignored typo would silently apply none of what '.
                'the caller asked for).'
            );
        }
    }

    /**
     * Resolves one change's own "originatingLine" to the caller-supplied
     * current line it names, and REFUSES anything the current read does not
     * agree with.
     *
     * @param  array<string, mixed>  $change
     * @param  array<string, SaleLine>  $linesById
     */
    private function resolveOrigin(array $change, int|string $index, array $linesById): SaleLine
    {
        $origin = $change['originatingLine'];

        if (! $origin instanceof SaleLine) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"originatingLine\"] must be a SaleLine, got ".
                get_debug_type($origin).'.'
            );
        }

        $id = $origin->id();

        if ($id === null || $id === '') {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] points at a SaleLine with no id — only a line that is already ".
                'persisted can be edited; a not-yet-written line is something to "add", not to change.'
            );
        }

        if (! array_key_exists($id, $linesById)) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] names line \"{$id}\", which is not one of the current lines ".
                'passed in. §4.4\'s current lines are the caller\'s own locked read, so an id outside them means a '.
                'stale form, or a line that is already gone or already fully reversed — this class will not guess '.
                'which line was meant.'
            );
        }

        $listed = $linesById[$id];

        // The revision this edit was planned against, pinned by the two
        // numbers the reversal is actually built from: a caller handing in an
        // older copy of the same line (same id, yesterday's quantity) would
        // otherwise have this class reverse the WRONG amount, and that wrong
        // amount would be persisted as truthfully as a right one.
        if ($listed->quantity() !== $origin->quantity() || ! $listed->amount()->equals($origin->amount())) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}] carries a stale revision of line \"{$id}\" — it says quantity ".
                "{$origin->quantity()} / amount {$origin->amount()->decimalValue()}, while the current lines passed ".
                "in say quantity {$listed->quantity()} / amount {$listed->amount()->decimalValue()}. A change entry ".
                'must reference the very line object the caller read, not an earlier copy of it.'
            );
        }

        return $listed;
    }

    /**
     * Turns the caller's instructions into everything the write phase needs,
     * validating ALL of it first — this class's own "no refusal after the
     * first write" rule, which is what keeps a mis-called editor (one that
     * reached this class outside a transaction) from leaving a stock change
     * behind. See the class docblock.
     *
     * @param  array<string, SaleLine>  $linesById
     * @param  array<int, array<string, mixed>>  $changes
     * @param  Money  $orderAmount  Any one current line's own amount — the order's own denomination (every line agrees, §3.13).
     * @return array{originIds: list<string>, removeIds: array<string, true>, quantities: array<string, int>, discounts: array<string, array{discretionaryDiscount: Money, promotionDiscountShare: Money}>, adds: list<array<string, mixed>>, snapshots: array<string, array<string, mixed>>, replacements: array<string, array{quantity: int, discretionaryDiscount: Money, promotionDiscountShare: Money}>}
     */
    private function plan(array $linesById, array $changes, Money $orderAmount): array
    {
        // originIds keeps FIRST-APPEARANCE order: §4.2's ledger is written in
        // the order the operator's own form listed the changes, and nothing
        // else in this class re-sorts it.
        $originIds = [];
        $kindsByOriginId = [];
        $removeIds = [];
        $quantities = [];
        $discounts = [];
        $adds = [];

        foreach ($changes as $index => $change) {
            $kind = $change['change'];

            if ($kind === self::CHANGE_ADD) {
                $adds[] = $this->validatePricedLine($change['pricedLine'], $index, $orderAmount);

                continue;
            }

            $origin = $this->resolveOrigin($change, $index, $linesById);
            $originId = $origin->id();

            if (! isset($kindsByOriginId[$originId])) {
                $originIds[] = $originId;
                $kindsByOriginId[$originId] = [];
            }

            $already = $kindsByOriginId[$originId];

            if (isset($already[$kind])) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: changes[{$index}] names line \"{$originId}\" with a second \"{$kind}\" change ".
                    '— one line may be named once per kind, and a repeat would silently make one of the two win.'
                );
            }

            // "remove" COMPOSES WITH NOTHING: a removed line is fully
            // reversed and never replaced, so a quantity or a discount for
            // the same line has nowhere to land. Refused here rather than
            // resolved by precedence, because the operator asked for two
            // different things and only the caller (with its own form in
            // front of it) knows which one they actually meant.
            if (($kind === self::CHANGE_REMOVE && $already !== []) || isset($already[self::CHANGE_REMOVE])) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: line \"{$originId}\" is named by both a \"remove\" change and a \"{$kind}\" ".
                    'change (changes['.$index.']) — contradictory instructions for one line are always refused, '.
                    'never resolved by picking one of them.'
                );
            }

            // change_quantity + discount IS the one legal pair (§4.2 writes
            // one reversal + one replacement carrying BOTH the new quantity
            // and the new discount, which is exactly what a form that
            // reduced a line and discounted it in one submit means).
            $kindsByOriginId[$originId][$kind] = true;

            if ($kind === self::CHANGE_REMOVE) {
                $removeIds[$originId] = true;

                continue;
            }

            if ($kind === self::CHANGE_QUANTITY) {
                $quantity = $change['quantity'];

                if (! is_int($quantity) || $quantity < 1) {
                    throw new InvalidArgumentException(
                        "OrderLineEditor: changes[{$index}][\"quantity\"] must be an integer of at least 1, got ".
                        var_export($quantity, true).' — editing a line down to zero units is a "remove" change, not '.
                        'an edit that leaves a zero-unit line behind (a zero-unit SALE line cannot even be '.
                        'constructed: SaleLine refuses quantity <= 0).'
                    );
                }

                $quantities[$originId] = $quantity;

                continue;
            }

            $discretionaryDiscount = $change['discretionaryDiscount'];

            if (! $discretionaryDiscount instanceof Money) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: changes[{$index}][\"discretionaryDiscount\"] must be a Money, got ".
                    get_debug_type($discretionaryDiscount).'.'
                );
            }

            $promotionDiscountShare = $change['promotionDiscountShare'] ?? null;

            if ($promotionDiscountShare !== null && ! $promotionDiscountShare instanceof Money) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: changes[{$index}][\"promotionDiscountShare\"] must be a Money or omitted ".
                    '(omitting it carries the origin\'s own share forward), got '.
                    get_debug_type($promotionDiscountShare).'.'
                );
            }

            $discounts[$originId] = [
                'discretionaryDiscount' => $discretionaryDiscount,
                'promotionDiscountShare' => $promotionDiscountShare,
            ];
        }

        // Second pass, because these checks need the WHOLE plan: a discount's
        // own upper bound is measured against the line's regular total at its
        // EFFECTIVE quantity (a change_quantity entry may have just moved
        // it), and every origin that will be replaced needs its own §3.13
        // snapshot gathered before the write phase begins.
        $snapshots = [];
        $replacements = [];

        foreach ($originIds as $originId) {
            $origin = $linesById[$originId];

            $this->assertOriginCanBeReversed($origin);

            if (isset($removeIds[$originId])) {
                continue;
            }

            $carried = $this->snapshotToCarryForward($origin);

            $snapshots[$originId] = $carried;

            $quantity = $quantities[$originId] ?? $origin->quantity();

            if (isset($discounts[$originId])) {
                $discounts[$originId] = $this->validateDiscount(
                    discount: $discounts[$originId],
                    carried: $carried,
                    quantity: $quantity,
                    orderAmount: $orderAmount,
                    originId: $originId,
                );
            }

            // The replacement's own EFFECTIVE numbers, computed and checked
            // ONCE, here: the new quantity when the caller asked for one and
            // the origin's own otherwise, with the same rule for both discount
            // fields (a "discount" change's own share already resolved against
            // what its origin carries, by validateDiscount() above). Nothing
            // is left for buildReplacement() to decide or refuse.
            $discretionaryDiscount = $discounts[$originId]['discretionaryDiscount'] ?? $carried['discretionaryDiscount'];
            $promotionDiscountShare = $discounts[$originId]['promotionDiscountShare'] ?? $carried['promotionDiscountShare'];

            $this->assertWrittenLineNumbersAreValid(
                label: "the replacement for line \"{$originId}\"",
                regularUnitPrice: $carried['regularUnitPrice'],
                finalUnitPrice: $carried['finalUnitPrice'],
                promotionDiscountShare: $promotionDiscountShare,
                discretionaryDiscount: $discretionaryDiscount,
                quantity: $quantity,
            );

            $replacements[$originId] = [
                'quantity' => $quantity,
                'discretionaryDiscount' => $discretionaryDiscount,
                'promotionDiscountShare' => $promotionDiscountShare,
            ];
        }

        return [
            'originIds' => $originIds,
            'removeIds' => $removeIds,
            'quantities' => $quantities,
            'discounts' => $discounts,
            'adds' => $adds,
            'snapshots' => $snapshots,
            'replacements' => $replacements,
        ];
    }

    /**
     * Validates one "add" change's own "pricedLine" and hands it back
     * unchanged — the exact shape (and the exact key set) that
     * SaleLineSnapshotBuilder::buildForCart() documents.
     *
     * The pricing itself is NOT this class's job and never was: §5 step 3
     * puts promotion validation, the promotion share per line and every
     * price in the CALLER's hands, and this class exists to write lines, not
     * to price them. What it does own is §5 step 1's own "non-negative
     * quantities, a manual discount that does not exceed the line's own
     * regular price" — checked here, on data it will write verbatim.
     *
     * @return array<string, mixed>
     */
    private function validatePricedLine(mixed $pricedLine, int|string $index, Money $orderAmount): array
    {
        if (! is_array($pricedLine)) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"pricedLine\"] must be an array, got ".
                get_debug_type($pricedLine).'.'
            );
        }

        $missing = array_diff(self::PRICED_LINE_KEYS, array_keys($pricedLine));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"pricedLine\"] is missing: ".implode(', ', $missing).
                ' — every one of these is a §3.13 snapshot column the new line must carry from its own first '.
                'moment, and a missing one would silently become a default that is not the operator\'s or the '.
                'pricing engine\'s actual value.'
            );
        }

        $unknown = array_diff(array_keys($pricedLine), self::PRICED_LINE_KEYS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"pricedLine\"] carries unrecognised key(s): ".
                implode(', ', $unknown).' — refused, never ignored: an ignored typo (a misspelled '.
                '"discretionaryDiscount", say) would silently add the line with NO discount at all.'
            );
        }

        if (! is_string($pricedLine['variationId']) || $pricedLine['variationId'] === '') {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"pricedLine\"][\"variationId\"] must be a non-empty string ".
                'naming the Variation being added, got '.var_export($pricedLine['variationId'], true).'.'
            );
        }

        $quantity = $pricedLine['quantity'];

        if (! is_int($quantity) || $quantity < 1) {
            throw new InvalidArgumentException(
                "OrderLineEditor: changes[{$index}][\"pricedLine\"][\"quantity\"] must be an integer of at least 1, ".
                'got '.var_export($quantity, true).'.'
            );
        }

        $moneyFields = [
            'regularUnitPrice' => $pricedLine['regularUnitPrice'],
            'finalUnitPrice' => $pricedLine['finalUnitPrice'],
            'promotionDiscountShare' => $pricedLine['promotionDiscountShare'],
            'discretionaryDiscount' => $pricedLine['discretionaryDiscount'],
        ];

        // unitCost is the one legitimately nullable Money here — null means
        // the cost is genuinely unknown (§3.13 Q2), never zero.
        if ($pricedLine['unitCost'] !== null) {
            $moneyFields['unitCost'] = $pricedLine['unitCost'];
        }

        foreach ($moneyFields as $key => $value) {
            if (! $value instanceof Money) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: changes[{$index}][\"pricedLine\"][\"{$key}\"] must be a Money".
                    ($key === 'unitCost' ? ' or null (null meaning the cost is genuinely unknown, §3.13 Q2)' : '').
                    ', got '.get_debug_type($value).'.'
                );
            }

            if (! $value->currency()->equals($orderAmount->currency())) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: changes[{$index}][\"pricedLine\"][\"{$key}\"] is denominated in ".
                    "{$value->currency()->code()}, while the order's own lines are in ".
                    "{$orderAmount->currency()->code()} — an added line in another currency would corrupt the ".
                    'order\'s totals the moment they are recomputed from the resulting lines. Refused, never '.
                    'converted.'
                );
            }
        }

        foreach (['productName', 'sku'] as $key) {
            if (! is_string($pricedLine[$key]) || $pricedLine[$key] === '') {
                throw new InvalidArgumentException(
                    "OrderLineEditor: changes[{$index}][\"pricedLine\"][\"{$key}\"] must be a non-empty string — ".
                    'the new line\'s own §3.13 snapshot name/code, as the pricing step read them, and exactly the '.
                    'Tier B invariant SaleLine::create() enforces for a fresh SALE line.'
                );
            }
        }

        $this->assertWrittenLineNumbersAreValid(
            label: "changes[{$index}][\"pricedLine\"]",
            regularUnitPrice: $pricedLine['regularUnitPrice'],
            finalUnitPrice: $pricedLine['finalUnitPrice'],
            promotionDiscountShare: $pricedLine['promotionDiscountShare'],
            discretionaryDiscount: $pricedLine['discretionaryDiscount'],
            quantity: $quantity,
        );

        return $pricedLine;
    }

    /**
     * The two numbers every SALE line this class writes must satisfy, checked
     * here — BEFORE any write — so that the equivalently-named invariants
     * inside SaleLine::create()/createEditReversal() (which of course still
     * enforce them at construction) can never be the first place a bad edit
     * is noticed, halfway through applying stock.
     *
     * §5 step 1's words, on the resulting line's own effective numbers: "a
     * manual discount that does not exceed the line's own regular price",
     * and therefore a net that never goes negative.
     */
    private function assertWrittenLineNumbersAreValid(
        string $label,
        Money $regularUnitPrice,
        Money $finalUnitPrice,
        Money $promotionDiscountShare,
        Money $discretionaryDiscount,
        int $quantity,
    ): void {
        foreach (['promotionDiscountShare' => $promotionDiscountShare, 'discretionaryDiscount' => $discretionaryDiscount] as $field => $value) {
            if ($value->isNegative()) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: {$label}'s {$field} must not be negative, got {$value->decimalValue()}."
                );
            }
        }

        $regularTotal = $regularUnitPrice->multiply($quantity);
        $amount = $finalUnitPrice->multiply($quantity);

        if ($discretionaryDiscount->minorValue() > $regularTotal->minorValue()) {
            throw new InvalidArgumentException(
                "OrderLineEditor: {$label} would leave a manual discount of {$discretionaryDiscount->decimalValue()} ".
                "against a regular total of only {$regularTotal->decimalValue()} ({$quantity} x ".
                "{$regularUnitPrice->decimalValue()}) — a manual discount larger than the line's own regular price ".
                'is a data error, never a negative-price line.'
            );
        }

        $netPaidAmount = $amount->subtract($promotionDiscountShare)->subtract($discretionaryDiscount);

        if ($netPaidAmount->isNegative()) {
            throw new InvalidArgumentException(
                "OrderLineEditor: {$label} would leave a negative net paid amount ({$netPaidAmount->decimalValue()}): ".
                "amount {$amount->decimalValue()} minus the promotion share {$promotionDiscountShare->decimalValue()} ".
                "minus the manual discount {$discretionaryDiscount->decimalValue()}. A promotion share must be ".
                'rebalanced (§7) BEFORE the lines it applies to are written — this class never writes a line whose '.
                'own customer has been charged a negative amount.'
            );
        }
    }

    /**
     * @param  array{discretionaryDiscount: Money, promotionDiscountShare: ?Money}  $discount  As read from the change entry, its share still possibly omitted.
     * @param  array<string, mixed>  $carried  The origin's own snapshot (snapshotToCarryForward() above).
     * @return array{discretionaryDiscount: Money, promotionDiscountShare: Money} With the share RESOLVED: the caller's own when it passed one (§7's redistribution), the origin's own when it did not.
     */
    private function validateDiscount(array $discount, array $carried, int $quantity, Money $orderAmount, string $originId): array
    {
        $promotionDiscountShare = $discount['promotionDiscountShare'] ?? $carried['promotionDiscountShare'];

        foreach ([
            'discretionaryDiscount' => $discount['discretionaryDiscount'],
            'promotionDiscountShare' => $promotionDiscountShare,
        ] as $field => $value) {
            if (! $value->currency()->equals($orderAmount->currency())) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: the \"discount\" change for line \"{$originId}\" carries a {$field} in ".
                    "{$value->currency()->code()}, while the order's own lines are in ".
                    "{$orderAmount->currency()->code()} — refused, never converted."
                );
            }
        }

        return [
            'discretionaryDiscount' => $discount['discretionaryDiscount'],
            'promotionDiscountShare' => $promotionDiscountShare,
        ];
    }

    /**
     * §4.2/§4.6's own precondition, checked BEFORE anything is written: an
     * EDIT_REVERSAL's amount is DERIVED from the origin's netPaidAmount(),
     * so a line written before stage 4d's §3.13 snapshot (netPaidAmount ===
     * null) has no amount to reverse at all. Such a line is edited by a
     * different means (refund the line, then place a fresh order) — NEVER by
     * reversing an amount this class guessed. SaleLine::createEditReversal()
     * refuses the same fact at construction; this check exists so that
     * refusal happens during planning, before stock has moved, and so the
     * message can name the line the operator is looking at.
     */
    private function assertOriginCanBeReversed(SaleLine $origin): void
    {
        if ($origin->netPaidAmount() === null) {
            throw new InvalidArgumentException(
                "OrderLineEditor: line \"{$origin->id()}\" cannot be edited — it carries no netPaidAmount, so it was ".
                'written before the §3.13 snapshot fields existed and there is no amount this edit could reverse. '.
                'Refund the line and place a new order instead; this class never guesses an amount.'
            );
        }
    }

    /**
     * The origin's own §3.13 snapshot, taken as the replacement's starting
     * point (§4.2: a replacement is the same economic line, re-stated — its
     * name, sku, prices, cost and attribute snapshot are NOT re-derived at
     * edit time, because none of them changed, and re-reading the catalog
     * now would silently re-price history).
     *
     * Refuses a legacy line (one written before stage 4d) for the same
     * reason the admin order screens already refuse to return one: there is
     * no snapshot to carry forward, and a replacement with a half-empty
     * snapshot is a line the codebase could never re-read reliably.
     *
     * @return array{regularUnitPrice: Money, finalUnitPrice: Money, promotionDiscountShare: Money, discretionaryDiscount: Money, productName: string, sku: string, soldAttributes: array<int, array<string, string>>}
     */
    private function snapshotToCarryForward(SaleLine $origin): array
    {
        $originId = $origin->id();

        $carried = [
            'regularUnitPrice' => $origin->regularUnitPrice(),
            'finalUnitPrice' => $origin->finalUnitPrice(),
            'promotionDiscountShare' => $origin->promotionDiscountShare(),
            'discretionaryDiscount' => $origin->discretionaryDiscount(),
            'productName' => $origin->productName(),
            'sku' => $origin->sku(),
            'soldAttributes' => $origin->soldAttributes(),
        ];

        foreach ($carried as $field => $value) {
            if ($value === null) {
                throw new InvalidArgumentException(
                    "OrderLineEditor: line \"{$originId}\" cannot be replaced — its own {$field} was never recorded ".
                    '(a line written before the §3.13 snapshot fields existed). A replacement is built from the '.
                    "origin's own snapshot and never from the catalog's values today, so there is nothing to carry ".
                    'forward: refund the line and place a new order instead.'
                );
            }
        }

        // productName/sku are non-null by now but may be the EMPTY STRING —
        // the one hole PHP's own type system leaves, and the exact Tier B
        // check SaleLine::create() refuses a fresh SALE line for.
        foreach (['productName', 'sku'] as $field) {
            if ($carried[$field] === '') {
                throw new InvalidArgumentException(
                    "OrderLineEditor: line \"{$originId}\" cannot be replaced — its own {$field} is an empty string, ".
                    'which no fresh SALE line may carry (SaleLine::create()\'s own Tier B check). The origin line is '.
                    'damaged data; refund it and place a new order instead of propagating the damage.'
                );
            }
        }

        return $carried;
    }

    /**
     * §6's own arithmetic, checked before anything is written: an edit never
     * empties the order. The caller's current lines, minus every line this
     * edit removes, plus every line it adds, must still be at least one — a
     * lineless order is a cancel, which is a different action with its own
     * service, its own assertions and its own events, not a form this class
     * finishes for it.
     *
     * @param  array<int, SaleLine>  $currentLines
     * @param  array{removeIds: array<string, true>, adds: list<array<string, mixed>>}  $plan
     */
    private function assertEditLeavesAtLeastOneLine(array $currentLines, array $plan): void
    {
        $remaining = count($currentLines) - count($plan['removeIds']) + count($plan['adds']);

        if ($remaining < 1) {
            throw new InvalidArgumentException(
                'OrderLineEditor: this edit removes every line of the order ('.count($currentLines).' current, '.
                count($plan['removeIds']).' removed, '.count($plan['adds']).' added, leaving '.$remaining.') — an '.
                'edit never empties an order. Cancelling one is a separate action, with its own status transition '.
                'and its own events.'
            );
        }
    }

    /**
     * §6's own stock consequence, applied BEFORE the lines are written (§5
     * step 7 before step 8) so that an InsufficientStockException refuses the
     * whole edit with nothing persisted:
     *
     *   - an INCREASE for every unit this edit RELEASED from the order —
     *     each removed line's whole quantity, and the difference when a
     *     line's quantity goes down (the reversal side);
     *   - a DECREASE for every unit this edit PUT INTO the order — each
     *     added line's quantity, and each replacement's own new quantity
     *     (the write side).
     *
     * INCREASES ALWAYS RUN BEFORE DECREASES, and each origin's two quantities
     * net FIRST (the loop below books one call per variation and direction,
     * never an increase and then a decrease for the same line): "change
     * 5 -> 3" against a variation with nothing on hand is one increase of 2,
     * never an increase of 5 followed by a decrease of 3. The ordering rule
     * is what lets an edit reuse what it released — remove a line of 3 and
     * add one of 2 against a variation with nothing on hand, and the increase
     * of 3 lands before the decrease of 2, so the edit succeeds at 1 with no
     * spurious InsufficientStockException. Re-ordering MORE than the edit
     * released still refuses truthfully: "change 3 -> 5" with only 1 on hand
     * asks for 2 units the order does not hold.
     *
     * Amounts are AGGREGATED PER VARIATION and per direction (one call per
     * variation, not one per line). ReturnGoodsRecorder's own per-line calls
     * exist because each returned line carries its own restock decision; an
     * edit has no such per-line decision to preserve, so aggregating is
     * simply fewer atomic UPDATEs and a smaller window for two concurrent
     * edits to interleave on one row. The totals each call is checked
     * against are identical either way.
     *
     * A DISCOUNT-only edit nets to zero on every variation and therefore
     * calls NEITHER method at all (§6's own words: nothing moved).
     *
     * @param  array<string, SaleLine>  $linesById
     */
    private function applyStock(array $linesById, array $plan): void
    {
        $increases = [];
        $decreases = [];

        foreach ($plan['originIds'] as $originId) {
            $origin = $linesById[$originId];

            $newQuantity = isset($plan['removeIds'][$originId])
                ? 0
                : $plan['replacements'][$originId]['quantity'];

            $delta = $origin->quantity() - $newQuantity;

            if ($delta === 0) {
                continue;
            }

            $variationId = $this->priceableIdOf($origin);
            $amount = abs($delta);

            if ($delta > 0) {
                $increases[$variationId] = ($increases[$variationId] ?? 0) + $amount;
            } else {
                $decreases[$variationId] = ($decreases[$variationId] ?? 0) + $amount;
            }
        }

        foreach ($plan['adds'] as $pricedLine) {
            $variationId = $pricedLine['variationId'];

            $decreases[$variationId] = ($decreases[$variationId] ?? 0) + $pricedLine['quantity'];
        }

        foreach ($increases as $variationId => $amount) {
            $this->stockLevels->increase($variationId, $amount);
        }

        foreach ($decreases as $variationId => $amount) {
            $this->stockLevels->decrease($variationId, $amount);
        }
    }

    /**
     * §4.2's replacement: a brand new SALE line for the same economic line,
     * at its post-edit quantity and discounts, carrying the origin's own
     * §3.13 snapshot forward unchanged and pointing at it with
     * originatingSaleLineId — purely lineage ("this line replaced that one",
     * §4.2's own words), never load-bearing for any refund-share
     * calculation, which always reads the current line's own fields.
     *
     * Every number it needs is already in $carried/$effective (computed and
     * checked by plan()); nothing here decides or refuses anything, so a
     * refusal from SaleLine::create() below would be a bug in this class
     * rather than something an operator can trigger.
     *
     * originatingReservationLineId is deliberately NOT carried forward: the
     * origin's own reservation provenance stays readable by walking one hop
     * (replacement -> origin -> reservation line), and copying it here would
     * have two SALE lines each claiming to be that reservation's settlement.
     *
     * Status is COMPLETED, the status a checkout's own freshly written SALE
     * line carries: the replacement IS the order's line for that product from
     * the moment this edit commits. A predecessor that already shipped or was
     * collected is refused by the caller's own §5 step 4/5 gates.
     *
     * @param  array<string, mixed>  $carried  The origin's §3.13 snapshot (snapshotToCarryForward()).
     * @param  array{quantity: int, discretionaryDiscount: Money, promotionDiscountShare: Money}  $effective  The post-edit numbers, already validated by plan().
     */
    private function buildReplacement(
        SaleLine $origin,
        array $carried,
        array $effective,
        string $clientId,
        DateTimeImmutable $occurredAt,
    ): SaleLine {
        $quantity = $effective['quantity'];
        $finalUnitPrice = $carried['finalUnitPrice'];

        $amount = $finalUnitPrice->multiply($quantity);
        $netPaidAmount = $amount
            ->subtract($effective['promotionDiscountShare'])
            ->subtract($effective['discretionaryDiscount']);

        $unitCost = $origin->unitCost();

        // D4's own rule, identical to SaleLineSnapshotBuilder's: a null
        // unitCost means the cost is genuinely unknown (§3.13 Q2), not zero,
        // so profit equals the whole net in that case rather than the net
        // minus nothing.
        $profit = $unitCost === null
            ? $netPaidAmount
            : $netPaidAmount->subtract($unitCost->multiply($quantity));

        return SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $this->priceableIdOf($origin),
            status: SaleLineStatus::COMPLETED,
            quantity: $quantity,
            amount: $amount,
            profit: $profit,
            recordedAt: $occurredAt,
            effectiveAt: $occurredAt,
            productName: $carried['productName'],
            sku: $carried['sku'],
            regularUnitPrice: $carried['regularUnitPrice'],
            finalUnitPrice: $finalUnitPrice,
            promotionDiscountShare: $effective['promotionDiscountShare'],
            discretionaryDiscount: $effective['discretionaryDiscount'],
            netPaidAmount: $netPaidAmount,
            soldAttributes: $carried['soldAttributes'],
            unitCost: $unitCost,
            originatingSaleLineId: $origin->id(),
        );
    }

    /**
     * A SALE line's own priceableId, as a non-null string.
     *
     * assertCurrentLines() has already refused a current line without one, so
     * the guard below is unreachable for every line this class reads from
     * $currentLines; it exists only because the accessor is nullable and the
     * writer above needs a string — this codebase's "cheap corruption
     * detector, not implicit trust" posture, never a silent `?? ''`.
     */
    private function priceableIdOf(SaleLine $origin): string
    {
        $priceableId = $origin->priceableId();

        if ($priceableId === null) {
            throw new InvalidArgumentException(
                "OrderLineEditor: line \"{$origin->id()}\" has no priceableId, so its variation cannot be named ".
                'on any line this edit would write — damaged data, refused rather than written as an empty id.'
            );
        }

        return $priceableId;
    }

    /**
     * §4.4's "current lines" after this edit, in the CALLER's own display
     * order — the replacement truth the caller hands to the admin UI and,
     * more importantly, the line set the caller's own totals recomputation
     * must summarise.
     *
     * An untouched line passes through as the very object it was; a replaced
     * line keeps its ORIGINAL POSITION (its replacement is the same economic
     * line re-stated, so it does not jump to the end of the list); a removed
     * line is gone; added lines are appended in the order the changes named
     * them, the same "appended in the order given" convention
     * ReturnGoodsRecorder's own return lines already follow.
     *
     * @param  array<int, SaleLine>  $currentLines
     * @param  array{removeIds: array<string, true>}  $plan
     * @param  array<string, SaleLine>  $replacements
     * @param  array<int, SaleLine>  $addedLines
     * @return array<int, SaleLine>
     */
    private function resultingLines(array $currentLines, array $plan, array $replacements, array $addedLines): array
    {
        $resulting = [];

        foreach ($currentLines as $line) {
            $id = $line->id();

            if (isset($plan['removeIds'][$id])) {
                continue;
            }

            $resulting[] = $replacements[$id] ?? $line;
        }

        foreach ($addedLines as $line) {
            $resulting[] = $line;
        }

        return $resulting;
    }
}
