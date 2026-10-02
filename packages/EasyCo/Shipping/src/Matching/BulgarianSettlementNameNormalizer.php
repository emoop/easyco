<?php

namespace EasyCo\Shipping\Matching;

/**
 * The neutral rules plus ONE leading settlement-type prefix stripped, so that
 * "гр. София", "ГР. СОФИЯ", "град София" and "София" are the same name, and so
 * are "с. Драгалевци", "село Драгалевци" and "Драгалевци".
 *
 * Stripped (case-insensitive, after the neutral folding): "гр." and "с." with or
 * without a space after the dot, and "гр ", "град ", "с ", "село " (these four
 * need the following space, so "Градец" and "Сопот" are untouched). Only ONE
 * prefix is removed.
 *
 * DELIBERATELY NOT STRIPPED: "кв." (квартал), "ж.к." (жилищен комплекс) and any
 * other prefix. They name a district inside a town, not a settlement, and
 * stripping them would equate "кв. Лозенец" with the town "Лозенец".
 */
final class BulgarianSettlementNameNormalizer extends NeutralSettlementNameNormalizer
{
    public function normalize(string $name): string
    {
        $neutral = parent::normalize($name);

        // The neutral step has already folded the case and collapsed whitespace.
        return trim((string) preg_replace('/^(?:гр\.|гр |град |с\.|с |село )\s*/u', '', $neutral, 1));
    }
}
