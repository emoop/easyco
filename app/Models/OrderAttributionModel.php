<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent write model for `order_attributions` (order-context-design.md section 2.1; the migration
 * 2026_10_18_000001_create_order_attributions_table.php is the authoritative column list). One row per order, written by checkout inside
 * the placement transaction (App\Services\OrderContextWriter) and updated by exactly one other thing: the IP anonymisation command.
 *
 * `ip` IS PERSONAL DATA: recorded for fraud, security and dispute evidence ONLY, never for marketing or profiling, never joined to
 * derive behaviour (section 7.1). The full value is read only for ORDER_MANAGE, through OrderContextReader::fullClientAddress(); it
 * never appears in a log line, an activity_log row, an exception message or a response body. It is deliberately NOT part of any
 * `$hidden`-less serialization shortcut: nothing in this codebase serialises this model.
 */
class OrderAttributionModel extends Model
{
    protected $table = 'order_attributions';

    protected $guarded = [];

    protected $casts = [
        'captured_at' => 'datetime',
        'ip_anonymised_at' => 'datetime',
        'last_touch_at' => 'datetime',
        'first_touch_at' => 'datetime',
        'session_pages' => 'integer',
        'session_count' => 'integer',
    ];
}
