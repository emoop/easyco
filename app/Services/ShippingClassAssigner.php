<?php

namespace App\Services;

use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use Illuminate\Support\Facades\DB;

/**
 * Assigns or clears the shipping class of a product or a variation (shipping-domain-design.md §3.2, §12.3.4) — the
 * only way the admin writes it.
 *
 * WHERE THE CLASS LIVES: on the VARIATION, as its class CODE in `Variation::shippingClass()` — nowhere else. There is
 * no product-level class and so no precedence between a product's and a variation's (the quote reads the variation's
 * alone). A SIMPLE product carries its class on its single universal variation, which is what setForProduct() writes;
 * a VARIABLE product has no universal variation, so it is refused with a translated message that points at the
 * variations. The write uses the existing Variation::setShippingClass() and the product repository's save() — no
 * frozen Catalog class is changed, and Catalog never learns that Shipping exists (the code is a plain value).
 *
 * Each call that changes something writes EXACTLY ONE ActivityLogger entry in the same transaction — entity
 * `product` (the entity every variation change is logged under on the product's activity page), field
 * `shipping_class` for the product and `variation[<id>].shipping_class` for a variation, old and new CLASS CODE —
 * and fires `shipping.class.assigned (string $target, string $targetId, ?string $oldCode, ?string $newCode)` after
 * the commit, with $target `product` or `variation`. Setting what is already set (or clearing what is already empty)
 * writes nothing and fires nothing. An unknown class is a ShippingClassInvalidException (field `shipping_class`).
 */
final class ShippingClassAssigner
{
    public function __construct(
        private readonly ShippingClassRepository $classes,
        private readonly ProductRepository $products,
        private readonly VariationRepository $variations,
        private readonly ActivityLogger $audit,
        private readonly ShippingClassMissingReader $missing,
    ) {
    }

    /**
     * @param  string|null  $classId  null clears the class
     * @return bool whether anything changed
     *
     * @throws ShippingClassInvalidException an unknown class, or a product without a single variation of its own
     * @throws ShippingClassNotFoundException the product is gone
     */
    public function setForProduct(string $productId, ?string $classId): bool
    {
        return $this->assign('product', $productId, $classId, function () use ($productId): array {
            $product = $this->products->findByIdWithVariations($productId) ?? throw new ShippingClassNotFoundException(target: true);
            $variation = $product->universalVariation();

            if ($variation === null) {
                throw new ShippingClassInvalidException(['shipping_class' => [__('shipping.classes.errors.product_has_variations')]]);
            }

            return [$product, $variation, 'shipping_class'];
        });
    }

    /**
     * @param  string|null  $classId  null clears the class
     * @return bool whether anything changed
     *
     * @throws ShippingClassInvalidException an unknown class
     * @throws ShippingClassNotFoundException the variation is gone
     */
    public function setForVariation(string $variationId, ?string $classId): bool
    {
        return $this->assign('variation', $variationId, $classId, function () use ($variationId): array {
            $found = $this->variations->findById($variationId) ?? throw new ShippingClassNotFoundException(target: true);
            $product = $this->products->findByIdWithVariations($found->productId()) ?? throw new ShippingClassNotFoundException(target: true);

            foreach ($product->variations() as $variation) {
                if ((string) $variation->id() === $variationId) {
                    return [$product, $variation, 'variation['.$variationId.'].shipping_class'];
                }
            }

            throw new ShippingClassNotFoundException(target: true);
        });
    }

    /**
     * Gives the class to variations that still have none (shipping stage 5e, the assign-missing command): one
     * TRANSACTION for the whole batch. Each variation row is locked and re-read first, and one that is no longer
     * missing — another process (or an admin) gave it a class meanwhile — is SKIPPED, never overwritten; a valid
     * class is never replaced. The class goes through the domain (Variation::setShippingClass and the product
     * repository, once per product), not raw SQL. No `shipping.class.assigned` hook per variation: the caller fires
     * ONE `shipping.class.assigned_bulk` after the run. With $auditEach every changed variation gets the usual audit
     * entry (entity `product`, field `variation[<id>].shipping_class`); without it none does (the caller writes the
     * run's summary entry).
     *
     * @param  list<string>  $variationIds
     * @return array{assigned: int, skipped: int, ids: list<string>} the variations changed
     *
     * @throws ShippingClassInvalidException an unknown class
     */
    public function assignMissing(array $variationIds, string $classId, bool $auditEach): array
    {
        $class = $this->classes->findById($classId);

        if ($class === null) {
            throw new ShippingClassInvalidException(['shipping_class' => [__('shipping.classes.errors.unknown_class')]]);
        }

        $codes = array_map(static fn ($existing): string => $existing->code(), $this->classes->all());

        return DB::transaction(function () use ($variationIds, $class, $codes, $auditEach): array {
            $rows = DB::table('catalog_variations')->whereIn('id', $variationIds)->orderBy('id')->lockForUpdate()->get(['id', 'product_id', 'shipping_class']);
            $byProduct = [];
            $skipped = count($variationIds) - $rows->count();

            foreach ($rows as $row) {
                if ($this->missing->isMissing($row->shipping_class === null ? null : (string) $row->shipping_class, $codes)) {
                    $byProduct[(string) $row->product_id][(string) $row->id] = $row->shipping_class === null ? null : (string) $row->shipping_class;
                } else {
                    $skipped++;
                }
            }

            $changed = [];

            foreach ($byProduct as $productId => $olds) {
                $product = $this->products->findByIdWithVariations((string) $productId);

                if ($product === null) {
                    $skipped += count($olds);

                    continue;
                }

                $done = false;

                foreach ($product->variations() as $variation) {
                    $id = (string) $variation->id();

                    if (! array_key_exists($id, $olds)) {
                        continue;
                    }

                    $variation->setShippingClass($class->code());
                    $done = true;
                    $changed[] = $id;

                    if ($auditEach) {
                        $this->audit->logFieldChanged('product', (string) $productId, 'variation['.$id.'].shipping_class', self::normalised($olds[$id]), $class->code());
                    }
                }

                if ($done) {
                    $this->products->save($product);
                }
            }

            return ['assigned' => count($changed), 'skipped' => $skipped, 'ids' => $changed];
        });
    }

    /**
     * @param  \Closure(): array{0: Product, 1: Variation, 2: string}  $locate  the product, the variation that carries the class, the audit field
     */
    private function assign(string $target, string $targetId, ?string $classId, \Closure $locate): bool
    {
        $newCode = null;

        if ($classId !== null && $classId !== '') {
            $class = $this->classes->findById($classId);

            if ($class === null) {
                throw new ShippingClassInvalidException(['shipping_class' => [__('shipping.classes.errors.unknown_class')]]);
            }

            $newCode = $class->code();
        }

        $change = DB::transaction(function () use ($locate, $newCode): ?array {
            [$product, $variation, $field] = $locate();

            $oldCode = self::normalised($variation->shippingClass());

            if ($oldCode === $newCode) {
                return null;
            }

            $variation->setShippingClass($newCode);
            $this->products->save($product);
            $this->audit->logFieldChanged('product', (string) $product->id(), $field, $oldCode, $newCode);

            return ['old' => $oldCode, 'new' => $newCode];
        });

        if ($change === null) {
            return false;
        }

        Hook::fire('shipping.class.assigned', $target, $targetId, $change['old'], $change['new']);

        return true;
    }

    /** The stored text as the rate calculator reads it: blank is no class. */
    private static function normalised(?string $code): ?string
    {
        return $code === null || trim($code) === '' ? null : $code;
    }
}
