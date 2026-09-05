<?php

namespace EasyCo\OperationalSales\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for operational_sales_clients — see
 * 2026_08_25_000001_create_operational_sales_clients_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\OperationalSales\Client.
 */
class ClientModel extends Model
{
    protected $table = 'operational_sales_clients';

    protected $fillable = [
        'name',
        'account_id',
    ];
}
