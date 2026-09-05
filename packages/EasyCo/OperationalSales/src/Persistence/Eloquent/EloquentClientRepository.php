<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;

/**
 * Maps the Client entity onto operational_sales_clients. The simplest
 * repository in this package — Client has no child rows and
 * operational_sales_clients.name carries no unique constraint (two
 * clients can share a name, per operational-sales-domain-design.md
 * §3.7/the clients migration), so there is no collision to detect and no
 * transaction wrapping is needed beyond Eloquent's own single-row write.
 */
final class EloquentClientRepository implements ClientRepository
{
    public function save(Client $client): void
    {
        $model = $client->id() !== null
            ? ClientModel::findOrFail($client->id())
            : new ClientModel();

        $model->name = $client->name();
        $model->account_id = $client->accountId();
        $model->save();

        if ($client->id() === null) {
            $client->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Client
    {
        $model = ClientModel::find($id);

        return $model !== null ? $this->toDomainClient($model) : null;
    }

    public function findByAccountId(string $accountId): ?Client
    {
        $model = ClientModel::where('account_id', $accountId)->first();

        return $model !== null ? $this->toDomainClient($model) : null;
    }

    private function toDomainClient(ClientModel $model): Client
    {
        return Client::reconstituteFromStorage(
            (string) $model->id,
            $model->name,
            $model->account_id !== null ? (string) $model->account_id : null,
        );
    }
}
