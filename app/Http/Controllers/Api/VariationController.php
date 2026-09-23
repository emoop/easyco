<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Exceptions\VariationNotRestorableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HTTP surface for Product::restoreArchivedVariation() (§3.17) — bringing
 * an ARCHIVED STANDARD variation back to DRAFT, keeping its id/sku/barcode.
 * Kept as its own controller rather than a method on VariationMediaController
 * or StockLevelController: this is a status transition on the Variation
 * itself, not a sub-resource of it, so it gets its own class the same way
 * VariableProductController is its own class rather than folding into
 * ProductController.
 *
 * Only one route: `POST /api/variations/{variationId}/restore` — a state
 * transition on a sub-resource, matching this API's existing nested-action
 * precedent (`POST /variations/{variationId}/media`,
 * `PUT /variations/{variationId}/stock`), deliberately NOT a general
 * `PATCH /variations/{variationId}` status mutator (that would need its own
 * guardrails/review and is out of scope here).
 */
class VariationController extends Controller
{
    public function __construct(
        private readonly VariationRepository $variations,
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * Both VariationNotRestorableException (axes drifted while archived)
     * and \LogicException (wrong type/status, or the impossible "doesn't
     * belong to this product" case) map to a 422 carrying the domain
     * exception's own message — the same mapping
     * VariableProductController::store() already uses for a domain
     * \LogicException. Restoring flips ARCHIVED -> DRAFT only:
     * is_visible/is_purchasable deliberately stay false, the merchant
     * re-activates separately (§3.17).
     */
    public function restore(Request $request, string $variationId): JsonResponse
    {
        $request->merge(['variation_id' => $variationId]);
        // Soft-delete-aware on purpose: VariationModel uses SoftDeletes,
        // and EloquentVariationRepository::findById() below respects that
        // global scope, so the domain layer's own existence semantics
        // already treat a soft-deleted variation as nonexistent. A plain
        // 'exists:catalog_variations,id' rule is a raw, non-scoped DB
        // query that does NOT see it that way — without ->whereNull(...),
        // the same request would behave differently for a soft-deleted id
        // (validation passes, then the domain read below returns null) vs.
        // a truly pruned id (validation fails), a distinction a client has
        // no way to make and should never need to. Matching the rule to
        // the domain's own view keeps both cases the same clean 422.
        $request->validate([
            'variation_id' => ['required', Rule::exists('catalog_variations', 'id')->whereNull('deleted_at')],
        ]);

        $variation = $this->variations->findById($variationId);

        // Pure defense-in-depth now that the validation rule above is
        // soft-delete aware: this branch is unreachable by construction
        // under normal request handling (a soft-deleted or nonexistent id
        // already failed validation above) — it would only fire if the
        // row were deleted in the narrow window between validation and
        // this read. Kept, not removed, for the same reason the two
        // RuntimeException checks below exist: a genuine data-integrity
        // surprise here is a bug signal, not a case to silently paper
        // over.
        if ($variation === null) {
            throw new \RuntimeException(
                "Variation \"{$variationId}\" passed validation but could not be reloaded from the domain layer."
            );
        }

        $product = $this->products->findByIdWithVariations($variation->productId());

        if ($product === null) {
            throw new \RuntimeException(
                "Variation \"{$variationId}\" resolves to a product that could not be reloaded from the domain layer."
            );
        }

        // restoreArchivedVariation() requires the exact instance from the
        // aggregate's own variations() (identity-checked via in_array(...,
        // true)) — never the standalone instance loaded above.
        $ownedVariation = null;
        foreach ($product->variations() as $candidate) {
            if ($candidate->id() === $variation->id()) {
                $ownedVariation = $candidate;
                break;
            }
        }

        if ($ownedVariation === null) {
            throw new \RuntimeException(
                "Variation \"{$variationId}\" could not be found among its own product's reloaded variations."
            );
        }

        try {
            $product->restoreArchivedVariation($ownedVariation);
        } catch (VariationNotRestorableException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->products->save($product);

        return response()->json([
            'variation_id' => $ownedVariation->id(),
            'product_id' => $product->id(),
            'sku' => $ownedVariation->sku(),
            'barcode' => $ownedVariation->barcode(),
            'status' => $ownedVariation->status()->value,
        ]);
    }
}
