<?php

namespace EasyCo\OperationalSales\Tests;

use EasyCo\OperationalSales\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function test_creating_a_client_with_a_valid_name_succeeds(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov');

        $this->assertNull($client->id());
        $this->assertSame('Ivan Petrov', $client->name());
    }

    public function test_creating_a_client_with_an_empty_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(id: null, name: '');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov');
        $client->assignId('1');

        $this->assertSame('1', $client->id());

        $this->expectException(\LogicException::class);
        $client->assignId('2');
    }

    public function test_change_name_updates_the_name(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov');

        $client->changeName('Georgi Ivanov');

        $this->assertSame('Georgi Ivanov', $client->name());
    }

    public function test_change_name_with_the_exact_same_value_is_a_true_no_op(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov');

        $client->changeName('Ivan Petrov');

        $this->assertSame('Ivan Petrov', $client->name());
    }

    public function test_change_name_with_an_empty_string_throws(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov');

        $this->expectException(\InvalidArgumentException::class);
        $client->changeName('');
    }

    public function test_names_in_any_script_or_case_are_all_accepted(): void
    {
        // Documents the §3.7 decision: no script/case/format rule was
        // imposed on Client.name, unlike Product.slug's strict Unicode
        // pattern. A lowercase-Cyrillic-only rule was proposed and
        // explicitly rejected during design review.
        $cyrillic = new Client(id: null, name: 'Иван Петров');
        $latin = new Client(id: null, name: 'John Smith');
        $mixedCase = new Client(id: null, name: 'MaRiya IVANOVA');

        $this->assertSame('Иван Петров', $cyrillic->name());
        $this->assertSame('John Smith', $latin->name());
        $this->assertSame('MaRiya IVANOVA', $mixedCase->name());
    }

    public function test_reconstitute_from_storage_rebuilds_a_client_with_its_id_already_set(): void
    {
        $client = Client::reconstituteFromStorage('42', 'Ivan Petrov');

        $this->assertSame('42', $client->id());
        $this->assertSame('Ivan Petrov', $client->name());
    }

    // --- accountId -----------------------------------------------------------

    public function test_null_account_id_succeeds_by_default(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov');

        $this->assertNull($client->accountId());
    }

    public function test_a_real_account_id_succeeds(): void
    {
        $client = new Client(id: null, name: 'Ivan Petrov', accountId: '42');

        $this->assertSame('42', $client->accountId());
    }

    public function test_empty_string_account_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Client(id: null, name: 'Ivan Petrov', accountId: '');
    }

    public function test_reconstitute_from_storage_round_trips_account_id(): void
    {
        $client = Client::reconstituteFromStorage('42', 'Ivan Petrov', '7');

        $this->assertSame('7', $client->accountId());
    }
}
