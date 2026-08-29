<?php

namespace EasyCo\Media\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_variation_media. Infrastructure-
 * layer mapping only; the domain invariants live in
 * EasyCo\Media\VariationMedia.
 */
class VariationMediaModel extends Model
{
    protected $table = 'catalog_variation_media';

    // The migration never added timestamps() to this table.
    public $timestamps = false;

    protected $fillable = [
        'variation_id',
        'media_id',
        'sort_order',
    ];
}
