<?php

namespace App\Providers;

use EasyCo\Extensibility\Hook;
use Illuminate\Support\ServiceProvider;

/**
 * DEMO / PROOF-OF-CONCEPT ONLY — not a real feature.
 *
 * Registers a single listener on the 'catalog.product.base_sku' filter
 * (fired from ProductController::store()) purely to prove the
 * EasyCo\Extensibility hook mechanism actually fires through a real HTTP
 * request, end-to-end. This is explicitly NOT the real, configurable
 * SKU-generator feature — that remains deferred (see
 * catalog-domain-design.md §6's "A real SKU-generation strategy..." entry).
 * A real implementation would need persistent sequence storage (a DB
 * table, not a PHP static that resets on every process restart and is
 * shared per-worker, not per-request, under PHP-FPM), configurable
 * generation strategies, and a race-condition-safe increment (this
 * demo's `self::$nextGeneratedSku++` is none of those things — two
 * concurrent requests hitting this listener in the same worker are not
 * even guaranteed a unique result under async/fiber execution, let alone
 * across multiple worker processes).
 *
 * The demo rule: if the incoming base_sku is literally "1", replace it
 * with a generated placeholder; every other value passes through
 * unchanged.
 */
class DemoHooksServiceProvider extends ServiceProvider
{
    /**
     * DEMO ONLY — see class docblock. In-memory, per-process, not
     * persisted, not concurrency-safe. Starts at 100000 and increments by
     * one on every generated value.
     */
    private static int $nextGeneratedSku = 100000;

    public function boot(): void
    {
        Hook::filter('catalog.product.base_sku', function (string $baseSku): string {
            if ($baseSku !== '1') {
                return $baseSku;
            }

            return (string) self::$nextGeneratedSku++;
        });
    }

    /**
     * Resets the demo counter back to its starting value (100000). Exists
     * purely for test determinism — tests call this in setUp() so each
     * test starts from a known state. A real SKU generator, backed by
     * persistent storage, would never need or want an operation like this.
     */
    public static function resetDemoCounter(): void
    {
        self::$nextGeneratedSku = 100000;
    }
}
