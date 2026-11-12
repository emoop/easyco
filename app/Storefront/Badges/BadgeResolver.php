<?php

namespace App\Storefront\Badges;

use App\Settings\StoreTimezone;
use App\Storefront\ReadModels\Badge;
use App\Storefront\ReadModels\PriceBlock;

/**
 * P7-1: the card's badges from the compiled set and the facts the card ALREADY has (badges-research.md §B): the price block the card prints, the total stock of its shown variations (the map `cards()` loaded) and the
 * store-local day the product was created (a column of the SAME products read). PURE: no query, no clock (the day of «today» is passed in), no markup. Only the built-ins exist here; P7-2 adds the kinds that need more.
 *
 *  - «Промо»        the price block is discounted (`regularFromMinor` set and above the final price): the word, or the percent `round((regular − final) / regular × 100)` half-up from the SAME block (none when < 1).
 *  - «Нови»         the product is at most `days` whole store-local calendar days old.
 *  - «Последни бройки»  the total stock of the shown variations is between 1 and N (0 = sold out: the «Изчерпан» label owns that state).
 * At most MAX_PER_POSITION badges per position, ordered by `priority` (lower first), then by kind order (built-ins before custom); the rest are dropped.
 */
final class BadgeResolver
{
    /**
     * @param  array<string, array<string, mixed>>  $set  the ENABLED rows by kind (BadgeSet::fromJson)
     * @param  string|null  $createdDay  the product's creation day, store-local `Y-m-d` (null = unknown: «Нови» is not shown)
     * @return list<Badge>
     */
    public static function resolve(array $set, ?PriceBlock $price, int $stockTotal, ?string $createdDay, string $today): array
    {
        $found = [];

        foreach (BadgeSet::KINDS as $order => $kind) {
            $row = $set[$kind] ?? null;

            if ($row === null || ! self::applies($kind, $row, $price, $stockTotal, $createdDay, $today)) {
                continue;
            }

            $found[] = [$order, new Badge($kind, self::label($kind, $row, $price), (string) $row['position'], (string) $row['colour'], (int) $row['priority'], (string) $row['shape'])];
        }

        usort($found, static fn (array $a, array $b): int => [$a[1]->priority, $a[0]] <=> [$b[1]->priority, $b[0]]);

        $count = ['top' => 0, 'bottom' => 0];
        $out = [];

        foreach ($found as [, $badge]) {
            if ($count[$badge->placement]++ < BadgeSet::MAX_PER_POSITION) {
                $out[] = $badge;
            }
        }

        return $out;
    }

    /** The percent of a discounted block as a whole number (half-up, integer maths only); 0 when the block is not discounted. */
    public static function percent(?PriceBlock $price): int
    {
        if ($price === null || $price->regularFromMinor === null || $price->regularFromMinor <= 0 || $price->regularFromMinor <= $price->fromMinor) {
            return 0;
        }

        $regular = $price->regularFromMinor;

        return intdiv(200 * ($regular - $price->fromMinor) + $regular, 2 * $regular);
    }

    /** @param  array<string, mixed>  $row */
    private static function applies(string $kind, array $row, ?PriceBlock $price, int $stockTotal, ?string $createdDay, string $today): bool
    {
        return match ($kind) {
            BadgeSet::SALE => $price !== null && $price->regularFromMinor !== null && $price->regularFromMinor > $price->fromMinor && ($row['mode'] !== 'percent' || self::percent($price) >= 1),
            BadgeSet::NEW => $createdDay !== null && StoreTimezone::daysBetween($createdDay, $today) <= (int) $row['days'],
            BadgeSet::LOW_STOCK => $stockTotal >= 1 && $stockTotal <= (int) $row['max'],
            default => false,
        };
    }

    /** @param  array<string, mixed>  $row */
    private static function label(string $kind, array $row, ?PriceBlock $price): string
    {
        if ($kind === BadgeSet::SALE && $row['mode'] === 'percent') {
            return '−'.self::percent($price).'%';
        }

        return $row['text'] !== '' ? (string) $row['text'] : (string) __('storefront.badges.'.$kind);
    }
}
