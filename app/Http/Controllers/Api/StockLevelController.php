<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read + merchant-set-absolute-value only — no increase()/decrease()
 * HTTP surface in this task, by deliberate scope decision. See
 * inventory-domain-design.md §9.
 */
class StockLevelController extends Controller
{
    public function __construct(
        private readonly StockLevelRepository $stockLevels,
    ) {
    }

    public function show(Request $request, string $variationId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);
        $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
        ]);

        // findByVariationId() never returns null — a variation with no
        // row yet is quantity=0, not a 404 (inventory-domain-design.md §5).
        $stockLevel = $this->stockLevels->findByVariationId($variationId);

        return response()->json([
            'variation_id' => $stockLevel->variationId(),
            'quantity' => $stockLevel->quantity(),
        ]);
    }

    public function update(Request $request, string $variationId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);
        $validated = $request->validate([
            'variation_id' => 'required|exists:catalog_variations,id',
            'quantity' => 'required|integer|min:0',
        ]);

        $stockLevel = $this->stockLevels->findByVariationId($variationId);
        $stockLevel->setQuantity($validated['quantity']);
        $this->stockLevels->save($stockLevel);

        return response()->json([
            'variation_id' => $stockLevel->variationId(),
            'quantity' => $stockLevel->quantity(),
        ]);
    }
}
