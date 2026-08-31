<?php

namespace EasyCo\Account;

use InvalidArgumentException;
use LogicException;

/**
 * The Account entity — a storefront customer's identity: email +
 * password hash only (see account-domain-design.md §4 for the
 * confirmed V1 scope). Deliberately minimal, mirroring
 * EasyCo\OperationalSales\Client's shape: a private-by-convention
 * constructor reached only via the two static factories below.
 *
 * The domain layer never sees or validates a plaintext password —
 * only the already-hashed string. Hashing happens one layer up, via
 * Contracts\PasswordHasher, before this class is ever constructed;
 * see account-domain-design.md §3 for why that split exists.
 */
final class Account
{
    public function __construct(
        private ?string $id,
        private string $email,
        private readonly string $passwordHash,
    ) {
        $this->email = self::normalizeAndValidateEmail($email);
        self::assertValidPasswordHash($passwordHash);
    }

    public static function register(string $email, string $passwordHash): self
    {
        return new self(id: null, email: $email, passwordHash: $passwordHash);
    }

    /**
     * Reconstitutes an Account exactly as it exists in storage.
     *
     * PERSISTENCE-LAYER ONLY — trusts the caller (a repository) that
     * the given data is already-valid data read back from storage.
     * This method is not a business operation and application code
     * must never call it directly; only a repository implementation
     * reconstructing this entity from an already-validated row should
     * call it.
     */
    public static function reconstituteFromStorage(string $id, string $email, string $passwordHash): self
    {
        return new self(id: $id, email: $email, passwordHash: $passwordHash);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function assignId(string $id): void
    {
        if ($this->id !== null) {
            throw new LogicException('Account already has an id; assignId() is a one-time operation.');
        }

        $this->id = $id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    private static function normalizeAndValidateEmail(string $email): string
    {
        $normalized = strtolower($email);

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Account email \"{$email}\" is not a valid email address.");
        }

        return $normalized;
    }

    private static function assertValidPasswordHash(string $passwordHash): void
    {
        if ($passwordHash === '') {
            throw new InvalidArgumentException('Account passwordHash must not be empty.');
        }
    }
}
