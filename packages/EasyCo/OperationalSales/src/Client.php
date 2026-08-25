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
 */
final class Client
{
    public function __construct(
        private ?string $id,
        private string $name,
    ) {
        self::assertValidName($name);
    }

    private static function assertValidName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Client name must not be empty.');
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
    public static function reconstituteFromStorage(string $id, string $name): self
    {
        return new self(id: $id, name: $name);
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
