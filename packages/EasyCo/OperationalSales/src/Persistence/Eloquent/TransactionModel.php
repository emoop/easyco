<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent read/write model for operational_sales_transactions — see
 * 2026_08_25_000002_create_operational_sales_transactions_table.php for
 * the authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\OperationalSales\Transaction.
 */
class TransactionModel extends Model
{
    protected $table = 'operational_sales_transactions';

    protected $fillable = [
        'channel',
    ];

    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleLineModel::class, 'transaction_id');
    }
}
