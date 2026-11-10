<?php

namespace App\Services\MassEdit;

/**
 * P6d: WHAT a mass edit acts on, and HOW the rows were chosen: picked by hand, or «all matching» of a table where a filter or a search is active. The SERVER decides both facts (from the Livewire component's own
 * state, never from a client flag) and the service re-checks them: an unfiltered «select all» is refused, and so is a set above ProductMassEditor::MAX_PRODUCTS.
 */
final class MassEditSelection
{
    /** @var list<string> */
    public readonly array $ids;

    /** @param list<int|string> $ids */
    public function __construct(array $ids, public readonly bool $handPicked, public readonly bool $filtered)
    {
        $this->ids = array_values(array_unique(array_map('strval', $ids)));
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /** @throws MassEditRefused */
    public function assertAllowed(): void
    {
        if ($this->ids === []) {
            throw new MassEditRefused('none');
        }

        if (! $this->handPicked && ! $this->filtered) {
            throw new MassEditRefused('unfiltered');
        }

        if ($this->count() > ProductMassEditor::MAX_PRODUCTS) {
            throw new MassEditRefused('too_many');
        }
    }
}
