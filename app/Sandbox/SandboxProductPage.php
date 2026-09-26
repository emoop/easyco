<?php

namespace App\Sandbox;

use App\Services\CatalogScopeResolver;
use App\Services\ProductPriceDisplay;
use App\Services\ProductPriceRangeProvider;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\ProductType;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Variation;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Pricing\Contracts\PriceContext;
use EasyCo\Pricing\Contracts\PriceQuote;
use EasyCo\Pricing\Contracts\PriceResolver;
use EasyCo\Pricing\DefaultCurrency;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;

/**
 * Assembles the sandbox product page — prompt D, D5.
 *
 * WHERE EVERY PIECE OF DATA COMES FROM, AND WHY NOTHING HERE IS COMPUTED
 * LOCALLY (CLAUDE.md's "no business rule is duplicated" rule):
 * - which product/variations may be shown at all — SandboxCatalogReader
 *   (D3, provisional, one place; D3's variation rule has TWO branches —
 *   a SIMPLE product's UNIVERSAL variation on status alone, a VARIABLE
 *   product's STANDARD variations on status + is_visible);
 * - price — EasyCo\Pricing only: ProductPriceRangeProvider::forProduct()
 *   for the product-level range, and PriceResolver::resolve() with a
 *   PriceContext built from CatalogScopeResolver::forVariations() for
 *   each variation. No price table, no price list, no arithmetic.
 * - stock — EasyCo\Inventory's StockLevelRepository only.
 * - purchasable — the DOMAIN's own Variation::isEffectivelyPurchasable(),
 *   called on a real Variation loaded through VariationRepository. It is
 *   deliberately NOT re-derived from the two columns D3 also checks
 *   (status/is_visible): isEffectivelyPurchasable() is the domain's own
 *   definition of that idea, and a second, sandbox-local version of it
 *   would be exactly the duplicated rule this project forbids.
 * - display — App\Services\ProductPriceDisplay, the admin's own rule
 *   (D6), for both the product-level range and each variation's quote.
 *
 * BATCHED WHEREVER THE CONTRACTS ALLOW IT, because the contracts decide
 * this, not taste: VariationRepository::findByIds() and
 * CatalogScopeResolver::forVariations() both exist precisely for a set of
 * ids, so this page loads every visible variation's domain object and
 * every variation's scope data in one call each, never one per row.
 * forVariation() is documented by its own class as a thin wrapper over
 * forVariations() — so batching changes no behavior, it only removes an
 * N+1 this page would otherwise have.
 *
 * TWO REAL, BOUNDED PER-VARIATION COSTS THAT REMAIN, FLAGGED NOT HIDDEN
 * (D4's "must not grow with rows" requirement is about the LIST page;
 * this is a single record's page, and these are contract limitations, not
 * oversights). A SIMPLE product has exactly one variation, so these costs
 * cannot exceed one call there at all: its page shows the product-level
 * price range plus that one variation's stock and purchasable state.
 * - PriceResolver::resolve() takes ONE PriceContext (its own contract), so
 *   D5's prescribed per-variation resolve is genuinely one call per
 *   visible variation. A future storefront stage could switch to the
 *   batched PriceRangeResolver::resolveQuotes() ProductPriceRangeProvider
 *   already uses — noted here, not done in stage 1, because D5 asks for
 *   PriceResolver specifically.
 * - StockLevelRepository has no batched read (its contract is
 *   findByVariationId()/save()/increase()/decrease()), so stock is one
 *   read per visible variation. Both costs scale with the number of
 *   VISIBLE VARIATIONS OF ONE PRODUCT, never with the store's size.
 *
 * FAIL-SOFT (D8) WHERE DATA CAN GENUINELY BE MISSING, FAIL-LOUD WHERE THE
 * CONFIGURATION IS BROKEN: a variation row that vanished between the id
 * read and the domain load is skipped rather than crashing the page; a
 * missing axis definition/value renders '—'; an unresolvable price renders
 * '—' (D5's own words). An exception that is NOT "no price configured" (a
 * broken resolver binding, a database error) is deliberately NOT
 * swallowed — a sandbox rendering silently wrong data would be worse than
 * one that fails loudly.
 */
