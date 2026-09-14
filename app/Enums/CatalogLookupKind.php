<?php

namespace App\Enums;

/**
 * Which of the six catalog lookup-entity types a product is being
 * detached from — the single vocabulary App\Services\
 * DetachProductFromCatalogLookup and App\Jobs\
 * DetachProductFromCatalogLookupJob share, so "which kind of unlink"
 * is never a stringly-typed guess at either call site.
 */
enum CatalogLookupKind: string
{
    case BRAND = 'brand';
    case SEASON = 'season';
    case CATEGORY = 'category';
    case TAG = 'tag';
    case ATTRIBUTE_DEFINITION = 'attribute_definition';
    case ATTRIBUTE_VALUE = 'attribute_value';
}
