<?php

namespace App\Services;

use App\Services\Exceptions\ReturnExceedsRemainingQuantityException;
use DateTimeImmutable;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\OperationalSales\Contracts\SaleLineRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use InvalidArgumentException;

/**
 * order-lifecycle-design.md §7.2 items 1-2 (its stage 6b-i) — writes the
 * goods half of a return: one new Transaction holding one REFUND SaleLine
 * per returned line, then restocks each line whose own flag says so.
 * §7.2 items 3-4 (no product/variation state change; the money step) are
 * explicitly NOT this class's job — see this class's own "what this does
 * not do" note below.
 *
 * ASSUMES IT IS ALREADY INSIDE AN OPEN TRANSACTION — DOES NOT CALL
 * DB::transaction() ITSELF. The same lesson App\Services\
 * OrderPaymentConfirmer's own stage-6a refactor exists to teach (see that
 * class's docblock): this class has no hook of its own to worry about, but
 * its future caller (OrderStatusChanger::recordReturn(), stage 6b-ii) will
 * call it from inside its own locked transaction, and a class that
 * silently opens its own transaction is the wrong shape to compose —
 * TransactionRepository::save() and StockLevelRepository::increase() each
 * still do their own plain-write DB::transaction()/atomic-UPDATE
 * internally, which is safe to nest (composes as a savepoint) because
 * neither does anything but a plain write — no hook, no external I/O
 * (confirmed by reading EloquentTransactionRepository::save() directly).
 *
 * WHAT THIS CLASS DOES NOT DO, PERMANENTLY (§7.2 item 3): no Product/
 * Variation state change of any kind — no relist, no republish, no
 * restore-from-archive. A unit physically returning is a stock fact; a
 * merchant's own decision about whether it may be sold online again is a
 * separate, deliberate act this class must never make silently.
 * No order_events row (that is OrderStatusChanger's job, not this
 * service's — the order context this service knows nothing about). No
 * PaymentRefund, no promotion release, no hook of any kind.
 */
final class ReturnGoodsRecorder
{
    public function __construct(
        private readonly SaleLineRepository $saleLines,
        private readonly TransactionRepository $transactions,
        private readonly StockLevelRepository $stockLevels,
        private readonly CumulativeRefundShareCalculator $shareCalculator,
    ) {}

