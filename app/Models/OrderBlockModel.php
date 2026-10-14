<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `order_blocks` (cod-protection-design.md section 3.1; the migration 2026_10_19_000002_create_order_blocks_table.php is the
 * authoritative column list). Written only by App\Services\OrderBlockService.
 *
 * PERSONAL DATA processed to PREVENT FRAUD, never used for marketing: `value_display` can hold a full IP address (kept for the block's duration
 * by the owner's decision) and is shown in full only to ORDER_MANAGE; it never appears in a log line, an activity_log row or a response body.
 */
class OrderBlockModel extends Model
{
    protected $table = 'order_blocks';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** ACTIVE = not revoked and (permanent or not yet expired). */
    public function scopeActive(Builder $query, CarbonInterface $now): Builder
    {
        return $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    public function isActive(CarbonInterface $now): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->greaterThan($now));
    }

    /** active | expired | revoked */
    public function state(CarbonInterface $now): string
    {
        return $this->revoked_at !== null ? 'revoked' : ($this->isActive($now) ? 'active' : 'expired');
    }
}