final class SandboxProductPage
{
    public function __construct(
        private readonly SandboxCatalogReader $reader,
        private readonly SandboxProductGallery $gallery,
        private readonly VariationRepository $variations,
        private readonly AttributeDefinitionRepository $attributeDefinitions,
        private readonly AttributeValueRepository $attributeValues,
        private readonly CatalogScopeResolver $scopeResolver,
        private readonly PriceResolver $priceResolver,
        private readonly ProductPriceRangeProvider $priceRanges,
        private readonly ProductPriceDisplay $priceDisplay,
        private readonly StockLevelRepository $stockLevels,
    ) {
    }

    /**
     * null means "not a listed product" (D3) — the controller's own 404,
     * so this class never aborts a response itself.
     *
     * D3's TWO VARIATION BRANCHES are resolved here and nowhere else
     * (SandboxCatalogReader's own docblock states the rule): a SIMPLE
     * product contributes its single UNIVERSAL variation on status alone,
     * a VARIABLE product its STANDARD variations with status ACTIVE +
     * is_visible. Everything downstream — which domain objects are loaded,
     * the scope data, the gallery's variation images, the table rows — is
     * driven by that one id list, so the two branches cannot drift apart.
     */
    public function forProduct(string $productId): ?SandboxProductPageView
    {
        $product = $this->reader->findListedProduct($productId);

        if ($product === null) {
            return null;
        }

        $isSimple = $product->type === ProductType::SIMPLE->value;

        $variationIds = $isSimple
            ? $this->universalVariationIds($productId)
            : $this->listedVariationIds($productId);

        $domainVariations = $variationIds === [] ? [] : $this->variations->findByIds($variationIds);

        $scopeDataByVariationId = $variationIds === []
            ? []
            : $this->scopeResolver->forVariations($variationIds);

        [$axisNames, $valueLabels] = $this->axisLabels($domainVariations);

        // Rows are a VARIABLE product's shape only. A SIMPLE product's
        // variation is never a table row — it IS the product, and the page
        // renders its stock + purchasable state under the price instead
        // (SandboxUniversalVariation's own docblock).
        $rows = [];

        if (! $isSimple) {
            foreach ($variationIds as $variationId) {
                $variation = $domainVariations[$variationId] ?? null;

                if ($variation === null) {
                    continue;
                }

                $scope = $scopeDataByVariationId[$variationId] ?? ['productId' => null, 'matchingScopeReferenceIds' => []];

                $rows[] = new SandboxVariationRow(
                    id: $variationId,
                    sku: $variation->sku(),
                    axisLabels: $this->axisLabelsFor($variation, $axisNames, $valueLabels),
                    priceHtml: $this->priceDisplay->quoteHtml($this->resolveQuote($variationId, $scope)),
                    stockQuantity: $this->stockLevels->findByVariationId($variationId)->quantity(),
                    purchasable: $variation->isEffectivelyPurchasable(),
                );
            }
        }

        return new SandboxProductPageView(
            id: (string) $product->id,
            name: (string) $product->name,
            brandName: $product->brand?->name,
            description: $product->description,
            priceHtml: $this->priceDisplay->rangeHtml($this->priceRanges->forProduct((string) $product->id)),
            isSimple: $isSimple,
            universalVariation: $isSimple ? $this->universalVariationState($variationIds, $domainVariations) : null,
            images: $this->gallery->forProduct((string) $product->id, $variationIds),
            variations: $rows,
        );
    }

    /**
     * D3's SIMPLE branch as an id list, so everything after this point is
     * branch-agnostic. Empty when the universal variation is not ACTIVE:
     * the domain does not produce that state for a listed product
     * (Product::createSimple() creates the universal variation ACTIVE, and
     * only explicit operations on the Variation itself could change that),
     * so this is defensive against a hand-edited row rather than a
     * business case — and the page then renders neither stock nor
     * purchasable instead of a misleading zero.
     *
     * @return string[]
     */
    private function universalVariationIds(string $productId): array
    {
        $model = $this->reader->universalVariationModel($productId);

        return $model === null ? [] : [(string) $model->id];
    }

    /**
     * D3's VARIABLE branch as an id list.
     *
     * @return string[]
     */
    private function listedVariationIds(string $productId): array
    {
        return array_map(
            static fn (VariationModel $model): string => (string) $model->id,
            $this->reader->listedVariationModels($productId),
        );
    }

