<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent read/write model for operational_sales_sale_lines — see
 * 2026_08_25_000004_create_operational_sales_sale_lines_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants (immutability, type/nullable-field
 * cross-validation) live in EasyCo\OperationalSales\SaleLine.
 *
 * installment_plan_id has NO corresponding field on the domain SaleLine
 * class at all — SaleLine doesn't know which InstallmentPlan (if any) it
 * belongs to; that association is owned the other way around, by
 * InstallmentPlan::reservedLines()/paymentLines(). This column exists
 * purely so EloquentInstallmentPlanRepository can link an
 * already-persisted SaleLine row to its plan — see that repository's
 * save() docblock.
 */
class SaleLineModel extends Model
{
    use SoftDeletes;

    protected $table = 'operational_sales_sale_lines';

    protected $fillable = [
        'transaction_id',
        'client_id',
        'priceable_id',
        'type',
        'status',
        'quantity',
        'amount_minor',
        'amount_currency',
        'profit_minor',
        'profit_currency',
        'recorded_at',
        'effective_at',
        'originating_sale_line_id',
        'originating_reservation_line_id',
        'installment_plan_id',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'effective_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(TransactionModel::class, 'transaction_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientModel::class, 'client_id');
    }

    public function installmentPlan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlanModel::class, 'installment_plan_id');
    }
}
