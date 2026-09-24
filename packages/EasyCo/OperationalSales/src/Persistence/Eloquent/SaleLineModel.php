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
        'product_name',
        'sku',
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
        // operational-sales-domain-design.md §3.13 — see that migration's
        // own docblock for why every Money field is a <name>_minor +
        // <name>_currency pair, and why sold_attributes is JSON.
        'regular_unit_price_minor',
        'regular_unit_price_currency',
        'final_unit_price_minor',
        'final_unit_price_currency',
        'promotion_discount_share_minor',
        'promotion_discount_share_currency',
        'discretionary_discount_minor',
        'discretionary_discount_currency',
        'net_paid_amount_minor',
        'net_paid_amount_currency',
        'unit_cost_minor',
        'unit_cost_currency',
        'sold_attributes',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'effective_at' => 'datetime',
        'sold_attributes' => 'array',
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