    /**
     * The SIMPLE branch's two customer-visible facts, or null when there is
     * no ACTIVE universal variation to report.
     *
     * Both come from exactly where the VARIABLE branch's rows get them: the
     * Inventory contract for the quantity, and the DOMAIN's own
     * Variation::isEffectivelyPurchasable() (status ACTIVE + is_purchasable
     * — the same answer Cart and POS act on) for purchasability. The
     * universal variation is deliberately not turned into a
     * SandboxVariationRow: it has no axes and is never customer-selectable
     * (see SandboxUniversalVariation).
     *
     * @param string[] $variationIds
     * @param array<string, Variation> $domainVariations
     */
    private function universalVariationState(array $variationIds, array $domainVariations): ?SandboxUniversalVariation
    {
        $variationId = $variationIds[0] ?? null;
        $variation = $variationId === null ? null : ($domainVariations[$variationId] ?? null);

        if ($variationId === null || $variation === null) {
            return null;
        }

        return new SandboxUniversalVariation(
            id: $variationId,
            stockQuantity: $this->stockLevels->findByVariationId($variationId)->quantity(),
            purchasable: $variation->isEffectivelyPurchasable(),
        );
    }

    /**
     * Axis-name and axis-value lookups for EVERY variation at once: one
     * AttributeDefinitionRepository::all() read (not one findById() per
     * axis) plus one AttributeValueRepository::findByAttributeDefinitionId()
     * read per DISTINCT axis definition this product actually uses — the
     * bounded, contract-shaped way to get labels without an N+1 over
     * variations, and without a new repository method (the batch reads
     * this codebase authorizes elsewhere).
     *
     * @param array<string, Variation> $domainVariations
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private function axisLabels(array $domainVariations): array
    {
        $definitionIds = [];

        foreach ($domainVariations as $variation) {
            foreach (array_keys($variation->attributeAssignments()) as $definitionId) {
                $definitionIds[(string) $definitionId] = true;
            }
        }

        if ($definitionIds === []) {
            return [[], []];
        }

        $axisNames = [];

        foreach ($this->attributeDefinitions->all() as $definition) {
            $axisNames[(string) $definition->id()] = $definition->name();
        }

        $valueLabels = [];

        foreach (array_keys($definitionIds) as $definitionId) {
            $labels = [];

            foreach ($this->attributeValues->findByAttributeDefinitionId($definitionId) as $value) {
                $labels[(string) $value->id()] = $value->value();
            }

            $valueLabels[$definitionId] = $labels;
        }

        return [$axisNames, $valueLabels];
    }

    /**
     * One variation's assignments as displayable name/value pairs. '—'
     * for an assignment pointing at a definition or value row that no
     * longer exists (a real state — catalog attribute values CAN be
     * deleted, see AttributeValueResource's own delete path) rather than
     * a TypeError or a blank cell.
     *
     * @param array<string, string> $axisNames
     * @param array<string, array<string, string>> $valueLabels
     * @return array<int, array{name: string, value: string}>
     */
    private function axisLabelsFor(Variation $variation, array $axisNames, array $valueLabels): array
    {
        $labels = [];

        foreach ($variation->attributeAssignments() as $definitionId => $valueId) {
            $labels[] = [
                'name' => $axisNames[(string) $definitionId] ?? '—',
                'value' => $valueLabels[(string) $definitionId][(string) $valueId] ?? '—',
            ];
        }

        return $labels;
    }

    /**
     * The one place a variation's price is resolved, and the one place
     * "no configured price" is turned into null — D5's own "shows '—',
     * never an error". The scope array's shape is
     * CatalogScopeResolver::forVariations()'s documented return shape; the
     * priceableId is the variation's own id (Variation::priceableId() ===
     * id(), that accessor's own docblock), and the currency is
     * DefaultCurrency, exactly as CartController's single-variation
     * resolve already does it.
     *
     * @param array{productId: ?string, matchingScopeReferenceIds: array<string, string[]>} $scope
     */
    private function resolveQuote(string $variationId, array $scope): ?PriceQuote
    {
        try {
            return $this->priceResolver->resolve(new PriceContext(
                priceableId: $variationId,
                quantity: 1,
                currency: DefaultCurrency::get()->code(),
                productId: $scope['productId'],
                matchingScopeReferenceIds: $scope['matchingScopeReferenceIds'],
            ));
        } catch (PriceNotConfiguredException) {
            return null;
        }
    }
}
