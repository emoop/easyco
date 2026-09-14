<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent read/write model for catalog_categories — see
 * 2026_08_23_000003_create_catalog_categories_table.php for the
 * authoritative column list. This is an infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Catalog\Category.
 */
class CategoryModel extends Model
{
    protected $table = 'catalog_categories';

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
    ];

    /**
     * Read-only convenience for the admin panel's table/infolist
     * columns (CategoryResource's `parent.name` dot-notation) — an
     * infrastructure-layer Eloquent relationship, not a domain concept;
     * EasyCo\Catalog\Category itself only ever exposes parentId() as a
     * plain string.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
