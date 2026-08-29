<?php

namespace EasyCo\Media\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent read/write model for catalog_media — see
 * 2026_08_23_000012_create_catalog_media_tables.php and
 * 2026_08_29_000001_add_processing_columns_to_catalog_media_table.php
 * for the authoritative column list. Infrastructure-layer mapping
 * only; the domain invariants live in EasyCo\Media\MediaAsset.
 */
class MediaAssetModel extends Model
{
    protected $table = 'catalog_media';

    protected $fillable = [
        'type',
        'disk',
        'path',
        'alt_text',
        'processing_status',
        'processing_failure_reason',
        'variants',
    ];

    protected $casts = [
        'variants' => 'array',
    ];
}
