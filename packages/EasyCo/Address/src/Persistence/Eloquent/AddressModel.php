<?php

namespace EasyCo\Address\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for `addresses` — see
 * 2026_09_03_000001_create_addresses_table.php for the authoritative
 * column list. This is an infrastructure-layer mapping only; the domain
 * invariants live in EasyCo\Address\Address.
 */
class AddressModel extends Model
{
    protected $table = 'addresses';

    protected $fillable = [
        'account_id',
        'delivery_type',
        'recipient_name',
        'phone',
        'country',
        'city',
        'postal_code',
        'address_line_1',
        'address_line_2',
        'carrier_code',
        'pickup_point_reference',
        'settlement',
    ];
}
