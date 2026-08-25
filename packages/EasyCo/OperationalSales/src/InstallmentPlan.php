<?php

namespace EasyCo\OperationalSales;

use DateTimeImmutable;
use EasyCo\OperationalSales\Enums\InstallmentPlanStatus;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Exceptions\ClientMismatchException;
use EasyCo\OperationalSales\Exceptions\CurrencyMismatchException;
use EasyCo\OperationalSales\Exceptions\InstallmentPlanNotActiveException;
use EasyCo\OperationalSales\Exceptions\OverpaymentException;
use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Money;

/**
 * The InstallmentPlan aggregate root — groups a client's reserved
 * SaleLines together with the partial payments made against them.
 *
 * THE DIRECT FIX FOR A REAL BUG (see operational-sales-domain-design.md
 * §3.3): the source system grouped a client's reserved items and their
 * partial payments using a randomly-generated string written into a
 * free-text comment column, found again later via a LIKE-style match.
 * A new item reserved while a plan was already active never got the
 * marker (the code that assigned it only ran when no marker existed yet
 * for that client at all), so it silently stayed reserved forever, even
 * once the plan was fully paid off. Here, attachReservedLine() appends a
 * real SaleLine object reference onto this aggregate's own
 * $reservedLines array — there is no string to independently regenerate
 * and no marker to fail to apply. Attaching a second reserved line to an
 * already-active plan (see InstallmentPlanTest's dedicated regression
 * test) works exactly the same as attaching the first: this bug class
 * is not mitigated here, it is structurally impossible.
 */
final class InstallmentPlan
{
    /**
     * @param SaleLine[] $reservedLines
     * @param SaleLine[] $paymentLines
     */
    public function __construct(
        private ?string $id,
        private string $clientId,
        private InstallmentPlanStatus $status,
        private array $reservedLines = [],
        private array $paymentLines = [],
    ) {
        if ($clientId === '') {
            throw new \InvalidArgumentException('InstallmentPlan clientId must not be empty.');
        }

        self::assertAllLinesShareOneCurrency($id, $reservedLines, $paymentLines);
    }

    /**
     * A completely empty, newly-opened plan has no lines yet to derive a
     * currency from at all; every subsequent line is checked against
     * whatever currency the first attached line establishes (see
     * planCurrency() / assertCurrencyMatches()). This is a pure,
     * in-memory, O(n) structural check over the exact lines being
     * constructed/reconstituted — no sibling-aggregate or database
     * lookup involved — so, per the same reasoning already applied to
     * SaleLine::reconstituteFromStorage() not bypassing its own
     * constructor validation, it is cheap enough and valuable enough as
     * a corruption detector to keep running for BOTH open() and
     * reconstituteFromStorage(), rather than being skipped for the
     * latter.
     *
     * @param SaleLine[] $reservedLines
     * @param SaleLine[] $paymentLines
     */
    private static function assertAllLinesShareOneCurrency(?string $id, array $reservedLines, array $paymentLines): void
    {
        $allLines = [...$reservedLines, ...$paymentLines];

        if ($allLines === []) {
            return;
        }

        $expectedCurrency = $allLines[0]->amount()->currency();

        foreach ($allLines as $line) {
            if (! $line->amount()->currency()->equals($expectedCurrency)) {
                throw CurrencyMismatchException::becauseCurrencyDoesNotMatch(
                    $id ?? '(unsaved)',
                    $expectedCurrency->code(),
                    $line->amount()->currency()->code(),
                );
            }
        }
    }

    /**
     * Opens a brand-new, empty, ACTIVE plan for a client.
     *
     * Deliberately takes no currency parameter yet: this aggregate
     * currently derives its currency from whichever line (reserved or
     * payment) is attached first — see planCurrency(). That is
     * provisional, not a settled design decision, and exists only
     * because no persistence layer/repository for this aggregate exists
     * yet to inform a real call site. Once one does, the natural,
     * concrete source for a plan's currency is the currency of the
     * first reserved line the caller is about to attach — at which
     * point open() should very likely accept that currency explicitly,
     * removing the ambiguity a zero-line plan has today (see
     * outstandingBalance()'s EasyCo\Pricing\DefaultCurrency fallback,
     * which exists only to cover that ambiguous gap).
     */
    public static function open(string $clientId): self
    {
        return new self(id: null, clientId: $clientId, status: InstallmentPlanStatus::ACTIVE);
    }

