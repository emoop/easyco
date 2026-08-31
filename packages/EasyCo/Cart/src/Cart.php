<?php

namespace EasyCo\Cart;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * The Cart aggregate root — owns the CartLines a guest or a logged-in
 * customer has added. Closely mirrors
 * OperationalSales\Transaction/SaleLine's aggregate shape (see that
 * pair's docblocks) — same "accept a line provisionally before this
 * aggregate has its own id, then back-fill on assignId()" pattern.
 *
 * Unlike Transaction::addSaleLine() (no aggregate-level uniqueness
 * invariant), Cart DOES enforce one: at most one CartLine per
 * variationId. addLine() for an already-present variation merges
 * quantities into the existing line instead of appending a second one
 * — see addLine()'s own docblock.
 *
 * EXACTLY ONE OF accountId/sessionToken, NEVER BOTH, NEVER NEITHER —
 * see cart-domain-design.md §2. There is no portable DB-level XOR
 * constraint, so this constructor is the real guard, not the schema.
 */
final class Cart
{
    /** @var array<string, CartLine> keyed by CartLine variationId */
    private array $lines = [];

    public function __construct(
        private ?string $id,
        private readonly ?string $accountId,
        private readonly ?string $sessionToken,
        private DateTimeImmutable $expiresAt,
    ) {
        if (($accountId === null) === ($sessionToken === null)) {
            throw new InvalidArgumentException(
                'Cart must have exactly one of accountId or sessionToken, never both, never neither.'
            );
        }

        if ($accountId === '') {
            throw new InvalidArgumentException('Cart accountId must not be an empty string; use null for a guest cart.');
        }

        if ($sessionToken === '') {
            throw new InvalidArgumentException('Cart sessionToken must not be an empty string; use null for an account cart.');
        }
    }

    public static function forAccount(string $accountId, DateTimeImmutable $expiresAt): self
    {
        return new self(id: null, accountId: $accountId, sessionToken: null, expiresAt: $expiresAt);
    }

    public static function forGuest(string $sessionToken, DateTimeImmutable $expiresAt): self
    {
        return new self(id: null, accountId: null, sessionToken: $sessionToken, expiresAt: $expiresAt);
    }

    /**
     * Reconstitutes a Cart aggregate exactly as it exists in storage,
     * together with its already-persisted CartLines.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that
     * every argument is already-valid data read back from storage.
     * This method is not a business operation and application code
     * must never call it directly; only a repository implementation
     * reconstructing an aggregate from already-validated rows should
     * call it.
     *
     * @param CartLine[] $lines Already-reconstituted CartLines, each
     *   with its real persisted id.
     */
    public static function reconstituteFromStorage(
        string $id,
        ?string $accountId,
        ?string $sessionToken,
        DateTimeImmutable $expiresAt,
        array $lines = [],
    ): self {
        $cart = new self(id: $id, accountId: $accountId, sessionToken: $sessionToken, expiresAt: $expiresAt);

        foreach ($lines as $line) {
            $cart->lines[$line->variationId()] = $line;
        }

        return $cart;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Cart already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;

        // Back-fill cartId on any not-yet-persisted line created
        // before this Cart had an id — mirrors
        // Transaction::assignId() back-filling SaleLine::transactionId().
        foreach ($this->lines as $line) {
            if ($line->cartId() === '') {
                $line->assignCartId($id);
            }
        }
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    public function sessionToken(): ?string
    {
        return $this->sessionToken;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function refreshExpiry(DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    /** @return CartLine[] */
    public function lines(): array
    {
        return array_values($this->lines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function totalQuantity(): int
    {
        return array_sum(array_map(fn (CartLine $line) => $line->quantity(), $this->lines));
    }

    /**
     * Attaches a CartLine to this Cart. If a line for the same
     * variationId already exists, that line's quantity is increased
     * by the new line's quantity instead of appending a second line —
     * a Cart has at most one line per variation, a real aggregate
     * invariant Transaction/SaleLine has no equivalent of (see class
     * docblock). Ownership check on cartId mirrors
     * Transaction::addSaleLine() exactly: only enforced once this Cart
     * has a real id; a not-yet-persisted Cart accepts the line
     * provisionally.
     */
    public function addLine(CartLine $line): void
    {
        if ($this->id !== null && $line->cartId() !== $this->id) {
            throw new InvalidArgumentException(
                "CartLine cartId \"{$line->cartId()}\" does not match this Cart's id \"{$this->id}\"."
            );
        }

        $existing = $this->lines[$line->variationId()] ?? null;

        if ($existing !== null) {
            $existing->increaseQuantity($line->quantity());

            return;
        }

        $this->lines[$line->variationId()] = $line;
    }

    public function removeLine(string $variationId): void
    {
        unset($this->lines[$variationId]);
    }

    public function updateLineQuantity(string $variationId, int $quantity): void
    {
        $line = $this->lines[$variationId] ?? null;

        if ($line === null) {
            throw new InvalidArgumentException("Variation \"{$variationId}\" is not in this cart.");
        }

        $line->setQuantity($quantity);
    }
}
