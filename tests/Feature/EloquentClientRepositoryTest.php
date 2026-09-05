<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentClientRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): ClientRepository
    {
        return app(ClientRepository::class);
    }

    private function accountId(string $email): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    public function test_save_then_find_by_id_round_trips_correctly(): void
    {
        $client = new Client(id: null, name: 'Иван Петров');

        $this->repository()->save($client);

        $this->assertNotNull($client->id());

        $reloaded = $this->repository()->findById($client->id());

        $this->assertNotNull($reloaded);
        $this->assertSame($client->id(), $reloaded->id());
        $this->assertSame('Иван Петров', $reloaded->name());
    }

    public function test_find_by_id_returns_null_for_a_nonexistent_id(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_saving_an_already_persisted_client_updates_it_in_place(): void
    {
        $client = new Client(id: null, name: 'Original Name');
        $this->repository()->save($client);

        $client->changeName('Updated Name');
        $this->repository()->save($client);

        $reloaded = $this->repository()->findById($client->id());

        $this->assertSame('Updated Name', $reloaded->name());
    }

    public function test_two_clients_can_share_the_same_name(): void
    {
        $first = new Client(id: null, name: 'Georgi Ivanov');
        $second = new Client(id: null, name: 'Georgi Ivanov');

        $this->repository()->save($first);
        $this->repository()->save($second);

        $this->assertNotSame($first->id(), $second->id());
    }

    public function test_save_then_find_by_id_round_trips_a_client_with_a_real_account_id(): void
    {
        $accountId = $this->accountId('buyer@example.com');
        $client = new Client(id: null, name: 'Ivan Petrov', accountId: $accountId);

        $this->repository()->save($client);

        $reloaded = $this->repository()->findById($client->id());
        $this->assertSame($accountId, $reloaded->accountId());
    }

    public function test_find_by_account_id_returns_the_right_client(): void
    {
        $accountId = $this->accountId('buyer@example.com');
        $client = new Client(id: null, name: 'Ivan Petrov', accountId: $accountId);
        $this->repository()->save($client);

        $found = $this->repository()->findByAccountId($accountId);

        $this->assertNotNull($found);
        $this->assertSame($client->id(), $found->id());
    }

    public function test_find_by_account_id_returns_null_for_an_account_with_no_client_yet(): void
    {
        $accountId = $this->accountId('nobody@example.com');

        $this->assertNull($this->repository()->findByAccountId($accountId));
    }

    public function test_two_clients_with_the_same_account_id_is_rejected_by_the_database(): void
    {
        $accountId = $this->accountId('buyer@example.com');
        $first = new Client(id: null, name: 'Ivan Petrov', accountId: $accountId);
        $this->repository()->save($first);

        $second = new Client(id: null, name: 'Someone Else', accountId: $accountId);

        $this->expectException(QueryException::class);

        $this->repository()->save($second);
    }
}
