<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent read/write model for catalog_products — see
 * 2026_08_23_000005_create_catalog_products_table.php for the authoritative
 * column list. This is an infrastructure-layer mapping only; the domain
 * invariants live in EasyCo\Catalog\Product.
 */
class ProductModel extends Model
{
    use SoftDeletes;

    protected $table = 'catalog_products';

    protected $fillable = [
        'type',
        'name',
        'slug',
        'base_sku',
        'short_description',
        'description',
        'brand_id',
        'season_id',
        'product_group_id',
        'size_guide_id',
        'status',
        'catalog_visibility',
        'is_featured',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'timeline_at' => 'datetime',
    ];

    public function variations(): HasMany
    {
        return $this->hasMany(VariationModel::class, 'product_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(BrandModel::class, 'brand_id');
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(SeasonModel::class, 'season_id');
    }

    public function productGroup(): BelongsTo
    {
        return $this->belongsTo(ProductGroupModel::class, 'product_group_id');
    }

    /**
     * Read-only — admin-panel-design.md §7's own explicit instruction:
     * added purely so Filament's table filters/SelectFilters have
     * something to query against. The domain layer's own category
     * writes still go exclusively through ProductCategoryRepository
     * (admin-panel-design.md §5) — this relationship is never used for
     * writing.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(CategoryModel::class, 'catalog_product_categories', 'product_id', 'category_id');
    }

    /** See categories()'s own docblock — identical reasoning, for tags. */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TagModel::class, 'catalog_product_tags', 'product_id', 'tag_id');
    }
}
