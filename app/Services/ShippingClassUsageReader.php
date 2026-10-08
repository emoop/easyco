<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Where a shipping class is used, as two numbers per class — the methods that carry a rate for it and the variations
 * assigned to it. ONE grouped read for each number, whatever the number of classes: the class list and the writer's
 * delete check share this single home. A read only; Catalog's table is read as a plain value (the class code), the
 * packages stay independent.
 */
class ShippingClassUsageReader
{
    /**
     * @param  list<string>|null  $codes  only these codes, or every class when null
     * @return array<string, array{methods: int, variations: int}> class code => counts (a code with no use is present with 0/0)
     */
    public function usage(?array $codes = null): array
    {
        $counts = [];

        foreach ($codes ?? [] as $code) {
            $counts[$code] = ['methods' => 0, 'variations' => 0];
        }

        $rates = DB::table('shipping_method_class_rates')
            ->select('class_code', DB::raw('COUNT(DISTINCT method_id) as total'))
            ->when($codes !== null, fn ($query) => $query->whereIn('class_code', $codes))
            ->groupBy('class_code')
            ->get();

        foreach ($rates as $row) {
            $counts[(string) $row->class_code]['methods'] = (int) $row->total;
            $counts[(string) $row->class_code]['variations'] ??= 0;
        }

        $variations = DB::table('catalog_variations')
            ->select('shipping_class', DB::raw('COUNT(*) as total'))
            ->whereNotNull('shipping_class')
            ->when($codes !== null, fn ($query) => $query->whereIn('shipping_class', $codes))
            ->groupBy('shipping_class')
            ->get();

        foreach ($variations as $row) {
            // the column is case-insensitive text and a class code is lower case: group keys are compared lower-cased
            $code = mb_strtolower((string) $row->shipping_class);

            if ($codes === null || array_key_exists($code, $counts)) {
                $counts[$code]['variations'] = (int) $row->total;
                $counts[$code]['methods'] ??= 0;
            }
        }

        return $counts;
    }
}
