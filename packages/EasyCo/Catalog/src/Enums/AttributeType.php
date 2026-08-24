<?php

namespace EasyCo\Catalog\Enums;

/**
 * Value shape of an AttributeDefinition. SELECT is the only type usable as
 * a variation axis in v1 (an axis needs a closed, enumerable set of values
 * to generate combinations from — a free-text or numeric attribute has no
 * such set). TEXT/NUMBER/BOOLEAN are descriptive-only.
 *
 * MULTISELECT is deliberately descriptive-only in v1 (e.g. "Season:
 * Spring/Summer" on one product) — see the domain design doc for what is
 * explicitly deferred.
 */
enum AttributeType: string
{
    case TEXT = 'text';
    case NUMBER = 'number';
    case BOOLEAN = 'boolean';
    case SELECT = 'select';
    case MULTISELECT = 'multiselect';

    public function isUsableAsVariationAxis(): bool
    {
        return $this === self::SELECT;
    }
}
