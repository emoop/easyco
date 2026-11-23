<?php

namespace App\Storefront\Support;

use App\Storefront\ReadModels\CategoryPage;
use App\Storefront\ReadModels\ListingPage;
use App\Storefront\ReadModels\ProductPage;
use App\Storefront\Seo\SeoImage;
use App\Storefront\Seo\StructuredData;

/**
 * The `seo` variable of every storefront view: ['title', 'description', 'canonical'] (plain text, ESCAPED by the view).
 *
 *  - title        "{name} — {shop name}"; the shop name is ShopName::current() (the «Лого» tab's name, else APP_NAME)
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

    /** @return array{title: string, description: string, canonical: ?string, og: array, ld: ?string} */
    public function forProduct(ProductPage $product): array
    {
        $description = $this->plain($product->shortDescription ?? $product->description ?? '');
        $canonical = $this->absolute($product->url);
        $pictures = $this->pictures($product);
        $main = $pictures === [] ? null : SeoImage::closest($pictures[0]);
        $price = $product->price === null ? null : [
            'amount' => \EasyCo\Pricing\Money::fromMinorUnits($product->price->fromMinor, $product->price->currency)->decimalValue(),
            'currency' => $product->price->currency,
        ];

        return [
            'title' => $this->title($product->name),
            'description' => $description,
            'canonical' => $canonical,
            'og' => $this->og('product', $product->name, $description, $canonical, $main, $main === null ? null : $pictures[0]->alt, $price),
            'ld' => StructuredData::encode(StructuredData::product($product, $canonical, $description, array_map(SeoImage::largest(...), $pictures))),
        ];
    }

    /** @return array{title: string, description: string, canonical: ?string, noindex?: bool} */
    public function forCategory(CategoryPage $category, ListingPage $listing, bool $filtered = false): array
    {
        // CT-3: a filtered page is never indexed and points at the unfiltered category (one URL per selection is for visitors, not for search engines)
        if ($filtered) {
            return ['title' => $this->title($category->name), 'description' => '', 'canonical' => $this->absolute($category->url), 'noindex' => true];
        }

        $canonical = $this->absolute($category->url.($listing->page > 1 ? '?page='.$listing->page : ''));

        return [
            'title' => $this->title($category->name),
            'description' => '',
            'canonical' => $canonical,
            'og' => $this->og('website', $category->name, '', $canonical),
            'ld' => StructuredData::encode(StructuredData::category($category->breadcrumbs)),
        ];
    }

    /**
     * SEO-1.1: an index page (`/brand`, `/collection`). The canonical is the bare path on the shop's address (never a query string). Under `easyco.noindex` the page
     * has no canonical and no share or structured data: only the noindex robots tag, as the test server asks.
     *
     * @param  list<\App\Storefront\ReadModels\Breadcrumb>  $crumbs  the trail; its last step is the page itself (no link)
     * @return array{title: string, description: string, canonical: ?string, noindex?: bool, og?: array, ld?: ?string}
     */
    public function forSubjectIndex(string $name, string $description, string $path, array $crumbs): array
    {
        if (config('easyco.noindex')) {
            return ['title' => $this->title($name), 'description' => '', 'canonical' => null, 'noindex' => true];
        }

        $canonical = $this->absolute($path);
        $plain = $this->plain($description);

        return [
            'title' => $this->title($name),
            'description' => $plain,
            'canonical' => $canonical,
            'og' => $this->og('website', $name, $plain, $canonical),
            'ld' => StructuredData::encode(StructuredData::category($crumbs)),
        ];
    }

    /** @return array{title: string, description: string, canonical: ?string} */
    public function forHome(string $homeUrl): array
    {
        $canonical = $this->absolute($homeUrl);

        return [
            'title' => $this->shopName(),
            'description' => '',
            'canonical' => $canonical,
            'og' => $this->og('website', $this->shopName(), '', $canonical),
            'ld' => StructuredData::encode(StructuredData::home($canonical)),
        ];
    }

    /** @return array{title: string, description: string, canonical: ?string} */
    public function forNotFound(string $title): array
    {
        return ['title' => $title.' — '.$this->shopName(), 'description' => '', 'canonical' => null];
    }

    /**
     * A content page (pages-design.md section 4): title = the meta title or the title; description = the meta description or the first ~160
     * characters of the text; canonical = the page url (null for a PREVIEW: it is never indexed); `noindex` asks the layout for the robots
     * meta even where there is a canonical.
     *
     * @return array{title: string, description: string, canonical: ?string, noindex: bool}
     */
    public function forPage(\App\Content\Models\PageModel $page, bool $preview = false): array
    {
        $description = $page->meta_description !== null && trim($page->meta_description) !== '' ? $page->meta_description : $this->plain($page->body);
        $canonical = $preview ? null : $this->absolute(\App\Storefront\Pages\PageReader::urlFor($page->slug));
        $indexable = $canonical !== null && ! $page->noindex;

        return [
            'title' => $this->title($page->meta_title !== null && trim($page->meta_title) !== '' ? $page->meta_title : $page->title),
            'description' => $description,
            'canonical' => $canonical,
            'noindex' => $page->noindex || $preview,
        ] + ($indexable ? ['og' => $this->og('website', $page->title, $description, $canonical)] : []);
    }

    /**
     * SEO-1: the Open Graph and Twitter tags of an indexable page, as `property` (og:*, product:*) and `name` (twitter:*) maps; the layout prints them only when the page has a canonical and is not noindex.
     * The description is left out when empty; the image block only with an image; the twitter card is the large one only with an image.
     *
     * @param  array{url: string, width: int, height: int}|null  $image
     * @param  array{amount: string, currency: string}|null  $price
     * @return array{property: array<string, string>, name: array<string, string>}
     */
    private function og(string $type, string $title, string $description, string $canonical, ?array $image = null, ?string $alt = null, ?array $price = null): array
    {
        $property = ['og:type' => $type, 'og:title' => $title];
        $description !== '' && $property['og:description'] = $description;
        $property += ['og:url' => $canonical, 'og:site_name' => $this->shopName()];

        $locale = ['bg' => 'bg_BG', 'en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR', 'es' => 'es_ES', 'it' => 'it_IT', 'ro' => 'ro_RO', 'el' => 'el_GR', 'tr' => 'tr_TR', 'pl' => 'pl_PL'][app()->getLocale()] ?? null;
        $locale !== null && $property['og:locale'] = $locale;

        if ($image !== null) {
            $property += ['og:image' => $image['url'], 'og:image:width' => (string) $image['width'], 'og:image:height' => (string) $image['height']];
            $alt !== null && $alt !== '' && $property['og:image:alt'] = $alt;
        }

        if ($price !== null) {
            $property += ['product:price:amount' => $price['amount'], 'product:price:currency' => $price['currency']];
        }

        $name = ['twitter:card' => $image === null ? 'summary' : 'summary_large_image', 'twitter:title' => $title];
        $description !== '' && $name['twitter:description'] = $description;
        $image !== null && $name['twitter:image'] = $image['url'];

        return ['property' => $property, 'name' => $name];
    }

    /**
     * The product's pictures for the cards and the JSON-LD: the gallery's images in order (a video is skipped), else the page's own image list.
     *
     * @return list<\App\Storefront\ReadModels\ImageSet>
     */
    private function pictures(ProductPage $product): array
    {
        $sets = [];

        foreach ($product->gallery as $item) {
            $item->isImage() && $item->image !== null && $sets[] = $item->image;
        }

        return $sets !== [] ? $sets : array_values($product->images);
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
        return (string) \App\Settings\ShopName::current();
    }

    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
