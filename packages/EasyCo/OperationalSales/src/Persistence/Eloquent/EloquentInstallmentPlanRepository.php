<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use EasyCo\OperationalSales\Contracts\InstallmentPlanRepository;
use EasyCo\OperationalSales\Enums\InstallmentPlanStatus;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Enums\SaleLineType;
use EasyCo\OperationalSales\Exceptions\ClientAlreadyHasActiveInstallmentPlanException;
use EasyCo\OperationalSales\InstallmentPlan;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Maps the InstallmentPlan aggregate onto
 * operational_sales_installment_plans, and links its reservedLines/
 * paymentLines to it via operational_sales_sale_lines.installment_plan_id.
 *
 * A REAL ORDERING CONSTRAINT, by design, not an oversight: SaleLine has
 * no installmentPlanId field at all (see SaleLineModel's docblock) — the
 * association is owned by InstallmentPlan, one-directionally. That means
 * this repository cannot create a SaleLine row; it can only point an
 * ALREADY-PERSISTED one at this plan. Persisting a SaleLine is
 * EloquentTransactionRepository's job, and operational_sales_sale_lines.
 * transaction_id is NOT NULL — a SaleLine cannot even be inserted without
 * a real, already-persisted Transaction. So the caller/orchestration
 * layer MUST save() each reservedLine/paymentLine's owning Transaction
 * (via TransactionRepository) BEFORE save()ing the InstallmentPlan that
 * references those lines. save() below enforces this explicitly — it
 * throws a clear RuntimeException naming the exact constraint violated
 * rather than silently skipping an unpersisted line (which would leave
 * the plan permanently missing that line's linkage) or silently trying
 * to persist the line itself (which would reach outside this
 * repository's own aggregate boundary and still fail on the NOT NULL
 * transaction_id column regardless).
 *
 * Per the current task's scope note: orchestrating
 * InstallmentPlan::recordPayment()'s newly-RETURNED settlement SaleLines
 * (which are never reservedLines/paymentLines, so this constraint does
 * not apply to them at all) is a separate, later, application-service
 * concern — not implemented here.
 *
 * UNIQUE-CONSTRAINT COLLISION HANDLING: operational_sales_installment_plans.
 * active_client_id (see the migration adding it) is the DB-level
 * guarantee behind "at most one ACTIVE InstallmentPlan per client" — not
 * enforced by the domain aggregate itself, which has no way to see any
 * other plan for the same client. save() below sets active_client_id to
 * client_id only while status=ACTIVE (null otherwise — the same
 * "derived marker projection" relationship attribute_signature has to
 * attribute_assignments in Catalog), and translates a caught violation
 * of the UNIQUE(active_client_id) index into
 * ClientAlreadyHasActiveInstallmentPlanException, using the same shared
 * SQLSTATE-23000-plus-driver-code primary check
 * (isPossibleUniqueConstraintViolation()) as
 * EasyCo\Catalog\Persistence\Eloquent\EloquentProductRepository, with its
 * own dual-format (MySQL named-index / SQLite table.column) secondary
 * narrowing.
 */
final class EloquentInstallmentPlanRepository implements InstallmentPlanRepository
{
    public function save(InstallmentPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $planModel = $plan->id() !== null
                ? InstallmentPlanModel::findOrFail($plan->id())
                : new InstallmentPlanModel();

            $planModel->client_id = $plan->clientId();
            $planModel->status = $plan->status()->value;
            $planModel->active_client_id = $plan->status() === InstallmentPlanStatus::ACTIVE
                ? $plan->clientId()
                : null;

            try {
                $planModel->save();
            } catch (QueryException $e) {
                if ($this->isActiveClientUniqueViolation($e)) {
                    throw ClientAlreadyHasActiveInstallmentPlanException::fromDatabaseConstraintViolation(
                        $plan->clientId(),
                        $e
                    );
                }

                throw $e;
            }

            if ($plan->id() === null) {
                $plan->assignId((string) $planModel->id);
            }

            $this->linkSaleLinesToPlan($planModel, $plan);
        });
    }

    /**
     * Detects a violation of os_installment_plans_active_client_id_unique
     * — the UNIQUE(active_client_id) index from
     * 2026_08_25_000005_add_active_client_id_to_operational_sales_installment_plans_table.php.
     * Same shared primary check as Catalog's equivalents
     * (isPossibleUniqueConstraintViolation()), with errorInfo[2] checked
     * for either MySQL's named index or SQLite's table.column pair —
     * never $e->getMessage() string matching (catalog-domain-design.md
     * §7).
     */
    private function isActiveClientUniqueViolation(QueryException $e): bool
    {
        if (! $this->isPossibleUniqueConstraintViolation($e)) {
            return false;
        }

        $driverErrorMessage = (string) ($e->errorInfo[2] ?? '');

        return str_contains($driverErrorMessage, 'os_installment_plans_active_client_id_unique')
            || str_contains($driverErrorMessage, 'operational_sales_installment_plans.active_client_id');
    }

    /**
     * Shared primary check for "was this QueryException possibly a
     * UNIQUE constraint violation at all": SQLSTATE 23000 plus a
     * driver-specific constraint error code. MySQL reports 1062
     * (ER_DUP_ENTRY) specifically for duplicate-key violations; SQLite
     * reports 19 (SQLITE_CONSTRAINT) for ANY constraint violation
     * (UNIQUE, NOT NULL, CHECK, FK alike) — mirrors
     * EloquentProductRepository::isPossibleUniqueConstraintViolation()
     * exactly; every caller must still narrow further via the driver's
     * own error message (errorInfo[2]).
     */
    private function isPossibleUniqueConstraintViolation(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];
        $sqlState = $errorInfo[0] ?? null;
        $driverErrorCode = (int) ($errorInfo[1] ?? 0);

        return $sqlState === '23000' && in_array($driverErrorCode, [1062, 19], true);
    }

    /**
     * See this class's docblock for the ordering constraint this
     * enforces.
     */
    private function linkSaleLinesToPlan(InstallmentPlanModel $planModel, InstallmentPlan $plan): void
    {
        $allLines = [...$plan->reservedLines(), ...$plan->paymentLines()];

        if ($allLines === []) {
            return;
        }

        $lineIds = [];
        foreach ($allLines as $line) {
            if ($line->id() === null) {
                throw new RuntimeException(
                    "Cannot save InstallmentPlan {$planModel->id}: one of its reserved/payment SaleLines has no ".
                    'id yet, meaning it was never persisted. A SaleLine must already be saved via its own '.
                    'Transaction (EloquentTransactionRepository::save()) before the InstallmentPlan that '.
                    'references it is saved — this repository only links an already-persisted SaleLine to a '.
                    'plan via operational_sales_sale_lines.installment_plan_id; it does not, and structurally '.
                    'cannot, persist a SaleLine itself.'
                );
            }

            $lineIds[] = $line->id();
        }

        SaleLineModel::whereIn('id', $lineIds)->update(['installment_plan_id' => $planModel->id]);
    }

    public function findById(string $id): ?InstallmentPlan
    {
        $model = InstallmentPlanModel::find($id);

        return $model !== null ? $this->toDomainInstallmentPlan($model) : null;
    }

    /**
     * See Contracts\InstallmentPlanRepository::findActiveByClientId()'s
     * docblock: save() now enforces "at most one ACTIVE plan per client"
     * at the DB level, so this should never actually see more than one
     * ACTIVE row through normal usage. Still orders by id descending as
     * a defensive fallback, not a guarantee, in case that's ever
     * bypassed.
     */
    public function findActiveByClientId(string $clientId): ?InstallmentPlan
    {
        $model = InstallmentPlanModel::where('client_id', $clientId)
            ->where('status', InstallmentPlanStatus::ACTIVE->value)
            ->orderByDesc('id')
            ->first();

        return $model !== null ? $this->toDomainInstallmentPlan($model) : null;
    }

    private function toDomainInstallmentPlan(InstallmentPlanModel $model): InstallmentPlan
    {
        $lineModels = SaleLineModel::where('installment_plan_id', $model->id)->get();

        $reservedLines = [];
        $paymentLines = [];

        foreach ($lineModels as $lineModel) {
            $saleLine = $this->toDomainSaleLine($lineModel);

            if ($saleLine->type() === SaleLineType::RESERVATION) {
                $reservedLines[] = $saleLine;
            } elseif ($saleLine->type() === SaleLineType::INSTALLMENT_PAYMENT) {
                $paymentLines[] = $saleLine;
            }
            // Any other type linked to a plan would be a data-integrity
            // bug this repository has no way to have caused (save() only
            // ever links RESERVATION/INSTALLMENT_PAYMENT lines) — skipped
            // defensively on read rather than thrown, same posture as
            // EloquentProductRepository::loadVariationAxes()'s "should
            // never happen" skip.
        }

        return InstallmentPlan::reconstituteFromStorage(
            id: (string) $model->id,
            clientId: (string) $model->client_id,
            status: InstallmentPlanStatus::from($model->status),
            reservedLines: $reservedLines,
            paymentLines: $paymentLines,
        );
    }

    private function toDomainSaleLine(SaleLineModel $model): SaleLine
    {
        return SaleLine::reconstituteFromStorage(
            id: (string) $model->id,
            transactionId: (string) $model->transaction_id,
            clientId: (string) $model->client_id,
            priceableId: $model->priceable_id,
            type: SaleLineType::from($model->type),
            status: SaleLineStatus::from($model->status),
            quantity: $model->quantity,
            amount: Money::fromMinorUnits($model->amount_minor, $model->amount_currency),
            profit: Money::fromMinorUnits($model->profit_minor, $model->profit_currency),
            recordedAt: $model->recorded_at->toDateTimeImmutable(),
            effectiveAt: $model->effective_at->toDateTimeImmutable(),
            originatingSaleLineId: $model->originating_sale_line_id !== null ? (string) $model->originating_sale_line_id : null,
            originatingReservationLineId: $model->originating_reservation_line_id !== null ? (string) $model->originating_reservation_line_id : null,
        );
    }
}
