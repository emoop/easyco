<?php

namespace App\Services;

/**
 * MEAS-1: the ONE all-or-nothing parcel-weight rule, shared by the shipping quote (the live cart) and by order placement
 * (the sold lines): the total weight in grams is the sum of weight x quantity ONLY when EVERY line has a known weight; one
 * unknown line makes the whole weight unknown (NULL, never 0 - an unweighed garment must not look weightless).
 */
final class ParcelWeight
{
    /**
     * @param iterable<array{0: ?int, 1: int}> $lines [unit weight in grams or null, quantity]
     */
    public static function sum(iterable $lines): ?int
    {
        $total = 0;

        foreach ($lines as [$unitGrams, $quantity]) {
            if ($unitGrams === null) {
                return null;
            }

            $total += $unitGrams * $quantity;
        }

        return $total;
    }
}
