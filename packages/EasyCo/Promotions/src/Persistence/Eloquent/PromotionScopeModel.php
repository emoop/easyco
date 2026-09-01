<?php

namespace EasyCo\Promotions\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent read/write model for `promotion_scopes` — see
 * 2026_09_01_000002_create_promotion_scopes_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Promotions\PromotionScope.
 */
class PromotionScopeModel extends Model
{
    protected $table = 'promotion_scopes';

    protected $fillable = [
        'promotion_id',
        'scope_type',
        'scope_reference_id',
        'mode',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(PromotionModel::class, 'promotion_id');
    }
}
