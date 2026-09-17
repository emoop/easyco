<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for activity_log — see
 * 2026_09_17_000001_create_activity_log_table.php for the authoritative
 * column list. Plain infrastructure: no domain layer backs this table
 * (a factual record, not a protected business invariant — see
 * App\Services\ActivityLogger's own docblock).
 */
class ActivityLogModel extends Model
{
    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'action',
        'field',
        'old_value',
        'new_value',
        'staff_id',
        'staff_name',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
