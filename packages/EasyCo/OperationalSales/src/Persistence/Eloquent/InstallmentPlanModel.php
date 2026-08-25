<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent read/write model for operational_sales_installment_plans —
 * see 2026_08_25_000003_create_operational_sales_installment_plans_table.php
 * and 2026_08_25_000005_add_active_client_id_to_operational_sales_installment_plans_table.php
 * for the authoritative column list. This is an infrastructure-layer
 * mapping only; the domain invariants live in
 * EasyCo\OperationalSales\InstallmentPlan.
 *
 * active_client_id has NO corresponding field on the domain
 * InstallmentPlan class at all — it's a pure persistence-layer
 * projection of status (client_id when ACTIVE, null otherwise), the
 * same relationship attribute_signature has to attribute_assignments in
 * Catalog. Only EloquentInstallmentPlanRepository ever sets it.
 */
class InstallmentPlanModel extends Model
{
    protected $table = 'operational_sales_installment_plans';

    protected $fillable = [
        'client_id',
        'status',
        'active_client_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientModel::class, 'client_id');
    }
}
