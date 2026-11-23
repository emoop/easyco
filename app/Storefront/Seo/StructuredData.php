<?php

namespace App\Storefront\Seo;

use App\Storefront\ReadModels\Breadcrumb;
use App\Storefront\ReadModels\ProductPage;
use App\Storefront\Search\SearchFeature;
use EasyCo\Pricing\Money;

/**
 * JSON-LD of a page (SEO-1): a PHP array, one graph (`@context` + `@graph`) or null. Built only from what the read models already hold (no query). `encode()` is the ONLY thing the layout prints raw:
 * `<`, `>`, `&`, `'` and `"` are \u-escaped by the flags, so no product name can close the script element.
 */
final class StructuredData
{
    public const AVAILABILITY = ['in' => 'https://schema.org/InStock', 'out' => 'https://schema.org/OutOfStock'];

    /**
     * @param  list<string>  $images  absolute urls, the first one is the main picture
     * @return array<string, mixed>
     */
    public static function product(ProductPage $product, string $canonical, string $description, array $images): array
    {
        $node = ['@type' => 'Product', 'name' => $product->name, 'url' => $canonical];

        $description !== '' && $node['description'] = $description;
        $images !== [] && $node['image'] = array_slice($images, 0, 5);

        $sku = $product->sku !== null && trim($product->sku) !== '' ? $product->sku : null;
        $sku !== null && $node['sku'] = $sku;

        if ($product->brand !== null && ($product->brand['name'] ?? '') !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $product->brand['name']];
        }

        $offers = self::offers($product, $canonical);
        $offers !== null && $node['offers'] = $offers;

        $graph = [$node];
        $crumbs = self::breadcrumbs($product->breadcrumbs);
        $crumbs !== null && $graph[] = $crumbs;

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    /**
     * @param  list<Breadcrumb>  $breadcrumbs  the page's own trail, ending with the page itself
     * @return array<string, mixed>|null
     */
    public static function category(array $breadcrumbs): ?array
    {
        $crumbs = self::breadcrumbs($breadcrumbs);

        return $crumbs === null ? null : ['@context' => 'https://schema.org', '@graph' => [$crumbs]];
    }

    /** @return array<string, mixed> */
    public static function home(string $homeUrl): array
    {
        $name = (string) \App\Settings\ShopName::current();
        $site = ['@type' => 'WebSite', 'name' => $name, 'url' => $homeUrl];

        if (SearchFeature::enabled()) {
            $site['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => rtrim((string) config('app.url'), '/').SearchFeature::path().'?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return ['@context' => 'https://schema.org', '@graph' => [$site, ['@type' => 'Organization', 'name' => $name, 'url' => $homeUrl]]];
    }

    /** The only raw output of the layout. @param  array<string, mixed>|null  $data */
    public static function encode(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed>|null */
    private static function offers(ProductPage $product, string $canonical): ?array
    {
        $price = $product->price;

        if ($price === null) {
            return null;
        }

        $decimal = static fn (int $minor): string => Money::fromMinorUnits($minor, $price->currency)->decimalValue();
        $common = [
            'priceCurrency' => $price->currency,
            'availability' => $product->inStock ? self::AVAILABILITY['in'] : self::AVAILABILITY['out'],
            'itemCondition' => 'https://schema.org/NewCondition',
            'url' => $canonical,
        ];

        if ($price->fromMinor === $price->toMinor) {
            return ['@type' => 'Offer', 'price' => $decimal($price->fromMinor)] + $common;
        }

        $purchasable = count(array_filter($product->variations, static fn ($variation): bool => $variation->purchasable));

        return ['@type' => 'AggregateOffer', 'lowPrice' => $decimal($price->fromMinor), 'highPrice' => $decimal($price->toMinor), 'offerCount' => max(1, $purchasable)] + $common;
    }

    /**
     * Home first, then the trail with absolute urls; the last item (the page itself) carries no `item` url.
     *
     * @param  list<Breadcrumb>  $trail
     * @return array<string, mixed>|null
     */
    private static function breadcrumbs(array $trail): ?array
    {
        if ($trail === []) {
            return null;
        }

        $base = rtrim((string) config('app.url'), '/');
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => (string) __('storefront.home'), 'item' => $base.'/']];

        foreach (array_values($trail) as $index => $crumb) {
            $item = ['@type' => 'ListItem', 'position' => $index + 2, 'name' => $crumb->label];
            $index < count($trail) - 1 && $crumb->url !== '' && $item['item'] = $base.$crumb->url;
            $items[] = $item;
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
}