    /**
     * Reconstitutes an InstallmentPlan aggregate exactly as it exists in
     * storage, together with its already-persisted reserved and payment
     * SaleLines.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that
     * every argument is already-valid data read back from storage. This
     * method is not a business operation and application code must
     * never call it directly; only a repository implementation
     * reconstructing an aggregate from already-validated rows should
     * call it. It deliberately does NOT go through attachReservedLine()
     * or recordPayment() — those methods enforce live-operation rules
     * (the plan must currently be ACTIVE, a payment must not overpay)
     * that make no sense while restoring an already-settled or
     * already-cancelled plan's prior state; see the currency-check note
     * on assertAllLinesShareOneCurrency() above for the one check that
     * *does* still run here.
     *
     * @param SaleLine[] $reservedLines
     * @param SaleLine[] $paymentLines
     */
    public static function reconstituteFromStorage(
        string $id,
        string $clientId,
        InstallmentPlanStatus $status,
        array $reservedLines = [],
        array $paymentLines = [],
    ): self {
        return new self(
            id: $id,
            clientId: $clientId,
            status: $status,
            reservedLines: $reservedLines,
            paymentLines: $paymentLines,
        );
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('InstallmentPlan already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function status(): InstallmentPlanStatus
    {
        return $this->status;
    }

    /** @return SaleLine[] */
    public function reservedLines(): array
    {
        return $this->reservedLines;
    }

    /** @return SaleLine[] */
    public function paymentLines(): array
    {
        return $this->paymentLines;
    }

    /**
     * The currency established by whichever line was attached first —
     * either a reserved line or a payment line, whichever this plan saw
     * first. Null only for a completely empty plan (no lines at all
     * yet), in which case any currency is provisionally acceptable for
     * the next line attached.
     */
    private function planCurrency(): ?Currency
    {
        $firstLine = $this->reservedLines[0] ?? $this->paymentLines[0] ?? null;

        return $firstLine?->amount()->currency();
    }

    private function assertActive(string $operation): void
    {
        if ($this->status !== InstallmentPlanStatus::ACTIVE) {
            throw InstallmentPlanNotActiveException::becauseOperationRequiresActiveStatus(
                $this->id ?? '(unsaved)',
                $operation,
                $this->status,
            );
        }
    }

    private function assertClientMatches(SaleLine $line): void
    {
        if ($line->clientId() !== $this->clientId) {
            throw ClientMismatchException::becauseClientDoesNotMatch(
                $this->id ?? '(unsaved)',
                $this->clientId,
                $line->clientId(),
            );
        }
    }

    private function assertCurrencyMatches(SaleLine $line): void
    {
        $expectedCurrency = $this->planCurrency();

        if ($expectedCurrency === null) {
            return;
        }

        if (! $line->amount()->currency()->equals($expectedCurrency)) {
            throw CurrencyMismatchException::becauseCurrencyDoesNotMatch(
                $this->id ?? '(unsaved)',
                $expectedCurrency->code(),
                $line->amount()->currency()->code(),
            );
        }
    }

    /**
     * Attaches a reserved SaleLine to this plan — see the class
     * docblock for why this is the direct structural fix for the
     * source system's marker-string bug. Works identically whether this
     * is the plan's first reserved line or its fifth, at any point while
     * the plan is ACTIVE.
     */
    public function attachReservedLine(SaleLine $line): void
    {
        if ($line->type() !== SaleLineType::RESERVATION) {
            throw new \InvalidArgumentException(
                "InstallmentPlan::attachReservedLine() requires a RESERVATION SaleLine, got {$line->type()->value}."
            );
        }

        $this->assertActive('attachReservedLine');
        $this->assertClientMatches($line);
        $this->assertCurrencyMatches($line);

        $this->reservedLines[] = $line;
    }

    /**
     * Records a payment against this plan.
     *
     * Rejects (OverpaymentException) rather than silently accepting a
     * payment that would push the balance below zero — overpayment
     * handling (e.g. refunding the difference) is a real business
     * decision this design does not specify, so it is not guessed at
     * here.
     *
     * If this payment brings the outstanding balance to EXACTLY zero,
     * the plan transitions to COMPLETED and one new settlement SaleLine
     * (type=SALE) is generated per reserved line, returned to the
     * caller for persistence — this method only produces them, exactly
     * like Product::attemptConvertToSimple() builds a fresh Variation
     * with id=null for the caller/repository to persist. Otherwise
     * returns an empty array.
     *
     * THE ZERO CHECK IS EXACT, UNLIKE THE SOURCE SYSTEM (see design doc
     * §3.1): the source system tested settlement via
     * round($total_debt - $sum, 2) == 0, an exact floating-point
     * equality check that a single stotinka of cash-rounding could
     * silently break forever, leaving a fully-paid plan open. Because
     * every amount here is EasyCo\Pricing\Money — an integer count of
     * minor units, never a float — Money::isZero() is an exact integer
     * comparison with no rounding-drift failure mode at all.
     *
     * @return SaleLine[] Newly-generated settlement SaleLines, or an
     *   empty array if the plan is not yet fully paid.
     */
    public function recordPayment(SaleLine $paymentLine): array
    {
        if ($paymentLine->type() !== SaleLineType::INSTALLMENT_PAYMENT) {
            throw new \InvalidArgumentException(
                "InstallmentPlan::recordPayment() requires an INSTALLMENT_PAYMENT SaleLine, got {$paymentLine->type()->value}."
            );
        }

        $this->assertActive('recordPayment');
        $this->assertClientMatches($paymentLine);
        $this->assertCurrencyMatches($paymentLine);

        $currentBalance = $this->outstandingBalance();
        $projectedBalance = $currentBalance->subtract($paymentLine->amount());

        if ($projectedBalance->isNegative()) {
            throw OverpaymentException::becauseAmountExceedsOutstandingBalance(
                $this->id ?? '(unsaved)',
                $paymentLine->amount(),
                $currentBalance,
            );
        }

        $this->paymentLines[] = $paymentLine;

        if (! $projectedBalance->isZero()) {
            return [];
        }

        $this->status = InstallmentPlanStatus::COMPLETED;

        return $this->buildSettlementSaleLines();
    }

    /**
     * @return SaleLine[]
     */
    private function buildSettlementSaleLines(): array
    {
        $recordedAt = new DateTimeImmutable();
        $settlementLines = [];

        foreach ($this->reservedLines as $reservedLine) {
            $settlementLines[] = new SaleLine(
                id: null,
                transactionId: '',
                clientId: $reservedLine->clientId(),
                priceableId: $reservedLine->priceableId(),
                type: SaleLineType::SALE,
                status: SaleLineStatus::COMPLETED,
                quantity: $reservedLine->quantity(),
                amount: $reservedLine->amount(),
                profit: $reservedLine->profit(),
                recordedAt: $recordedAt,
                // Preserves the ORIGINAL reservation date (§3.5) —
                // deliberately NOT $recordedAt/"now".
                effectiveAt: $reservedLine->effectiveAt(),
                originatingReservationLineId: $reservedLine->id(),
            );
        }

        return $settlementLines;
    }

    /**
     * sum(reservedLines amounts) - sum(paymentLines amounts). Correct
     * with zero lines of either kind: a plan with reservations but no
     * payments yet returns the full reserved total; a plan that somehow
     * has payments but no reservations (not a real workflow, but not
     * this method's job to forbid) returns a negative balance rather
     * than dividing by zero or similar. A completely empty, just-opened
     * plan has no lines to derive a currency from at all — falls back to
     * EasyCo\Pricing\DefaultCurrency::get(), the host application's own
     * configured store currency, rather than any currency hardcoded
     * here. A previous version of this method hardcoded BGN for exactly
     * this fallback; that stopped being legal tender in Bulgaria on
     * 2026-02-01 (Bulgaria adopted the euro on 2026-01-01, with a
     * one-month dual-circulation period), which made the hardcode
     * factually wrong, not just provisional. Hardcoding a replacement
     * currency here (EUR or otherwise) would only move the same problem
     * to the next currency this project eventually needs — see
     * DefaultCurrency's own docblock. The moment any real line is
     * attached to this plan, that line's currency becomes authoritative
     * (via planCurrency()) and this fallback is never consulted again;
     * see open()'s docblock for the longer-term fix once this
     * aggregate's real construction call site exists.
     */
    public function outstandingBalance(): Money
    {
        $currency = $this->planCurrency() ?? DefaultCurrency::get();

        $reservedTotal = self::sumAmounts($this->reservedLines, $currency);
        $paymentTotal = self::sumAmounts($this->paymentLines, $currency);

        return $reservedTotal->subtract($paymentTotal);
    }

    /**
     * @param SaleLine[] $lines
     */
    private static function sumAmounts(array $lines, Currency $currency): Money
    {
        $sum = Money::zero($currency);

        foreach ($lines as $line) {
            $sum = $sum->add($line->amount());
        }

        return $sum;
    }

    /**
     * ACTIVE -> CANCELLED. Deliberately NOT idempotent: calling this on
     * an already COMPLETED or CANCELLED plan throws
     * InstallmentPlanNotActiveException rather than silently doing
     * nothing, because a caller cancelling a plan it believes is still
     * active — but which was, unknown to it, already settled or
     * cancelled by something else — has a real bug worth surfacing, not
     * a state worth masking.
     */
    public function cancel(): void
    {
        $this->assertActive('cancel');

        $this->status = InstallmentPlanStatus::CANCELLED;
    }
}
