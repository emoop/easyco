<?php

namespace App\Storefront\ReadModels;

/**
 * A visible product, complete. Text fields are RAW.
 *
 * `short_description` and `description` are stored exactly as the admin saved them. The admin edits `description` in a
 * RICH-TEXT editor, so it may contain HTML: it is NOT plain text and must be sanitised by whoever renders it as markup
 * (S2); it must never be printed unescaped as it is.
 *
 * `options` lists, per attribute (in definition order), its values (in the attribute's own value order) that occur on
 * the shown variations; empty for a SIMPLE product. `images` are the READY gallery images, first = main.
 *
 * AT-1: `optionDisplay` runs parallel to `options` (same order): per attribute its `displayType` (dropdown | buttons | swatch | specs) and `colours` (value => `#rrggbb` or null) which is filled ONLY for a `swatch` attribute (empty for every other type, the stored colour stays hidden). Nothing prints them yet; like `gallery` it is NOT in toArray() (the JSON contract is unchanged).
 *
 * SC-1: `sku` is the product-level SKU (`base_sku`), or null when it has none; like `gallery` it is for the markup only and is NOT in toArray() (the JSON contract is unchanged).
 */
final readonly class ProductPage
{
    /**
     * @param array{name: string, slug: string}|null              $brand
     * @param list<Breadcrumb>                                    $breadcrumbs
     * @param list<ImageSet>                                      $images
     * @param list<array{name: string, values: list<string>}>     $options
     * @param list<VariationView>                                 $variations
     * @param list<Badge>                                         $badges always empty in S1
     * @param list<array{name: string, displayType: string, colours: array<string, string|null>}> $optionDisplay
     * @param list<GalleryItem>                                   $gallery GL-1: the usable images AND videos in media order, for the gallery markup only (NOT in toArray(): the JSON contract is unchanged)
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $url,
        public string $name,
        public string $type,
        public ?string $shortDescription,
        public ?string $description,
        public ?array $brand,
        public array $breadcrumbs,
        public array $images,
        public ?PriceBlock $price,
        public bool $inStock,
        public array $options,
        public array $variations,
        public array $badges,
        public array $gallery = [],
        public ?string $sku = null,
        public array $optionDisplay = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'url' => $this->url,
            'name' => $this->name,
            'type' => $this->type,
            'short_description' => $this->shortDescription,
            'description' => $this->description,
            'brand' => $this->brand,
            'breadcrumbs' => array_map(static fn (Breadcrumb $b): array => $b->toArray(), $this->breadcrumbs),
            'images' => array_map(static fn (ImageSet $i): array => $i->toArray(), $this->images),
            'price' => $this->price?->toArray(),
            'in_stock' => $this->inStock,
            'options' => $this->options,
            'variations' => array_map(static fn (VariationView $v): array => $v->toArray(), $this->variations),
            'badges' => array_map(static fn (Badge $b): array => $b->toArray(), $this->badges),
        ];
    }
}