    /**
     * @param array<int, array{originatingLine: SaleLine, quantityReturned: int, restock: bool}> $lines
     *
     * @throws InvalidArgumentException If $lines is empty, or if any
     *   originating line's netPaidAmount is null (a legacy line — refused
     *   loudly rather than silently treated as zero).
     * @throws ReturnExceedsRemainingQuantityException If any line's own
     *   requested quantity exceeds what R7's read says remains.
     */
    public function record(
        array $lines,
        string $clientId,
        DateTimeImmutable $occurredAt,
        ?string $returnedBy,
        ?string $returnedByName,
        ?string $reason,
    ): Transaction {
        if ($lines === []) {
            throw new InvalidArgumentException(
                'ReturnGoodsRecorder: lines must not be empty — a return of nothing is a caller bug.'
            );
        }

        // Step 2: validate EVERY line before writing any of them, so a bad
        // line in a multi-line return aborts the whole call, not just its
        // own line. alreadyReturned is read once per line here and reused
        // below in step 4, rather than queried twice.
        //
        // NOT DEFENDED AGAINST, FLAGGED RATHER THAN GUESSED AT: two entries
        // in the SAME $lines array referencing the SAME originating line
        // are each checked independently against the database's own prior
        // REFUND sum — their own combined quantityReturned is not
        // cross-checked against each other. The real future caller
        // (OrderStatusChanger::recordReturn(), order-lifecycle-design.md
        // §5.2) builds this array from an associative map keyed by
        // originating line id, which makes that input shape structurally
        // impossible — defending against it here would guard a shape the
        // real system never produces. See this stage's own final report.
        $alreadyReturnedByIndex = [];

        foreach ($lines as $index => $line) {
            $originatingLine = $line['originatingLine'];

            // A real, caller-supplied guard rather than an unused
            // parameter: every line's own origin must belong to the same
            // client this return is being recorded for — catches a caller
            // accidentally mixing lines from a different order/client into
            // one return, before anything is written.
            if ($originatingLine->clientId() !== $clientId) {
                throw new InvalidArgumentException(sprintf(
                    'ReturnGoodsRecorder: SaleLine "%s" belongs to client "%s", not the client this return is being recorded for ("%s").',
                    $originatingLine->id(),
                    $originatingLine->clientId(),
                    $clientId,
                ));
            }

            $alreadyReturned = $this->saleLines->sumQuantityReturnedForOriginatingLine($originatingLine->id());
            $remaining = $originatingLine->quantity() - $alreadyReturned;

            if ($line['quantityReturned'] > $remaining) {
                throw ReturnExceedsRemainingQuantityException::forLine(
                    $originatingLine->id(),
                    $line['quantityReturned'],
                    $remaining,
                );
            }

            $alreadyReturnedByIndex[$index] = $alreadyReturned;
        }

        // Step 3: one new Transaction for this return event.
        $transaction = new Transaction(null, Channel::WEB);

        // Step 4: build the REFUND lines, in the order given.
        $restockPlan = [];

        foreach ($lines as $index => $line) {
            $originatingLine = $line['originatingLine'];
            $quantityReturned = $line['quantityReturned'];

            $netPaidAmount = $originatingLine->netPaidAmount();

            if ($netPaidAmount === null) {
                throw new InvalidArgumentException(
                    "ReturnGoodsRecorder: SaleLine \"{$originatingLine->id()}\" has no netPaidAmount recorded ".
                    '(a legacy line, written before operational-sales-domain-design.md §3.13 shipped) — '.
                    'a refund share cannot be computed for it, and this is refused rather than silently treated as zero.'
                );
            }

            $defaultRefundAmount = $this->shareCalculator->shareFor(
                netPaidAmount: $netPaidAmount,
                originalQuantity: $originatingLine->quantity(),
                alreadyReturned: $alreadyReturnedByIndex[$index],
                thisReturn: $quantityReturned,
            );

            // displayPriceAtReturn is always NULL from this class: a live
            // PriceResolver read is informational only (order-lifecycle-
            // design.md §7.2 item 1) and never drives the refund amount —
            // out of this stage's scope, which has no Pricing dependency
            // at all. A future caller with a PriceResolver in hand may
            // build the REFUND line itself with a real value if that ever
            // matters; this service does not.
            $refundLine = SaleLine::createRefund(
                originatingLine: $originatingLine,
                transactionId: '',
                quantityReturned: $quantityReturned,
                defaultRefundAmount: $defaultRefundAmount,
                returnedBy: $returnedBy,
                returnedByName: $returnedByName,
                returnReason: $reason,
                displayPriceAtReturn: null,
                recordedAt: $occurredAt,
                effectiveAt: $occurredAt,
            );

            $transaction->addSaleLine($refundLine);

            if ($line['restock']) {
                $restockPlan[] = ['priceableId' => $originatingLine->priceableId(), 'quantity' => $quantityReturned];
            }
        }

        // Step 5: persist — this is where the rows actually land. Safe to
        // nest (see this class's own docblock).
        $this->transactions->save($transaction);

        // Step 6: restock, one call per line, never batched into one
        // number (order-lifecycle-design.md §7.1). A line with
        // restock=false calls nothing at all.
        foreach ($restockPlan as $restock) {
            $this->stockLevels->increase($restock['priceableId'], $restock['quantity']);
        }

        // Step 7: the caller (stage 6b-ii's OrderStatusChanger) reads the
        // new transaction's id for order_events.transaction_id and each
        // new line's actualRefundAmount for the money step.
        return $transaction;
    }
}
