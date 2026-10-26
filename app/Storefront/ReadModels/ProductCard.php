<?php

namespace App\Storefront\ReadModels;

/**
 * A product in a listing. Plain text fields are RAW (never pre-escaped). `price` is null when no shown variation
 * has a price. `in_stock` is a fact about the shown variations (quantity > 0 on at least one), never visibility.
 */
final readonly class ProductCard
{
    /**
     * @param array{name: string, slug: string}|null $brand
     * @param list<Badge>                            $badges always empty in S1
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $url,
        public string $name,
        public ?array $brand,
        public ?ImageSet $image,
        public ?PriceBlock $price,
        public array $badges,
        public bool $inStock,
        public ?ImageSet $secondImage = null,
    ) {
    }

    /**
     * The second photo (PC-1) is OMITTED when the product has none: a card with one photo keeps the same data as before.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'slug' => $this->slug,
            'url' => $this->url,
            'name' => $this->name,
            'brand' => $this->brand,
            'image' => $this->image?->toArray(),
            'price' => $this->price?->toArray(),
            'badges' => array_map(static fn (Badge $badge): array => $badge->toArray(), $this->badges),
            'in_stock' => $this->inStock,
        ];

        if ($this->secondImage !== null) {
            $data['second_image'] = $this->secondImage->toArray();
        }

        return $data;
    }
}
