<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which variations have NO usable shipping class (shipping stage 5e) — the one definition shared by the overview's
 * health line and the assign-missing command. A variation is "missing" when its stored class text is
 *
 *  - NULL,
 *  - blank (empty or only spaces), or
 *  - a code that names NO existing class (legacy free text, or a class since deleted) — compared EXACTLY, the way the
 *    rate calculator compares (a rate keys on the exact code; "Heavy" is not the class "heavy").
 *
 * Archived variations are included: they keep their history, only the class text is filled. A read only; it never
 * changes the catalog, and counts everything in ONE query.
 */
class ShippingClassMissingReader
{
    /** @return array{null: int, blank: int, unknown: int, total: int} */
    public function counts(): array
    {
        $row = DB::table('catalog_variations as v')
            ->selectRaw(
                'COALESCE(SUM(v.shipping_class IS NULL), 0) as n_null, '
                .'COALESCE(SUM(v.shipping_class IS NOT NULL AND TRIM(v.shipping_class) = \'\'), 0) as n_blank, '
                .'COALESCE(SUM(v.shipping_class IS NOT NULL AND TRIM(v.shipping_class) <> \'\' AND NOT EXISTS (SELECT 1 FROM shipping_classes c WHERE '.$this->exactMatch().')), 0) as n_unknown'
            )
            ->first();

        $null = (int) $row->n_null;
        $blank = (int) $row->n_blank;
        $unknown = (int) $row->n_unknown;

        return ['null' => $null, 'blank' => $blank, 'unknown' => $unknown, 'total' => $null + $blank + $unknown];
    }

    /** The total only — what the overview's health line needs. */
    public function total(): int
    {
        return $this->counts()['total'];
    }

    /** The missing variations, as a query over `catalog_variations as v` (ordered by id by the caller). */
    public function query(): Builder
    {
        return DB::table('catalog_variations as v')->whereRaw(
            '(v.shipping_class IS NULL OR TRIM(v.shipping_class) = \'\' OR NOT EXISTS (SELECT 1 FROM shipping_classes c WHERE '.$this->exactMatch().'))'
        );
    }

    /** Whether ONE variation row (already read) is still missing — used under a row lock to skip what another process fixed. */
    public function isMissing(?string $storedClass, array $existingCodes): bool
    {
        return $storedClass === null || trim($storedClass) === '' || ! in_array($storedClass, $existingCodes, true);
    }

    /** Exact (case-sensitive) code equality: BINARY on MySQL/MariaDB, plain `=` on SQLite (which is exact already). */
    private function exactMatch(): string
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? 'BINARY c.code = BINARY v.shipping_class'
            : 'c.code = v.shipping_class';
    }
}
