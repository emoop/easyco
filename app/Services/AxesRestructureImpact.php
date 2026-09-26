<?php

namespace App\Services;

use EasyCo\Catalog\Exceptions\AxesRestructureRefused;
use EasyCo\Catalog\VariationAxis;

/**
 * The read-only report behind the "Change axes" action —
 * catalog-domain-design.md §3.19.8 C, and the return type of
 * VariationAxisRestructure::impact(). Assembled entirely by that service,
 * never by a Filament page: the plan and the refusal decision come from the
 * same place the restructure itself re-derives inside its transaction, so a
 * modal cannot promise a plan the operation then refuses to carry out.
 *
 * THE TWO LISTS ARE §3.19.8 C's OWN: `willBeDeleted` (live STANDARD
 * variations whose combination stops being valid under the new axes AND that
 * have no history and zero stock — CatalogDeletion's own rule, reused) and
 * `willBeArchived` (the rest of them: history or stock > 0, or any of them
 * when the caller may not delete at all — §3.19.9's PRODUCT_DELETE posture,
 * carried in `mayDelete` so the modal can say which of the two it is
 * showing).
 *
 * `willBecomeUnrestorable` IS §3.17's OWN TRADE-OFF, NAMED BEFORE IT HAPPENS:
 * archived STANDARD variations that are restorable TODAY (their combination
 * still fits the current axes) and would not be restorable after this change
 * — `restoreArchivedVariation()` re-validates against the CURRENT axes and
 * throws VariationNotRestorableException, so the merchant is told in advance
 * rather than by a later refusal.
 *
 * `axesDiffer` IS NOT `hasBlockers()`: a product with zero live variations
 * can have both lists empty and still be a real (R5-legal) change — adding a
 * value, removing an axis nothing depends on. A set identical to the current
 * one (R1) is the one case with nothing to do at all, and the modal says so.
 *
 * `refusal` IS THE REASON, NOT A BOOLEAN — a nullable AxesRestructureRefused
 * whose reason enum the UI localises. When it is set, the three lists are
 * empty: there is no plan to show for a set the domain will not declare.
 *
 * `fingerprint` IS THIS EXACT PLAN, AND IT IS THE POINT OF THE WHOLE REPORT
 * (§3.19.8 C): a hash of the proposed axes plus the sorted variation ids of the
 * two lists. The surface that shows this impact carries the fingerprint along
 * with it, and `VariationAxisRestructure::apply()` is given it as the plan the
 * merchant confirmed — it re-derives the plan inside its transaction and
 * refuses, changing nothing, when the two fingerprints differ. That is what
 * makes "the plan you confirmed is the plan that runs" true rather than hoped:
 * a variation added, archived or deleted in between produces a different
 * fingerprint, whatever the axes say. A fingerprint the caller tampers with can
 * only ever cause a refusal, never a different deletion — the deletions are
 * still bounded by the plan `apply()` derives itself.
 */
final class AxesRestructureImpact
{
    /**
     * @param VariationAxis[] $currentAxes
     * @param VariationAxis[] $newAxes
     * @param list<VariationDeletionImpact> $willBeDeleted
     * @param list<VariationDeletionImpact> $willBeArchived
     * @param list<VariationDeletionImpact> $willBecomeUnrestorable
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $baseSku,
        public readonly array $currentAxes,
        public readonly array $newAxes,
        public readonly string $currentAxesSummary,
        public readonly string $newAxesSummary,
        public readonly bool $axesDiffer,
        public readonly bool $mayDelete,
        public readonly array $willBeDeleted,
        public readonly array $willBeArchived,
        public readonly array $willBecomeUnrestorable,
        public readonly string $fingerprint,
        public readonly ?AxesRestructureRefused $refusal,
    ) {
    }

    /** False when the domain will not declare the proposed set at all — the modal then has no submit button. */
    public function canApply(): bool
    {
        return $this->refusal === null;
    }

    /** True when the change actually affects live variations — the case that needs the confirmation fields (§3.19.8 C). */
    public function affectsVariations(): bool
    {
        return $this->willBeDeleted !== [] || $this->willBeArchived !== [];
    }

    /** True when this change would free identifiers, so the "cannot be undone" part is real (§3.19.11). */
    public function deletesVariations(): bool
    {
        return $this->willBeDeleted !== [];
    }
}
