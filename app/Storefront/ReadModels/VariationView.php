<?php

namespace App\Storefront\ReadModels;

/**
 * A SHOWN variation on a product page. A SIMPLE product has exactly one (its universal variation) with no
 * attributes; its id is what add-to-cart needs. `purchasable` mirrors Variation::isEffectivelyPurchasable()
 * (status active AND is_purchasable) and is pinned against the domain method by a test. The stock QUANTITY is never
 * exposed, only `in_stock`.
 */
final readonly class VariationView
{
    /**
     * @param list<array{name: string, value: string}> $attributes
     */
    public function __construct(
        public string $id,
        public ?string $sku,
        public string $label,
        public array $attributes,
        public ?PriceBlock $price,
        public bool $inStock,
        public bool $purchasable,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'label' => $this->label,
            'attributes' => $this->attributes,
            'price' => $this->price?->toArray(),
            'in_stock' => $this->inStock,
            'purchasable' => $this->purchasable,
        ];
    }
}
