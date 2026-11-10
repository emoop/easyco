<?php

namespace App\Services\MassEdit;

/** P6d: the counts of a mass edit: products changed / unchanged (a no-op) / skipped (no longer exist), the manual prices removed (the reset only), and how many products were handed to the queue. */
final class MassEditResult
{
    public function __construct(
        public int $changed = 0,
        public int $unchanged = 0,
        public int $skipped = 0,
        public int $removedPrices = 0,
        public int $queued = 0,
    ) {
    }

    public function merge(self $other): self
    {
        $this->changed += $other->changed;
        $this->unchanged += $other->unchanged;
        $this->skipped += $other->skipped;
        $this->removedPrices += $other->removedPrices;
        $this->queued += $other->queued;

        return $this;
    }
}
