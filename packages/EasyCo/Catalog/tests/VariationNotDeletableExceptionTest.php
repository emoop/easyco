<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use EasyCo\Catalog\Exceptions\VariationNotDeletableException;
use PHPUnit\Framework\TestCase;

/**
 * Covers the refusal's own contract: it carries a REASON plus the values the
 * UI needs, and it keeps an English sentence for logs — see
 * catalog-domain-design.md §3.19.8 D.
 *
 * The localised rendering is the application's job
 * (App\Services\VariationDeletionRefusalMessage, covered by the admin test
 * that asserts the Bulgarian modal). This file pins the other half: that the
 * domain states the fact in a locale-independent way, and that its own
 * wording never becomes the merchant-facing string.
 */
final class VariationNotDeletableExceptionTest extends TestCase
{
    public function test_it_carries_the_history_reason_with_the_sku_and_the_count(): void
    {
        $exception = VariationNotDeletableException::becauseItHasHistory('SKU-BLACK', 3);

        $this->assertSame(VariationDeletionRefusal::HAS_HISTORY, $exception->reason);
        $this->assertSame('SKU-BLACK', $exception->variationSku);
        $this->assertSame(3, $exception->count);

        // English stays English: this sentence is what logs, exception dumps
        // and support tickets carry, whatever locale the request used.
        $this->assertSame(
            'Variation "SKU-BLACK" has 3 sale line(s) and cannot be deleted. Archive it instead.',
            $exception->getMessage()
        );
    }

    public function test_it_carries_the_stock_reason_with_the_sku_and_the_quantity(): void
    {
        $exception = VariationNotDeletableException::becauseStockIsNotZero('SKU-BLACK', 4);

        $this->assertSame(VariationDeletionRefusal::HAS_STOCK, $exception->reason);
        $this->assertSame('SKU-BLACK', $exception->variationSku);
        $this->assertSame(4, $exception->count);

        $this->assertSame(
            'Variation "SKU-BLACK" still has 4 in stock. Set stock to 0, or archive it instead.',
            $exception->getMessage()
        );
    }

    /**
     * The reason VALUES are the fragments the application builds
     * `products.deletion.refusal.{value}` from, so renaming a case without
     * renaming the lang key is caught here rather than by a merchant seeing a
     * raw key. Two cases, and only two — the enum is also the list of
     * renderable refusals.
     */
    public function test_the_reason_values_are_the_key_fragments_the_ui_builds_from(): void
    {
        $this->assertSame('has_history', VariationDeletionRefusal::HAS_HISTORY->value);
        $this->assertSame('has_stock', VariationDeletionRefusal::HAS_STOCK->value);
        $this->assertCount(2, VariationDeletionRefusal::cases());
    }
}
