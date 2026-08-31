<?php

namespace EasyCo\Account\Tests;

use EasyCo\Account\Account;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function test_registering_with_a_valid_email_and_password_hash_succeeds(): void
    {
        $account = Account::register('user@example.com', 'a-hashed-value');

        $this->assertNull($account->id());
        $this->assertSame('user@example.com', $account->email());
        $this->assertSame('a-hashed-value', $account->passwordHash());
    }

    public function test_email_is_normalized_to_lowercase(): void
    {
        $account = Account::register('Foo@Example.com', 'a-hashed-value');

        $this->assertSame('foo@example.com', $account->email());
    }

    public function test_an_invalid_email_format_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Account::register('not-an-email', 'a-hashed-value');
    }

    public function test_an_empty_password_hash_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Account::register('user@example.com', '');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $account = Account::register('user@example.com', 'a-hashed-value');
        $account->assignId('1');

        $this->assertSame('1', $account->id());

        $this->expectException(LogicException::class);
        $account->assignId('2');
    }

    public function test_reconstitute_from_storage_rebuilds_an_account_with_its_id_already_set(): void
    {
        $account = Account::reconstituteFromStorage('42', 'user@example.com', 'a-hashed-value');

        $this->assertSame('42', $account->id());
        $this->assertSame('user@example.com', $account->email());
        $this->assertSame('a-hashed-value', $account->passwordHash());
    }

    public function test_reconstitute_from_storage_also_normalizes_email_to_lowercase(): void
    {
        // Defensive, not expected in practice — findByEmail() already
        // normalizes before querying, so stored rows should already be
        // lowercase — but the domain class itself should never trust a
        // caller not to violate that invariant.
        $account = Account::reconstituteFromStorage('42', 'Foo@Example.com', 'a-hashed-value');

        $this->assertSame('foo@example.com', $account->email());
    }
}
