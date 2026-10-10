<?php

namespace App\Storefront\Support;

use Illuminate\Http\Response;

/**
 * THE 404 of the storefront. A missing, hidden, draft, archived or soft-deleted product or category, an invalid slug or
 * path, a refused query and a page past the last one all answer with this one response: the same status, the same
 * body, byte for byte (nothing request-dependent is in it, not even the menu), so nothing tells a hidden product from
 * one that never existed.
 */
final class NotFoundResponse
{
    public static function make(): Response
    {
        return response()->view('storefront.404', [
            'seo' => app(SeoMeta::class)->forNotFound(__('storefront.not_found_title')),
            'categories' => [],
        ], 404);
    }
}
