<?php

namespace App\Filament\Concerns;

/**
 * Filament's `form->getState()` hands a repeater / builder back as a LIST with numeric keys (0, 1, 2), but the fields of the form are named by the keys the browser holds in the page's
 * own state (`$this->data`: random ids). A validator that reports `...left.1.settings.height` therefore has to be turned into `data...left.<id of the second item>.data.height`, or the
 * message lands on the wrong field or on none (FT-1 found it on the Footer page, HDR-3 applied it to the Header page). This is the one piece both pages use: the keys of the raw
 * state at a dot path, in order, so that the i-th item of the dehydrated list is the item whose key is the i-th here.
 */
trait ReconcilesFormKeys
{
    /**
     * The keys the browser's state holds at a dot path of `$this->data` (without the `data.` prefix), in order.
     *
     * @param  array<string, mixed>  $raw  the page's own state
     * @return list<int|string>
     */
    protected function rawKeys(array $raw, string $path): array
    {
        $node = $raw;

        foreach ($path === '' ? [] : explode('.', $path) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return [];
            }

            $node = $node[$segment];
        }

        return is_array($node) ? array_keys($node) : [];
    }

    /** The key of the item at a position of a list: the browser's key when it is known, else the position itself. @param  list<int|string>  $keys */
    protected function keyAt(array $keys, int $position): int|string
    {
        return $keys[$position] ?? $position;
    }
}
