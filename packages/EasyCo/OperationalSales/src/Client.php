<?php

namespace EasyCo\OperationalSales;

/**
 * The Client entity.
 *
 * AUTHORITATIVE MODEL (see operational-sales-domain-design.md §2 / §3.7):
 * a Client is deliberately minimal — an id and a free-text name. Per §3.7,
 * an earlier draft of this design proposed a lowercase-Cyrillic format rule
 * for the name, and that was explicitly rejected during design review: it
 * was a legitimate operational habit for one specific store, not a domain
 * invariant every future EasyCo merchant should be forced into. This class
 * therefore validates only that the name is non-empty — no script, case,
 * or format rule of any kind.
 *
 * accountId RESOLVES checkout-domain-design.md §8.1's Account<->Client
 * link — nullable (a POS-only Client has none), set at most once (via
 * construction or reconstituteFromStorage()) and NEVER reassigned
 * afterward: same "set once, never changed" posture as id itself. If a
 * real need to move a Client's linked account ever comes up, that is a
 * separate future decision, not assumed here.
 */
final class Client
{
    public function __construct(
        private ?string $id,
        private string $name,
        private readonly ?string $accountId = null,
    ) {
        self::assertValidName($name);
        self::assertAccountIdNotEmptyString($accountId);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Client name must not be empty.');
        }
    }

    /**
     * Identical rule to EasyCo\Address\Address's own: null means no
     * linked Account (a POS-only or not-yet-linked Client), an empty
     * string is never a valid way to express that.
     */
    private static function assertAccountIdNotEmptyString(?string $accountId): void
    {
        if ($accountId === '') {
            throw new \InvalidArgumentException('Client accountId must not be an empty string; use null for no linked account.');
        }
    }

    /**
     * Reconstitutes a Client entity exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that the
     * given data is already-valid data read back from storage. This method
     * is not a business operation and application code must never call it
     * directly; only a repository implementation reconstructing this
     * entity from an already-validated row should call it.
     */
    public static function reconstituteFromStorage(string $id, string $name, ?string $accountId = null): self
    {
        return new self(id: $id, name: $name, accountId: $accountId);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Client already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function accountId(): ?string
    {
        return $this->accountId;
    }

    /**
     * Renames the Client after creation, subject to the same validation as
     * construction (assertValidName()). Calling this with the exact
     * current value is a harmless no-op: it returns immediately without
     * even re-running validation, so it can never fail on an
     * already-valid, unchanged name.
     */
    public function changeName(string $newName): void
    {
        if ($newName === $this->name) {
            return;
        }

        self::assertValidName($newName);
        $this->name = $newName;
    }
}
