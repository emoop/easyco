<?php

namespace App\Services;

use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;

/**
 * Resolves the OperationalSales.Client for a checkout, per
 * checkout-domain-design.md §8.1.
 *
 * CLIENT.NAME IS SET ONLY AT CREATION, NEVER UPDATED AFTERWARD ON A
 * REPEAT CHECKOUT — domain-owner decision, confirmed explicitly (not
 * assumed): recipientName is who receives THIS specific delivery (e.g.
 * a gift order to someone else's name/address), not necessarily the
 * account holder's own identity. Automatically syncing Client.name to
 * the latest recipientName would make a returning customer's own
 * recorded identity flicker between different people's names depending
 * on who happened to receive their last order — worse for reporting,
 * not better. If Client.name ever needs to reliably reflect the account
 * holder's own identity, the correct fix is a separate `name` field on
 * Account itself (a future, separate task) — not resolved here.
 *
 * GUEST CHECKOUT: always a fresh Client, no reuse attempted across
 * separate guest checkouts (§8.1 — no cross-order guest deduplication
 * in V1, e.g. by email; flagged there as deferred, not solved here
 * either).
 *
 * Same shape as App\Services\CatalogScopeResolver: a small, standalone,
 * constructor-injected app-layer service, not tied to any one caller —
 * reusable by the future Checkout orchestration transaction this
 * resolver is a building block for.
 */
class ClientResolver
{
    public function __construct(
        private readonly ClientRepository $clients,
    ) {
    }

    /**
     * @param ?string $accountId The logged-in Account's id, or null for
     *   a guest checkout.
     * @param string $recipientName THIS checkout's recipient name — used
     *   as the new Client's name ONLY when one doesn't exist yet (a
     *   guest, or an Account's very first checkout). Ignored entirely if
     *   an existing Client is found for this accountId.
     */
    public function resolve(?string $accountId, string $recipientName): Client
    {
        if ($accountId === null) {
            $client = new Client(id: null, name: $recipientName, accountId: null);
            $this->clients->save($client);

            return $client;
        }

        $existing = $this->clients->findByAccountId($accountId);
        if ($existing !== null) {
            return $existing;
        }

        $client = new Client(id: null, name: $recipientName, accountId: $accountId);
        $this->clients->save($client);

        return $client;
    }
}
