<?php

namespace Tests\Feature;

use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentClientRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function repository(): ClientRepository
    {
        return app(ClientRepository::class);
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
}
