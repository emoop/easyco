<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Exceptions\EmailAlreadyRegisteredException;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentAccountRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): AccountRepository
    {
        return app(AccountRepository::class);
    }

    public function test_the_real_email_column_has_a_unique_constraint(): void
    {
        // Confirms the actual constraint this repository's collision
        // detection depends on — not just trusting the migration file
        // (CLAUDE.md rule 2/project convention).
        $createTable = DB::select('SHOW CREATE TABLE accounts')[0]->{'Create Table'};

        $this->assertStringContainsString('UNIQUE KEY `accounts_email_unique`', $createTable);
    }

    public function test_save_insert_then_find_by_id_round_trips(): void
    {
        $account = Account::register('user@example.com', 'a-hashed-value');

        $this->repository()->save($account);

        $this->assertNotNull($account->id());

        $reloaded = $this->repository()->findById($account->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($account->id(), $reloaded->id());
        $this->assertSame('user@example.com', $reloaded->email());
        $this->assertSame('a-hashed-value', $reloaded->passwordHash());
    }

    public function test_find_by_email_round_trips_and_is_case_insensitive(): void
    {
        $account = Account::register('User@Example.com', 'a-hashed-value');
        $this->repository()->save($account);

        $found = $this->repository()->findByEmail('USER@EXAMPLE.COM');

        $this->assertNotNull($found);
        $this->assertSame($account->id(), $found->id());
        $this->assertSame('user@example.com', $found->email());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_find_by_email_for_a_nonexistent_email_returns_null(): void
    {
        $this->assertNull($this->repository()->findByEmail('nobody@example.com'));
    }

    public function test_saving_a_duplicate_email_throws_and_persists_no_duplicate_row(): void
    {
        $first = Account::register('user@example.com', 'hash-one');
        $this->repository()->save($first);

        $second = Account::register('USER@EXAMPLE.COM', 'hash-two');

        $this->expectException(EmailAlreadyRegisteredException::class);

        try {
            $this->repository()->save($second);
        } finally {
            $this->assertSame(1, AccountModel::count());
        }
    }
}
