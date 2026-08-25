<?php

namespace EasyCo\OperationalSales\Contracts;

use EasyCo\OperationalSales\InstallmentPlan;

interface InstallmentPlanRepository
{
    /**
     * "At most one ACTIVE InstallmentPlan per client" — previously
     * flagged here as an open, unenforced gap — is now a real, DB-level
     * guarantee: operational_sales_installment_plans.active_client_id
     * carries a UNIQUE index (see the migration that added it), and
     * EloquentInstallmentPlanRepository::save() translates a violation
     * into ClientAlreadyHasActiveInstallmentPlanException. This is NOT
     * enforced by the InstallmentPlan aggregate itself (open() still has
     * no knowledge of any other plan for the same client) — it is
     * enforced here, at the persistence boundary, which is also the only
     * place that can actually hold under concurrent writes (an app-layer
     * check-then-insert has the same TOCTOU race the DB constraint
     * exists to close).
     */
    public function save(InstallmentPlan $plan): void;

    public function findById(string $id): ?InstallmentPlan;

    /**
     * The hot path operational_sales_installment_plans' (client_id,
     * status) index exists for: "does this client have an active plan
     * right now."
     *
     * Because save() now enforces "at most one ACTIVE plan per client"
     * at the DB level (see save()'s docblock), this method should never
     * actually encounter more than one ACTIVE row for a given client
     * through normal repository usage. It still orders by id descending
     * as a defensive fallback (not a documented guarantee to rely on) in
     * case that invariant is ever bypassed outside this repository (e.g.
     * a direct DB write) — it does not error in that case, it just
     * returns the most recently created ACTIVE plan.
     */
    public function findActiveByClientId(string $clientId): ?InstallmentPlan;
}
