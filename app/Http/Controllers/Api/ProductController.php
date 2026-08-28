<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\Hook;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Deliberately minimal — exists only to prove the Catalog -> Pricing
 * vertical slice works end-to-end (create a Product, persist it, resolve a
 * price for its Universal variation's priceableId). Not production request
 * handling: no auth, no form request class, no resource transformer.
 */
class ProductController extends Controller
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PriceResolver $priceResolver,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'base_sku' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'barcode' => 'nullable|string|max:255',
        ]);

        // Lets anything listening on this hook adjust the merchant-supplied
        // base_sku before it becomes the Product's identity — see
        // App\Providers\CatalogSkuGeneratorServiceProvider, the real
        // generator. An empty string tells it to auto-generate the next
        // value from the persistent sequence; a merchant-supplied value
        // passes through completely unchanged (a SKU is an opaque
        // identifier, not a URL-safe token, unlike slug).
        $baseSku = Hook::apply('catalog.product.base_sku', $validated['base_sku'] ?? '');

        // Same idea for slug, but with a real, production-intended
        // listener — see App\Providers\CatalogSlugGeneratorServiceProvider.
        // An empty string tells it to auto-generate from the name; a
        // merchant-supplied slug still gets cleaned up and deduped.
        $slug = Hook::apply('catalog.product.slug', $validated['slug'] ?? '', $validated['name']);

        $product = Product::createSimple($validated['name'], $baseSku, $slug);

        // No default listener exists (or ever will, by design) for this
        // hook — barcode format/length/checksum requirements vary too
        // much per merchant for EasyCo to impose a default, unlike
        // base_sku/slug above. With zero listeners registered this is a
        // pure no-op: whatever was supplied (or "" if nothing was) comes
        // back unchanged. See extensibility-design-and-hooks.md's Hook
        // Reference entry for 'catalog.variation.barcode'.
        $variation = $product->universalVariation();
        $barcode = Hook::apply('catalog.variation.barcode', $validated['barcode'] ?? '', $variation);
        if ($barcode !== '') {
            $variation->setBarcode($barcode);
        }

        $this->products->save($product);

        $priceableId = (string) $product->universalVariation()->priceableId();

        try {
            $quote = $this->priceResolver->resolve(new PriceContext(
                priceableId: $priceableId,
                quantity: 1,
                currency: 'EUR',
            ));

            return response()->json([
                'product_id' => $product->id(),
                'name' => $product->name(),
                'slug' => $product->slug(),
                'price' => $quote->final->gross()->decimalValue(),
            ]);
        } catch (RuntimeException) {
            // The current PriceResolver binding (EloquentPriceResolver)
            // throws RuntimeException in two cases: the reserved
            // "Regular Prices" system PriceList has not been seeded yet
            // (pricing-persistence-domain-design.md §8 item 3, not yet
            // implemented), or it has been seeded but has no
            // PriceListItem for this exact priceableId. Both are the same
            // "no price configured for this product yet" situation from
            // this controller's point of view — returning a null price
            // with a flag is the correct graceful response either way,
            // not a reason to fail the whole product-creation request.
            return response()->json([
                'product_id' => $product->id(),
                'name' => $product->name(),
                'slug' => $product->slug(),
                'price' => null,
                'price_unavailable' => true,
            ]);
        }
    }
}
