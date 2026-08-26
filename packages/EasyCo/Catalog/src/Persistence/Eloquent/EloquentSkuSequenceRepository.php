<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\SkuSequenceRepository;
use Illuminate\Support\Facades\DB;

/**
 * Maps the base_sku sequence onto catalog_sku_sequence — a single-row
 * table, so a dedicated Eloquent model would add nothing over the query
 * builder (same reasoning already applied to catalog_product_attributes/
 * catalog_product_axis_values in EloquentProductRepository — plain
 * DB::table() calls for internal, non-aggregate tables that don't need
 * relationships or attribute casting).
 */
final class EloquentSkuSequenceRepository implements SkuSequenceRepository
{
    /**
     * Atomically increments and reads catalog_sku_sequence.last_value in
     * one DB::transaction() + lockForUpdate() unit — the read and the
     * write happen as a single round trip a second concurrent caller
     * cannot interleave with, never a separate "read the current value,
     * then write current+1" pair (which would let two concurrent
     * requests both read the same starting value and both write the
     * same result). On MySQL/MariaDB, lockForUpdate() takes a real
     * row-level lock; on SQLite it compiles to nothing (SQLite has no
     * row-level locking) but the safety still holds, because SQLite
     * itself serializes concurrent write transactions against the whole
     * database — a second connection's UPDATE inside its own
     * transaction blocks until the first transaction commits, exactly
     * the same "no read-then-write window" guarantee, achieved by a
     * different mechanism per driver. Mirrors the constraint-first,
     * never-read-then-write principle from catalog-domain-design.md §7,
     * applied to sequence generation rather than a unique-constraint
     * collision.
     */
    public function next(): int
    {
        return DB::transaction(function (): int {
            $row = DB::table('catalog_sku_sequence')->lockForUpdate()->first();
            $nextValue = (int) $row->last_value + 1;

            DB::table('catalog_sku_sequence')->update(['last_value' => $nextValue, 'updated_at' => now()]);

            return $nextValue;
        });
    }
}
