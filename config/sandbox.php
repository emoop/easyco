<?php

/**
 * The sandbox storefront's own flag — Prompt D, D1.
 *
 * WHAT THIS GATES: a read-only, customer-facing preview of REAL products
 * (the sandbox product list and product page), so the catalog can be
 * looked at through a page a customer would recognize, before the real
 * storefront (storefront-frontend-design.md) exists.
 *
 * NOTHING HERE IS THE REAL STOREFRONT. The real storefront's URLs
 * (`/`, `/product/{slug}`, `/product-category/{path}`, `/product-tag/
 * {slug}` — storefront-frontend-design.md §7) are deliberately NOT
 * touched by anything in this sandbox; every sandbox route lives under
 * `/_sandbox` (D2) so the two can never collide.
 *
 * DEFAULT IS OFF, IN EVERY ENVIRONMENT, INCLUDING LOCAL. Enabling it is
 * an explicit act: set EASYCO_SANDBOX=true in .env (or the real process
 * environment) and clear the config cache. A flag that defaults on would
 * make "the sandbox is reachable" a fact nobody chose.
 *
 * TWO conditions, not one, and they are both enforced in exactly one
 * place (App\Providers\SandboxServiceProvider::sandboxRoutesEnabled()):
 * this flag AND `! app()->isProduction()`. A misconfigured production
 * deployment that sets EASYCO_SANDBOX=true by accident still exposes
 * nothing — the routes are never registered there at all.
 *
 * REMOVABILITY (D2's own requirement): deleting this file, the
 * SandboxServiceProvider, its one entry in bootstrap/providers.php, and
 * the App\Sandbox/ + resources/views/sandbox/ folders removes the
 * sandbox completely, with nothing else in the codebase referencing it.
 * Adding the EASYCO_SANDBOX key to .env.example was considered and
 * deliberately NOT done — the key would then outlive the feature on
 * every installation that merges this branch, and the flag's own
 * docblock (this one) is where it is documented instead.
 */
return [
    'enabled' => env('EASYCO_SANDBOX', false),
];
