<?php

namespace App\Storefront\Support;

use App\Storefront\ReadModels\CategoryPage;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ProductPage;

/**
 * The `seo` variable of every storefront view: ['title', 'description', 'canonical'] (plain text, ESCAPED by the view).
 *
 *  - title        "{name} — {shop name}"; the shop name is config('app.name') (no site setting holds one yet)
 *  - description  the short description (falling back to the description) with tags removed, entities decoded,
 *                 whitespace collapsed, at most 160 characters; "" when there is none (the view omits the tag)
 *  - canonical    ABSOLUTE url: config('app.url') + the url carried by the read model (never built here); a listing
 *                 page canonicalises to its own page: `?page=n` for n > 1, nothing for page 1
 *
 * canonical is null for a page that must not have one (the 404).
 */
final class SeoMeta
{
    public const DESCRIPTION_LENGTH = 160;

    /** @return array{title: string, description: string, canonical: ?string} */
    public function forProduct(ProductPage $product): array
    {
        return [
            'title' => $this->title($product->name),
            'description' => $this->plain($product->shortDescription ?? $product->description ?? ''),
            'canonical' => $this->absolute($product->url),
        ];
    }

    /** @return array{title: string, description: string, canonical: ?string} */
    public function forCategory(CategoryPage $category, ListingPage $listing): array
    {
        return [
            'title' => $this->title($category->name),
            'description' => '',
            'canonical' => $this->absolute($category->url.($listing->page > 1 ? '?page='.$listing->page : '')),
        ];
    }

    /** @return array{title: string, description: string, canonical: ?string} */
    public function forHome(string $homeUrl): array
    {
        return ['title' => $this->shopName(), 'description' => '', 'canonical' => $this->absolute($homeUrl)];
    }

    /** @return array{title: string, description: string, canonical: ?string} */
    public function forNotFound(string $title): array
    {
        return ['title' => $title.' — '.$this->shopName(), 'description' => '', 'canonical' => null];
    }

    public function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/[\s\x{00A0}\x{200B}-\x{200F}\x{2028}\x{2029}]+/u', ' ', $text));
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $text);

        if (mb_strlen($text) <= self::DESCRIPTION_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::DESCRIPTION_LENGTH - 1)).'…';
    }

    private function title(string $name): string
    {
        return $name.' — '.$this->shopName();
    }

    private function shopName(): string
    {
        return (string) config('app.name');
    }

    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
