<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\PromotionScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for a Promotion's scope conditions — mirrors
 * ProductCategoryController's attach/list/detach shape, with two real
 * differences from that precedent:
 *
 * 1. PromotionScopeRepository is attach()/detach($scopeId), not
 *    save()/remove() — different method names, per that contract's own
 *    docblock ("deliberately not save()/delete()").
 *
 * 2. NO `exists:` VALIDATION ON scope_reference_id, unlike
 *    ProductCategoryController's `exists:catalog_categories,id` on
 *    category_id. PromotionScope's own docblock (and its
 *    PromotionScopeType enum docblock, and promotion_scopes' own
 *    migration comment) states three separate times that this package
 *    deliberately never validates scope_reference_id against whatever
 *    domain actually owns it — "that's a cross-domain concern outside
 *    this entity." This controller mirrors that posture: only the
 *    structural shape is validated (a real PromotionScopeType, a real
 *    PromotionScopeMode, a non-empty string reference id), not whether
 *    the id actually resolves to anything in Catalog/Account. A typo'd
 *    id will silently never match anything at resolution time — the
 *    same shape of gap 11744f3 fixed for Cart→Pricing scope matching —
 *    a deliberate tradeoff this prompt keeps rather than unilaterally
 *    tightening.
 *
 * No duplicate-attach exception to catch: promotion_scopes has no
 * unique constraint at all (confirmed via its own migration), so
 * attaching the same scope twice is a real, supported no-op-that-isn't
 * — it simply creates two rows.
 */
class PromotionScopeController extends Controller
{
    public function __construct(
        private readonly PromotionScopeRepository $promotionScopes,
    ) {
    }

    public function store(Request $request, string $promotionId): JsonResponse
    {
        $request->merge(['promotion_id' => $promotionId]);

        $validated = $request->validate([
            'promotion_id' => 'required|exists:promotions,id',
            'scope_type' => 'required|in:brand,category,tag,attribute_value,product,account',
            'scope_reference_id' => 'required|string',
            'mode' => 'required|in:include,exclude',
        ]);

        $scope = new PromotionScope(
            id: null,
            promotionId: $promotionId,
            scopeType: PromotionScopeType::from($validated['scope_type']),
            scopeReferenceId: (string) $validated['scope_reference_id'],
            mode: PromotionScopeMode::from($validated['mode']),
        );

        $this->promotionScopes->attach($scope);

        return response()->json($this->toListItem($scope), 201);
    }

    public function index(Request $request, string $promotionId): JsonResponse
    {
        $request->merge(['promotion_id' => $promotionId]);
        $request->validate([
            'promotion_id' => 'required|exists:promotions,id',
        ]);

        $items = array_map(
            fn (PromotionScope $scope) => $this->toListItem($scope),
            $this->promotionScopes->findByPromotionId($promotionId)
        );

        return response()->json(['data' => $items]);
    }

    public function destroy(Request $request, string $promotionId, string $scopeId): JsonResponse
    {
        $request->merge(['promotion_id' => $promotionId]);
        $request->validate([
            'promotion_id' => 'required|exists:promotions,id',
        ]);

        $scope = $this->findOwnedScope($promotionId, $scopeId);

        if ($scope === null) {
            return response()->json([
                'message' => "No scope {$scopeId} found for promotion {$promotionId}.",
            ], 404);
        }

        $this->promotionScopes->detach($scopeId);

        return response()->json(null, 204);
    }

    private function toListItem(PromotionScope $scope): array
    {
        return [
            'id' => $scope->id(),
            'promotion_id' => $scope->promotionId(),
            'scope_type' => $scope->scopeType()->value,
            'scope_reference_id' => $scope->scopeReferenceId(),
            'mode' => $scope->mode()->value,
        ];
    }

    /**
     * Ownership check: a scope id belonging to a DIFFERENT promotion
     * must be treated the same as one that doesn't exist at all — never
     * detachable just by knowing the scope id. Mirrors
     * ProductCategoryController::findOwnedPivot() exactly.
     */
    private function findOwnedScope(string $promotionId, string $scopeId): ?PromotionScope
    {
        foreach ($this->promotionScopes->findByPromotionId($promotionId) as $scope) {
            if ($scope->id() === $scopeId) {
                return $scope;
            }
        }

        return null;
    }
}
