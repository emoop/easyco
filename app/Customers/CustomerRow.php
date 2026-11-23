<?php

namespace App\Customers;

use Illuminate\Database\Eloquent\Model;

/**
 * ACC-4e-1: one row of the admin «Клиенти» list: ONE PERSON, a read model over a derived table (CustomerListQuery), never a stored table. The key is the URL key
 * (`o-{orderId}` or `a-{accountId}`), never the email. Read only: nothing here is ever saved.
 *
 * @property string $row_key
 * @property string $email_key
 * @property ?string $name
 * @property ?string $email
 * @property ?string $phone
 * @property int $orders_count
 * @property int $spent_minor
 * @property ?string $currency
 * @property ?\Illuminate\Support\Carbon $last_order_at
 * @property bool $has_account
 */
final class CustomerRow extends Model
{
    protected $table = 'customers';

    protected $primaryKey = 'row_key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'orders_count' => 'integer',
        'spent_minor' => 'integer',
        'has_account' => 'boolean',
        'last_order_at' => 'datetime',
    ];
}
