<?php

namespace Tests\Feature;

use App\Services\ClientResolver;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\ClientResolver — resolves the Account<->Client link per
 * checkout-domain-design.md §8.1. Real MySQL Feature test, not a mocked
 * Unit test — the whole point is proving real find-or-create behavior
 * against the real ClientRepository.
 */
class ClientResolverTest extends TestCase
{
    use RefreshDatabase;

    private function accountId(string $email): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    public function test_a_guest_checkout_creates_a_genuinely_new_client_every_call_even_with_the_same_recipient_name(): void
    {
        $first = app(ClientResolver::class)->resolve(null, 'Ivan Ivanov');
        $second = app(ClientResolver::class)->resolve(null, 'Ivan Ivanov');

        $this->assertNotNull($first->id());
        $this->assertNotNull($second->id());
        $this->assertNotSame($first->id(), $second->id());
    }

    public function test_an_accounts_first_checkout_creates_a_client_with_the_given_recipient_name(): void
    {
        $accountId = $this->accountId('buyer@example.com');

        $client = app(ClientResolver::class)->resolve($accountId, 'Anelia Georgieva');

        $this->assertNotNull($client->id());
        $this->assertSame('Anelia Georgieva', $client->name());
        $this->assertSame($accountId, $client->accountId());
    }

    /**
     * The real proof of the "never sync" decision: a gift order on the
     * second checkout, naming a different recipient, must never
     * overwrite the account holder's own recorded identity.
     */
    public function test_a_second_checkout_naming_a_different_gift_recipient_does_not_overwrite_the_clients_name(): void
    {
        $accountId = $this->accountId('buyer@example.com');

        $first = app(ClientResolver::class)->resolve($accountId, 'Anelia');
        $second = app(ClientResolver::class)->resolve($accountId, 'Milena');

        $this->assertSame($first->id(), $second->id());
        $this->assertSame('Anelia', $second->name());
    }

    public function test_only_one_client_row_exists_for_an_account_after_two_resolve_calls(): void
    {
        $accountId = $this->accountId('buyer@example.com');

        app(ClientResolver::class)->resolve($accountId, 'Anelia');
        app(ClientResolver::class)->resolve($accountId, 'Milena');

        $this->assertSame(1, ClientModel::where('account_id', $accountId)->count());
    }
}
