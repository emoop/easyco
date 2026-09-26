<?php

namespace App\Services;

/**
 * What a completed axis restructure actually did — the counters the success
 * notification reports, and nothing else. The two axis summaries a caller might
 * expect here live in the activity log instead (VariationAxisRestructure writes
 * that entry itself, from the same summaries the modal showed), so this object
 * carries only what is genuinely result-shaped.
 *
 * THE COUNTS ARE MEASURED, NOT PROMISED: they come from what the operation did
 * inside its transaction, so a merchant who confirmed "2 deleted" against a
 * product that changed in between sees the real numbers — including
 * `restoredVariationCount`, which is the §3.9 revival-by-signature path (a
 * combination the new axes still allow that an ARCHIVED variation already owns
 * is restored with its own SKU, never recreated).
 *
 * `changed` is false for the R1 no-op: the same axis set was submitted, so
 * nothing was written, nothing was logged, and no combinations were generated.
 * The modal's own text says so before the merchant submits.
 */
final class AxesRestructureResult
{
    public function __construct(
        public readonly int $deletedVariationCount,
        public readonly int $archivedVariationCount,
        public readonly int $createdVariationCount,
        public readonly int $restoredVariationCount,
        public readonly bool $changed,
    ) {
    }
}
