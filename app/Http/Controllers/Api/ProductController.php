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
use OutOfBoundsException;

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
            'base_sku' => 'required|string|max:255',
        ]);

        // Lets anything listening on this hook adjust the merchant-supplied
        // base_sku before it becomes the Product's identity — see
        // App\Providers\DemoHooksServiceProvider for the one demo listener
        // currently registered. Not the real SKU-generator feature (see
        // that provider's docblock).
        $baseSku = Hook::apply('catalog.product.base_sku', $validated['base_sku']);

        $product = Product::createSimple($validated['name'], $baseSku);
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
                'price' => $quote->final->gross()->decimalValue(),
            ]);
        } catch (OutOfBoundsException) {
            // The current PriceResolver binding (InMemoryPriceResolver) is
            // seeded with exactly one hardcoded priceableId ("1") — see
            // PricingServiceProvider. Every other priceableId, including
            // the one this brand-new product's Universal variation just
            // got, has no seeded price. Returning a null price with a flag
            // is preferable to guessing/hardcoding a second seeded id
            // here: ids are auto-increment, so a hardcoded id would only
            // ever coincidentally match one specific product and silently
            // break for every other one.
            return response()->json([
                'product_id' => $product->id(),
                'name' => $product->name(),
                'price' => null,
                'price_unavailable' => true,
            ]);
        }
    }
}
