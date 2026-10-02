<?php

namespace EasyCo\Shipping\Matching;

use EasyCo\Shipping\Contracts\SettlementNameNormalizer;
use LogicException;
use Normalizer;

/**
 * The locale-independent normalization every locale starts from, and the one a
 * store gets when its locale has no rules of its own (owner decision D3: an
 * unknown locale is never a refusal).
 *
 * In this order:
 *  1. Unicode NFC, so a decomposed "й" (и + U+0306) equals the composed one;
 *  2. case folding (MB_CASE_FOLD), not just lower-casing;
 *  3. every run of whitespace — ASCII, NBSP and every other Unicode separator —
 *     becomes ONE space, and the ends are trimmed;
 *  4. the dash variants U+2010 ‐, U+2011 ‑, U+2013 – and U+2014 — become "-".
 *
 * Deliberately NOT done: no prefix stripping and no transliteration ("Sofia"
 * and "София" are different names; a merchant lists each spelling he wants).
 *
 * Invalid UTF-8 normalizes to the empty string, which matches nothing.
 */
class NeutralSettlementNameNormalizer implements SettlementNameNormalizer
{
    public function normalize(string $name): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new LogicException('SettlementNameNormalizer needs the PHP intl extension (Normalizer).');
        }

        $composed = Normalizer::normalize($name, Normalizer::FORM_C);

        if ($composed === false || $composed === null) {
            return '';
        }

        $folded = mb_convert_case($composed, MB_CASE_FOLD, 'UTF-8');
        $spaced = preg_replace('/[\s\p{Z}]+/u', ' ', $folded);

        if ($spaced === null) {
            return '';
        }

        return trim(str_replace(["\u{2010}", "\u{2011}", "\u{2013}", "\u{2014}"], '-', $spaced));
    }
}
