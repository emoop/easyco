<?php

namespace Tests\Support;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;

/**
 * Test double for shipping stage 5e: the admin product forms now REQUIRE a shipping class, so a fixture product that
 * is built straight through the repository (no class — the domain stays permissive) would make every unrelated form
 * save in an older test fail on that one field. This decorator hands every variation SAVED WITHOUT a class the given
 * class code, so those tests keep testing what they were written for. It decorates the real repository; nothing else
 * changes.
 */
final class ClassGivingProductRepository implements ProductRepository
{
    public function __construct(private readonly ProductRepository $inner, private readonly string $classCode)
    {
    }

    public function save(Product $product): void
    {
        foreach ($product->variations() as $variation) {
            if ($variation->shippingClass() === null || trim($variation->shippingClass()) === '') {
                $variation->setShippingClass($this->classCode);
            }
        }

        $this->inner->save($product);
    }

    public function deleteVariation(Variation $variation): void
    {
        $this->inner->deleteVariation($variation);
    }

    public function delete(Product $product): void
    {
        $this->inner->delete($product);
    }

    public function findById(string $id): ?Product
    {
        return $this->inner->findById($id);
    }

    public function findByIdWithVariations(string $id): ?Product
    {
        return $this->inner->findByIdWithVariations($id);
    }

    public function findBySku(string $sku): ?Product
    {
        return $this->inner->findBySku($sku);
    }

    public function findByBarcode(string $barcode): ?Product
    {
        return $this->inner->findByBarcode($barcode);
    }

    public function findByBaseSku(string $baseSku): ?Product
    {
        return $this->inner->findByBaseSku($baseSku);
    }

    public function findBySlug(string $slug): ?Product
    {
        return $this->inner->findBySlug($slug);
    }

    public function findBrandIdsByProductIds(array $productIds): array
    {
        return $this->inner->findBrandIdsByProductIds($productIds);
    }
}
