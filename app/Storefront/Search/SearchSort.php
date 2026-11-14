<?php

namespace App\Storefront\Search;

/** The sorts of the results page: «Най-подходящи» (the default, the engine's own rank) and the ones a category page offers. */
enum SearchSort: string
{
    case RELEVANCE = 'relevance';
    case NEWEST = 'newest';
    case PRICE_ASC = 'price_asc';
    case PRICE_DESC = 'price_desc';

    public static function fromRequest(mixed $value): ?self
    {
        return $value === null || $value === '' ? self::RELEVANCE : (is_string($value) ? self::tryFrom($value) : null);
    }
}
