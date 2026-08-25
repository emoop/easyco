<?php

namespace EasyCo\OperationalSales;

use EasyCo\OperationalSales\Enums\Channel;

/**
 * The Transaction aggregate root — owns the SaleLines recorded against a
 * single POS or Web checkout event.
 *
 * Unlike Product::addStandardVariation() (which is the ONLY way a
 * Variation is constructed, because Product must validate its combination
 * against declared axes and check for duplicates), SaleLine has no
 * aggregate-level uniqueness invariant for Transaction to enforce — a
 * SaleLine is constructed directly by the caller (see SaleLine's own
 * constructor and validation). addSaleLine() therefore only needs to
 * confirm the line actually belongs to this Transaction: it checks the
 * line's transactionId against this Transaction's own id, or, if this
 * Transaction has no id yet (not yet persisted), accepts the line
 * provisionally — mirroring how Product::createSimple() accepts a
 * not-yet-persisted parent id for its Universal variation.
 */
final class Transaction
{
    /** @var array<string, SaleLine> keyed by SaleLine id once persisted, or a temporary spl_object_id() before */
    private array $saleLines = [];

    public function __construct(
        private ?string $id,
        private Channel $channel,
    ) {
    }

    /**
     * Reconstitutes a Transaction aggregate exactly as it exists in
     * storage, together with its already-persisted SaleLines.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that
     * every argument is already-valid data read back from storage. This
     * method is not a business operation and application code must never
     * call it directly; only a repository implementation reconstructing
     * an aggregate from already-validated rows should call it.
     *
     * @param SaleLine[] $saleLines Already-reconstituted SaleLines (e.g.
     *   via SaleLine::reconstituteFromStorage()), each with its real
     *   persisted id.
     */
    public static function reconstituteFromStorage(string $id, Channel $channel, array $saleLines = []): self
    {
        $transaction = new self(id: $id, channel: $channel);

        foreach ($saleLines as $saleLine) {
            $transaction->saleLines[$saleLine->id()] = $saleLine;
        }

        return $transaction;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Transaction already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;

        // Back-fill transactionId on any not-yet-persisted SaleLine
        // created before this Transaction had an id (see the class
        // docblock's note on provisional addSaleLine() acceptance) —
        // mirrors Product::assignId() back-filling
        // Variation::assignProductId().
        foreach ($this->saleLines as $saleLine) {
            if ($saleLine->transactionId() === '') {
                $saleLine->assignTransactionId($id);
            }
        }
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    /** @return SaleLine[] */
    public function saleLines(): array
    {
        return array_values($this->saleLines);
    }

    /**
     * Attaches a SaleLine to this Transaction — the only way a SaleLine
     * becomes part of one. See the class docblock for why this only
     * checks ownership (transactionId match) rather than any
     * aggregate-level uniqueness invariant.
     */
    public function addSaleLine(SaleLine $line): void
    {
        if ($this->id !== null && $line->transactionId() !== $this->id) {
            throw new \InvalidArgumentException(
                "SaleLine transactionId \"{$line->transactionId()}\" does not match this Transaction's id \"{$this->id}\"."
            );
        }

        $key = $line->id() ?? (string) spl_object_id($line);
        $this->saleLines[$key] = $line;
    }
}
