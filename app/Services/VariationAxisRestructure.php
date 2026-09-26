<?php

namespace App\Services;

use App\Providers\CatalogSkuGeneratorServiceProvider;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Enums\VariationType;
use EasyCo\Catalog\Exceptions\AxesRestructureRefused;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Services\VariationCombinationGenerator;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The axis-restructuring flow — catalog-domain-design.md §3.19.8 C, on top of
 * §3.17's directional guard (R1–R5) and §3.9's revival-by-signature.
 *
 * WHAT PROBLEM THIS SOLVES: §3.17 refuses a re-declaration only while it would
 * actually orphan a LIVE variation — so the merchant's own way to make an
 * unsafe change safe is to get those variations out of the way first. Doing
 * that by hand (archive here, delete there, edit the axes, generate) is four
 * operations that must all succeed or none, and `declareVariationAxes()` is
 * the last one to notice if the earlier ones were wrong. This service makes it
 * one operation with one plan.
 *
 * THE PLAN IS DERIVED ONCE, FROM ONE PREDICATE. A live STANDARD variation
 * blocks the change exactly when its own combination stops being valid under
 * the proposed axes — every axis supplied, with an allowed value, and nothing
 * assigned for a definition that is no longer an axis. That single predicate
 * IS what R2/R3/R4 refuse on (§3.17's three rules are the ways a live
 * variation becomes invalid), so this class does not re-implement the guard:
 * it lets the domain's own `declareVariationAxes()` validate the set
 * structurally, and asks the per-variation question for the list. Everything
 * else follows: a blocker with history or stock is archived, a blocker without
 * either is deleted through CatalogDeletion — whose own rule decides that, not
 * a second copy of it here.
 *
 * APPLY IS ONE TRANSACTION, IN THIS ORDER (§3.19.8 C): delete the first list
 * through `CatalogDeletion::deleteVariation()`, so its history/stock/locking
 * checks re-run inside this same transaction; then RE-LOAD the product from
 * the repository — never the pre-deletion aggregate, whose variations array
 * still holds the deleted rows as live objects, which would make R2/R3/R4
 * refuse the re-declaration and make `save()` write rows that no longer exist;
 * archive the second list on that fresh aggregate, in memory — which is
 * exactly what lets the guard pass, since those variations are no longer LIVE
 * by the time the axes are re-declared; then `declareVariationAxes()` (the
 * guard's final word) and ONE `save()` for both; then the new combinations
 * through the existing generation path (`VariationCombinationGenerator` + the
 * `catalog.variation.sku` hook via
 * CatalogSkuGeneratorServiceProvider::variationSkuStrategy()). Any refusal at
 * any step rolls the whole thing back.
 *
 * PERMISSIONS ARE NOT CHECKED HERE (§3.19.9's own rule: the service enforces
 * the invariant, the UI/HTTP layer enforces who may ask). `$mayDelete` is how
 * the caller tells the service whether this staff member holds
 * PRODUCT_DELETE: false means the deletable list is ARCHIVED instead, which is
 * the only behaviour change — nothing is ever deleted on a caller's word
 * alone, and the flag is computed server-side from the real permission, never
 * from submitted data.
 *
 * NOT IN HERE, DELIBERATELY: product deletion (stages 2–3), merchant-defined
 * axis order, and any change to R1–R5 themselves.
 *
 * THE CONFIRMED PLAN IS THE EXECUTED PLAN: `apply()` is given the fingerprint
 * of the plan the merchant was shown (`AxesRestructureImpact::fingerprint`),
 * re-derives the plan inside its own transaction exactly as described above,
 * and REFUSES — changing nothing — when the two fingerprints differ, because
 * then this product's variations changed between the impact being read and the
 * change being applied (`AxesRestructureRefusal::PLAN_CHANGED`). §3.17's guard
 * is the second net under the same fact: it refuses the re-declaration if a
 * live variation the plan never saw would otherwise be orphaned. A fingerprint
 * the caller fabricates can only cause a refusal, never a different deletion —
 * the lists that are deleted and archived are the ones this method derives
 * itself.
 */
final class VariationAxisRestructure
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly CatalogDeletion $deletion,
    ) {
    }

    /**
     * Read-only. Nothing is locked, nothing is written: this is what the
     * confirmation modal renders, and `apply()` re-derives every part of it
     * inside its own transaction regardless of what this returned.
     *
     * @param VariationAxis[] $newAxes
     * @param bool $mayDelete Whether the caller holds PRODUCT_DELETE (§3.19.9). False moves every deletable blocker into the archive list.
     *
     * @throws InvalidArgumentException When the product does not exist.
     */
    public function impact(string $productId, array $newAxes, bool $mayDelete = true): AxesRestructureImpact
    {
        return $this->planFor($this->requireProduct($productId), $newAxes, $mayDelete);
    }

    /**
     * Carries the proposed re-declaration out, or refuses and changes
     * nothing.
     *
     * @param VariationAxis[] $newAxes
     * @param ?string $expectedFingerprint The `fingerprint` of the plan the caller SHOWED the merchant (see AxesRestructureImpact). Null means no plan was confirmed, which is refused like a mismatch rather than treated as consent.
     *
     * @throws AxesRestructureRefused When the proposed set cannot be declared, or the plan the caller confirmed is not the plan that would be executed.
     * @throws \EasyCo\Catalog\Exceptions\VariationNotDeletableException When a variation the plan would delete turns out to have history or stock (a concurrent sale or stock write) — the whole restructure rolls back.
     * @throws InvalidArgumentException When the product does not exist.
     */
    public function apply(string $productId, array $newAxes, bool $mayDelete = true, ?string $expectedFingerprint = null): AxesRestructureResult
    {
        return DB::transaction(function () use ($productId, $newAxes, $mayDelete, $expectedFingerprint): AxesRestructureResult {
            $plan = $this->planFor($this->requireProduct($productId), $newAxes, $mayDelete);

            if (! $plan->canApply()) {
                // The proposed set cannot be declared at all. The same answer
                // the modal showed, reached the same way — an exception rather
                // than a returned report, because there is no result to report.
                throw $plan->refusal;
            }

            // THE CONFIRMED PLAN IS THE EXECUTED PLAN: the fingerprint the
            // caller was given together with the impact must be the fingerprint
            // of the plan derived right here, inside this transaction. This is
            // the check that catches a variation added, archived or deleted
            // between the two — before a single row is touched, and before the
            // generated combinations could be based on a different world.
            if ($expectedFingerprint === null || ! hash_equals($plan->fingerprint, $expectedFingerprint)) {
                throw AxesRestructureRefused::becauseThePlanChanged($plan->productName);
            }

            if (! $plan->axesDiffer) {
                // R1: the identical set. Nothing written, nothing logged —
                // §3.17's own reason for R1 is that a plain re-save must stay
                // harmless, and a no-op restructure is that same harmlessness.
                return new AxesRestructureResult(
                    deletedVariationCount: 0,
                    archivedVariationCount: 0,
                    createdVariationCount: 0,
                    restoredVariationCount: 0,
                    changed: false,
                );
            }

            foreach ($plan->willBeDeleted as $variationImpact) {
                $this->deletion->deleteVariation($variationImpact->variationId);
            }

            // Never the pre-deletion aggregate — see the class docblock.
            $product = $this->requireProduct($productId);

            $logger = app(ActivityLogger::class);

            foreach ($plan->willBeArchived as $variationImpact) {
                $variation = $this->variationById($product, $variationImpact->variationId, $plan->productName);

                $logger->logFieldChanged(
                    'product',
                    $productId,
                    "variation[{$variationImpact->variationId}].status",
                    $variation->status()->value,
                    VariationStatus::ARCHIVED->value,
                );

                $variation->archive();
            }

            // Logged BEFORE declareVariationAxes() runs, so the entry's own
            // old value is the real pre-change summary — same posture (and the
            // same format) as EditVariableProduct's normal-save axes entry.
            $logger->logFieldChanged(
                'product',
                $productId,
                'variation_axes',
                $plan->currentAxesSummary,
                $plan->newAxesSummary,
            );

            try {
                $product->declareVariationAxes($newAxes);
            } catch (UnsafeAxisRedeclarationException) {
                // The plan was overtaken: a variation the impact never saw is
                // live and this change would orphan it. The fingerprint check
                // above is what normally catches this BEFORE any write; this is
                // the same fact discovered one step later, by the domain's own
                // guard — so it is the same refusal.
                throw AxesRestructureRefused::becauseThePlanChanged($plan->productName);
            } catch (InvalidVariationAxisException|\LogicException $e) {
                throw AxesRestructureRefused::becauseTheAxesCannotBeDeclared($plan->productName, $e->getMessage());
            }

            $this->products->save($product);

            [$created, $restored] = $this->generateNewCombinations($product, $newAxes);

            return new AxesRestructureResult(
                deletedVariationCount: count($plan->willBeDeleted),
                archivedVariationCount: count($plan->willBeArchived),
                createdVariationCount: $created,
                restoredVariationCount: $restored,
                changed: true,
            );
        }, attempts: 3);
    }

    /**
     * The whole plan, from ONE pass over the product's own variations — the
     * shared body of impact() and apply(), so the modal and the operation can
     * never disagree about which variations the change affects.
     *
     * @param VariationAxis[] $newAxes
     */
    private function planFor(Product $product, array $newAxes, bool $mayDelete): AxesRestructureImpact
    {
        $productId = (string) $product->id();
        $productName = $product->name();

        // Captured BEFORE the structural check below, which assigns the
        // proposed set to this in-memory aggregate when it succeeds.
        $currentAxes = $product->variationAxes();
        $currentAxesSummary = self::summarize($currentAxes);
        $newAxesSummary = self::summarize($newAxes);
        $axesDiffer = self::axesDiffer($currentAxes, $newAxes);

        $structuralRefusal = null;

        try {
            $product->declareVariationAxes($newAxes);
        } catch (UnsafeAxisRedeclarationException) {
            // Expected: live variations block this change, which is exactly the
            // case this flow exists to resolve (R2/R3/R4). Not a refusal.
        } catch (InvalidVariationAxisException|\LogicException $e) {
            // A set that could never be declared, whatever the variations are.
            $structuralRefusal = AxesRestructureRefused::becauseTheAxesCannotBeDeclared($productName, $e->getMessage());
        }

        $willBeDeleted = [];
        $willBeArchived = [];
        $willBecomeUnrestorable = [];

        if ($structuralRefusal === null) {
            $currentAxesByDefinitionId = self::axesByDefinitionId($currentAxes);
            $newAxesByDefinitionId = self::axesByDefinitionId($newAxes);

            // ONE source for "can this variation be deleted": stage 3's own
            // product impact, whose per-variation verdicts carry the history
            // count, the stock quantity and CatalogDeletion's refusal. ONLY the
            // per-variation list is used — the product-level `refusal` is about
            // deleting the PRODUCT (G-D3), and an axis change is routinely made
            // on a draft product.
            $impactsByVariationId = [];
            foreach ($this->deletion->impactForProduct($productId)->variations as $variationImpact) {
                $impactsByVariationId[$variationImpact->variationId] = $variationImpact;
            }

            foreach ($product->variations() as $variation) {
                $this->classifyVariation(
                    $variation,
                    $impactsByVariationId,
                    $currentAxesByDefinitionId,
                    $newAxesByDefinitionId,
                    $mayDelete,
                    $willBeDeleted,
                    $willBeArchived,
                    $willBecomeUnrestorable,
                );
            }
        }

        return new AxesRestructureImpact(
            productId: $productId,
            productName: $productName,
            baseSku: $product->baseSku(),
            currentAxes: $currentAxes,
            newAxes: $newAxes,
            currentAxesSummary: $currentAxesSummary,
            newAxesSummary: $newAxesSummary,
            axesDiffer: $axesDiffer,
            mayDelete: $mayDelete,
            willBeDeleted: $willBeDeleted,
            willBeArchived: $willBeArchived,
            willBecomeUnrestorable: $willBecomeUnrestorable,
            // The plan's own identity: see fingerprintFor() for exactly what is
            // hashed, and apply() for the comparison that makes it binding.
            fingerprint: self::fingerprintFor($newAxes, $willBeDeleted, $willBeArchived),
            refusal: $structuralRefusal,
        );
    }

    /**
     * One variation's verdict for this plan — appended to exactly one of the
     * three lists, or to none.
     *
     * @param array<string, VariationDeletionImpact> $impactsByVariationId
     * @param array<string, VariationAxis> $currentAxesByDefinitionId
     * @param array<string, VariationAxis> $newAxesByDefinitionId
     * @param list<VariationDeletionImpact> $willBeDeleted
     * @param list<VariationDeletionImpact> $willBeArchived
     * @param list<VariationDeletionImpact> $willBecomeUnrestorable
     */
    private function classifyVariation(
        Variation $variation,
        array $impactsByVariationId,
        array $currentAxesByDefinitionId,
        array $newAxesByDefinitionId,
        bool $mayDelete,
        array &$willBeDeleted,
        array &$willBeArchived,
        array &$willBecomeUnrestorable,
    ): void {
        if ($variation->type() !== VariationType::STANDARD) {
            // A UNIVERSAL variation is never part of an axis change (G-D2: it
            // goes only together with its whole product).
            return;
        }

        $variationImpact = $impactsByVariationId[(string) $variation->id()] ?? null;

        if ($variationImpact === null) {
            // Defensive only: without CatalogDeletion's own facts there is
            // nothing safe to do with this row. Leaving it live makes the
            // re-declaration refuse loudly — never a guess.
            return;
        }

        $assignments = $variation->attributeAssignments();

        if ($variation->status() === VariationStatus::ARCHIVED) {
            // §3.17's own trade-off, named before it happens: an archived
            // variation restorable TODAY whose combination the new axes no
            // longer allow becomes unrestorable, and the modal says so instead
            // of letting the Restore action refuse later. One that is already
            // unrestorable is not news, so it is not listed again.
            if (! $this->combinationIsValidUnder($assignments, $newAxesByDefinitionId)
                && $this->combinationIsValidUnder($assignments, $currentAxesByDefinitionId)) {
                $willBecomeUnrestorable[] = $variationImpact;
            }

            return;
        }

        if ($this->combinationIsValidUnder($assignments, $newAxesByDefinitionId)) {
            // This change leaves the variation perfectly valid (R5's own
            // "otherwise: allow" case, per variation).
            return;
        }

        if ($mayDelete && $variationImpact->isDeletable()) {
            $willBeDeleted[] = $variationImpact;

            return;
        }

        // History or stock > 0 (CatalogDeletion's own rule), or a caller
        // without PRODUCT_DELETE (§3.19.9) — archive instead, which is exactly
        // why the modal can tell the merchant those SKUs stay occupied.
        $willBeArchived[] = $variationImpact;
    }

    /**
     * The ONE predicate behind every list in the plan: a variation's own
     * combination fits the given axes iff every one of them is supplied with an
     * allowed value AND nothing is assigned for a definition that is not an
     * axis any more. §3.17's R2/R3/R4 are exactly the ways a LIVE variation
     * stops fitting, so a variation that fails this test is a variation the
     * domain's own guard would refuse to strand — the plan and the guard agree
     * by construction, not by a second copy of the rules.
     *
     * @param array<int|string, int|string> $assignments
     * @param array<string, VariationAxis> $axesByDefinitionId
     */
    private function combinationIsValidUnder(array $assignments, array $axesByDefinitionId): bool
    {
        foreach ($axesByDefinitionId as $definitionId => $axis) {
            $valueId = $assignments[$definitionId] ?? null;

            if ($valueId === null || ! $axis->isAllowedValueId((string) $valueId)) {
                return false;
            }
        }

        foreach ($assignments as $definitionId => $valueId) {
            if (! isset($axesByDefinitionId[(string) $definitionId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The existing generation path, unchanged, on the aggregate that already
     * carries the newly declared axes: the cartesian product of those axes, the
     * real `catalog.variation.sku` hook, and §3.9's revival-by-signature for a
     * combination an ARCHIVED variation already owns (restored with its own
     * SKU, never recreated).
     *
     * @param VariationAxis[] $newAxes
     * @return array{0: int, 1: int} created, restored
     */
    private function generateNewCombinations(Product $product, array $newAxes): array
    {
        if ($newAxes === []) {
            // No axes, no combinations — declareVariationAxes() allows an empty
            // set (a VARIABLE product with nothing declared yet), and the
            // generator's cartesian product over nothing is empty.
            return [0, 0];
        }

        $valuesByAxis = [];
        foreach ($product->variationAxes() as $axis) {
            $valuesByAxis[$axis->attributeDefinitionId()] = $axis->allowedValueIds();
        }

        $generated = (new VariationCombinationGenerator())->generate(
            $product,
            $valuesByAxis,
            CatalogSkuGeneratorServiceProvider::variationSkuStrategy($product),
        );

        $created = 0;
        $restored = 0;

        foreach ($generated as $variation) {
            // A non-null id means §3.9 revived an ARCHIVED row; a null one is a
            // genuinely new Variation about to be persisted — the same two-real-
            // counts distinction the page's own Generate action reports.
            $variation->id() === null ? $created++ : $restored++;
        }

        if ($generated !== []) {
            $this->products->save($product);
        }

        return [$created, $restored];
    }

    /** The aggregate's own instance of this variation, or a refusal — never a half-applied plan. */
    private function variationById(Product $product, string $variationId, string $productName): Variation
    {
        foreach ($product->variations() as $variation) {
            if ((string) $variation->id() === $variationId) {
                return $variation;
            }
        }

        // Concurrently deleted, or never this product's row: the outer
        // transaction rolls the whole restructure back rather than archiving
        // something the merchant never saw — the same "the plan changed" answer
        // the fingerprint check gives up front.
        throw AxesRestructureRefused::becauseThePlanChanged($productName);
    }

    private function requireProduct(string $productId): Product
    {
        $product = $this->products->findByIdWithVariations($productId);

        if ($product === null) {
            throw new InvalidArgumentException("Product \"{$productId}\" does not exist.");
        }

        return $product;
    }

    /**
     * The human-readable axis summary used by the activity log's
     * 'variation_axes' entries and by the modal's current-vs-new comparison —
     * real definition/value NAMES (e.g. "Color: Black, White; Size: S, M"), not
     * raw ids. A definition/value whose model has since been deleted falls back
     * to its own code/id, the same defensive posture EditVariableProduct's own
     * private version had. ONE implementation for both callers (the page's
     * normal save and this service's restructure), so two log entries about the
     * same fact cannot be formatted differently.
     *
     * @param VariationAxis[] $axes
     */
    public static function summarize(array $axes): string
    {
        $parts = [];

        foreach ($axes as $axis) {
            $definitionName = AttributeDefinitionModel::find($axis->attributeDefinitionId())?->name
                ?? $axis->attributeDefinitionCode();

            $valueNames = array_map(
                static fn (string $valueId): string => AttributeValueModel::find($valueId)?->value ?? $valueId,
                $axis->allowedValueIds(),
            );

            $parts[] = "{$definitionName}: ".implode(', ', $valueNames);
        }

        return implode('; ', $parts);
    }

    /**
     * The order-insensitive set comparison the normal save and the restructure
     * both need ("would this re-declaration change anything at all?"): the same
     * definition-id keys and, per definition, the same allowed-value ids.
     * Identical to §3.17's own R1 test, which is what makes it the right
     * question to ask before writing anything.
     *
     * @param VariationAxis[] $currentAxes
     * @param VariationAxis[] $newAxes
     */
    public static function axesDiffer(array $currentAxes, array $newAxes): bool
    {
        $current = self::valueIdsByDefinitionId($currentAxes);
        $new = self::valueIdsByDefinitionId($newAxes);

        if (self::normalizedIdSet(array_keys($current)) !== self::normalizedIdSet(array_keys($new))) {
            return true;
        }

        foreach ($current as $definitionId => $valueIds) {
            if ($valueIds !== $new[$definitionId]) {
                return true;
            }
        }

        return false;
    }

    /** @param VariationAxis[] $axes @return array<string, list<string>> definition id => normalized value ids */
    private static function valueIdsByDefinitionId(array $axes): array
    {
        $valueIds = [];

        foreach ($axes as $axis) {
            $valueIds[$axis->attributeDefinitionId()] = self::normalizedIdSet($axis->allowedValueIds());
        }

        return $valueIds;
    }

    /** @param VariationAxis[] $axes @return array<string, VariationAxis> */
    private static function axesByDefinitionId(array $axes): array
    {
        $byDefinitionId = [];

        foreach ($axes as $axis) {
            $byDefinitionId[$axis->attributeDefinitionId()] = $axis;
        }

        return $byDefinitionId;
    }

    /**
     * The plan's fingerprint — what `apply()` binds the merchant's confirmation
     * to (§3.19.8 C).
     *
     * WHAT IS HASHED, and why exactly this: the PROPOSED AXES (per definition,
     * the allowed value ids, both order-insensitive — the same normalized shape
     * axesDiffer() compares) plus the SORTED variation ids of the two lists the
     * impact shows. The axes alone would not notice a variation appearing,
     * archiving or being deleted; the lists alone would not notice the axes
     * being edited. Together they are the whole plan: the permission mode is in
     * there too, implicitly, because a caller without PRODUCT_DELETE gets an
     * empty delete list and a full archive list.
     *
     * WHAT IS NOT HASHED, deliberately: anything about how the lists will be
     * EXECUTED (counts, order, SKUs). Two plans that differ only in those are
     * the same plan — and `apply()` derives its own execution order anyway.
     *
     * @param VariationAxis[] $newAxes
     * @param list<VariationDeletionImpact> $willBeDeleted
     * @param list<VariationDeletionImpact> $willBeArchived
     */
    private static function fingerprintFor(array $newAxes, array $willBeDeleted, array $willBeArchived): string
    {
        $axes = self::valueIdsByDefinitionId($newAxes);
        ksort($axes, SORT_STRING);

        return hash('sha256', (string) json_encode([
            'axes' => $axes,
            'will_be_deleted' => self::sortedVariationIds($willBeDeleted),
            'will_be_archived' => self::sortedVariationIds($willBeArchived),
        ]));
    }

    /**
     * @param list<VariationDeletionImpact> $impacts
     * @return list<string>
     */
    private static function sortedVariationIds(array $impacts): array
    {
        $ids = array_map(
            static fn (VariationDeletionImpact $impact): string => $impact->variationId,
            $impacts,
        );

        sort($ids, SORT_STRING);

        return $ids;
    }

    /**
     * @param array<int|string, int|string> $ids
     * @return list<string>
     */
    private static function normalizedIdSet(array $ids): array
    {
        $unique = array_values(array_unique(array_map(strval(...), $ids)));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
