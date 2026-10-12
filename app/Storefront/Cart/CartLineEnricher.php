<?php

namespace App\Storefront\Cart;

use App\Storefront\Reader\ImageSetBuilder;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\Enums\ProcessingStatus;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Collection;

/**
 * The storefront facts of a cart's lines that the cart API adds to each line (S3b): the product's storefront url and
 * one image. App-layer, built on the read layer's own pieces (StorefrontVisibility, StorefrontUrls, ImageSetBuilder);
 * CartController calls it ONCE per serialised cart and merges the result by variation id.
 *
 * `product_url`  the url the read layer builds (StorefrontUrls) for the product's CURRENT slug, or null when the product
 *                is not shown: hidden, draft, archived or soft-deleted, decided by StorefrontVisibility::isProductVisible()
 *                (the ONE rule; the cart never decides visibility itself).
 * `image`        null, or {src, srcset, sizes, width, height, alt}: the variation's own first image if it has one, else the
 *                product's first image. Only READY IMAGE assets and only the customer tiers (ImageSetBuilder's rules:
 *                `admin_grid` never); `src`/`width`/`height` are the `thumbnail` rendition (the largest ready one when a
 *                thumbnail is missing), `srcset` lists every ready customer width. `alt` is the asset's alt text, else the
 *                product name. A line whose product is not shown still gets its image: the customer put it in the cart.
 *
 * QUERY COST: FOUR reads for the whole cart, whatever the number of lines or images: the variations (-> product ids),
 * the products (slug, name and the visibility columns), the variations' own images, the products' images.
 */
final class CartLineEnricher
{
    /** The cart shows the image small; the browser picks from the srcset with this hint. */
    public const SIZES = '96px';

    private const FIRST_IMAGE_TIER = 'thumbnail';

    public function __construct(
        private readonly StorefrontVisibility $visibility,
        private readonly StorefrontUrls $urls,
        private readonly ImageSetBuilder $images,
    ) {
    }

    /**
     * @param list<string> $variationIds
     * @return array<string, array{product_url: ?string, image: ?array{src: string, srcset: string, sizes: string, width: int, height: int, alt: string}}>
     */
    public function enrich(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_map('strval', $variationIds)));

        if ($variationIds === []) {
            return [];
        }

        // 1. variation -> product
        $productIdOf = VariationModel::withTrashed()->whereIn('id', $variationIds)->pluck('product_id', 'id')->map(fn ($id): string => (string) $id)->all();
        $productIds = array_values(array_unique($productIdOf));

        // 2. the products, trashed ones included: the visibility predicate decides, not a hidden scope
        $products = ProductModel::withTrashed()->whereIn('id', $productIds)
            ->get(['id', 'slug', 'name', 'status', 'catalog_visibility', 'deleted_at'])
            ->keyBy(fn (ProductModel $p): string => (string) $p->id);

        // 3. + 4. first image of each variation, first image of each product
        $variationImages = $this->firstImages('catalog_variation_media', 'variation_id', $variationIds);
        $productImages = $this->firstImages('catalog_product_media', 'product_id', $productIds);

        $result = [];

        foreach ($variationIds as $variationId) {
            $productId = $productIdOf[$variationId] ?? null;
            $product = $productId === null ? null : $products->get($productId);
            $asset = $variationImages[$variationId] ?? ($productId === null ? null : ($productImages[$productId] ?? null));

            $result[$variationId] = [
                'product_url' => $product !== null && $this->visibility->isProductVisible($product) ? $this->urls->product((string) $product->slug) : null,
                'image' => $asset === null ? null : $this->imageOf($asset, $product?->name === null ? '' : (string) $product->name),
            ];
        }

        return $result;
    }

    /**
     * The first ready image asset of each owner, in ONE query: sort order, then asset id.
     *
     * @param list<string> $ownerIds
     * @return array<string, MediaAssetModel>
     */
    private function firstImages(string $pivot, string $ownerColumn, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        /** @var Collection<int, MediaAssetModel> $assets */
        $assets = MediaAssetModel::query()
            ->join($pivot, $pivot.'.media_id', '=', 'catalog_media.id')
            ->whereIn($pivot.'.'.$ownerColumn, $ownerIds)
            ->where('catalog_media.type', MediaType::IMAGE->value)
            ->where('catalog_media.processing_status', ProcessingStatus::READY->value)
            ->orderBy($pivot.'.sort_order')
            ->orderBy('catalog_media.id')
            ->get(['catalog_media.*', $pivot.'.'.$ownerColumn.' as owner_id']);

        $first = [];

        foreach ($assets as $asset) {
            $owner = (string) $asset->getAttribute('owner_id');

            // An asset with no usable variant is not an image: the next one may be.
            if (! isset($first[$owner]) && $this->images->build($asset, '', self::SIZES) !== null) {
                $first[$owner] = $asset;
            }
        }

        return $first;
    }

    /** @return array{src: string, srcset: string, sizes: string, width: int, height: int, alt: string}|null */
    private function imageOf(MediaAssetModel $asset, string $productName): ?array
    {
        $set = $this->images->build($asset, $productName, self::SIZES);

        if ($set === null) {
            return null;
        }

        $src = $set->src;
        $width = $set->width;
        $height = $set->height;

        foreach ((array) $asset->variants as $row) {
            if (is_array($row) && ($row['tier'] ?? null) === self::FIRST_IMAGE_TIER && is_int($row['width'] ?? null) && is_int($row['height'] ?? null)) {
                foreach ($set->srcset as $source) {
                    if ($source['width'] === $row['width']) {
                        [$src, $width, $height] = [$source['url'], $row['width'], $row['height']];
                    }
                }
            }
        }

        return [
            'src' => $src,
            'srcset' => implode(', ', array_map(fn (array $source): string => $source['url'].' '.$source['width'].'w', $set->srcset)),
            'sizes' => self::SIZES,
            'width' => $width,
            'height' => $height,
            'alt' => $set->alt,
        ];
    }
}
