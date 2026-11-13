<?php

namespace App\Services;

use EasyCo\Catalog\Variation;
use InvalidArgumentException;

/**
 * MEAS-1: the ONE place the admin writes a variation's weight (whole grams, NULL = unknown). It goes through the EXISTING
 * Variation::setDimensions() keeping the three dimensions as they are (Variation.php is frozen and is not edited), and logs the
 * change like every other variation field (old -> new). The form already validates 1...100 000 whole numbers; the range and the
 * integer rule are enforced here again, so a tampered submission never reaches storage.
 */
final class VariationWeightWriter
{
    public const MIN = 1;

    public const MAX = 100000;

    /**
     * '' / null / whitespace = unknown (null); a whole number string or int in range = grams; anything else is refused.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $raw): ?int
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $text = is_int($raw) ? (string) $raw : (is_string($raw) ? trim($raw) : '');

        if (preg_match('/^[0-9]{1,6}$/', $text) !== 1 || (int) $text < self::MIN || (int) $text > self::MAX) {
            throw new InvalidArgumentException('A weight must be a whole number of grams from '.self::MIN.' to '.self::MAX.', or empty.');
        }

        return (int) $text;
    }

    /**
     * Sets the weight when it differs, logging old -> new. $onlyIfEmpty writes ONLY into a variation that has no weight yet.
     *
     * @return bool true when the variation was changed
     */
    public function apply(Variation $variation, mixed $raw, ActivityLogger $logger, string $productId, string $fieldName, bool $onlyIfEmpty = false): bool
    {
        $new = self::normalize($raw);
        $old = $variation->weightGrams();

        if ($new === $old || ($onlyIfEmpty && $old !== null)) {
            return false;
        }

        $logger->logFieldChanged('product', $productId, $fieldName, $old === null ? null : (string) $old, $new === null ? null : (string) $new);
        $variation->setDimensions($new, $variation->lengthMm(), $variation->widthMm(), $variation->heightMm());

        return true;
    }
}
