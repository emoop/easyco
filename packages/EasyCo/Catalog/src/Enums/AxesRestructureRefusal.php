<?php

namespace EasyCo\Catalog\Enums;

/**
 * WHY a proposed axis re-declaration cannot be carried out by the
 * restructure flow — catalog-domain-design.md §3.19.8 C. Two reasons, and
 * only two:
 *
 *  - the proposed set cannot be DECLARED at all: the domain's own
 *    structural rules refuse it (the same attribute declared twice, an
 *    axis with no values, an attribute that is not usable as a variation
 *    axis). This is not a question about the change being "unsafe" — it is
 *    a set that could never be declared, whatever the variations are;
 *  - the plan the merchant confirmed is not the plan that would now be
 *    executed: the product's variations changed between the impact being
 *    read and the change being applied, so the confirmed fingerprint no
 *    longer matches the freshly derived one. Nothing is done; the merchant
 *    reopens the dialog and reads the current impact.
 *
 * PLAN_CHANGED covers BOTH ways that second situation is detected — the
 * fingerprint comparison before anything is written, and §3.17's own guard
 * refusing the re-declaration after the deletions and archiving have already
 * run inside the transaction (a variation the plan never saw is live and
 * would be orphaned). Same fact, same sentence, same remedy; two reasons for
 * it would only invite the UI to word one of them differently.
 *
 * A REASON CODE, DELIBERATELY NOT A MESSAGE — the same split
 * `VariationDeletionRefusal` and `ProductDeletionRefusal` document: the
 * merchant-facing sentence is rendered in the current locale by the
 * application layer (App\Services\AxesRestructureRefusalMessage), while the
 * exception keeps a plain English sentence for logs and support. The VALUES
 * are the `products.axes_restructure.refusal.{value}` key fragments, so the
 * mapping cannot drift.
 *
 * There is deliberately NO case for "a variation has history or stock, so it
 * will be archived rather than deleted" or for "you may not delete at all":
 * neither is a refusal of the operation. The first is the normal outcome of
 * the two lists §3.19.8 C shows; the second is the permission posture §3.19.9
 * records, reported by the modal, not by an exception.
 */
enum AxesRestructureRefusal: string
{
    /** The proposed set violates a structural axis rule (duplicate axis, empty axis, unusable attribute). */
    case INVALID_AXES = 'invalid_axes';

    /** The product's variations changed since the impact was read: the confirmed plan is not the executable one, nothing was done. */
    case PLAN_CHANGED = 'plan_changed';
}
