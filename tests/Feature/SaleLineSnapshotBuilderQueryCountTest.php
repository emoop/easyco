<?php

namespace Tests\Feature;

use App\Services\SaleLineSnapshotBuilder;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\Pricing\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D5 (operational-sales-domain-design.md §3.13 stage 4a) — the sold-
 * attributes read for a whole cart must be ONE bounded batch, never one
 * round trip per line. Proves SaleLineSnapshotBuilder::buildForCart()'s
 * own query count does not grow between a 2-line and a 10-line cart,
 * mirroring EloquentVariationRepositoryFindByProductIdQueryCountTest's
 * own countQueries() pattern.
 */
class SaleLineSnapshotBuilderQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $callback();

        DB::flushQueryLog();

        return $count;
    }

    private function simpleVariationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    /**
     * @param string[] $variationIds
     */
    private function buildForVariations(array $variationIds): void
    {
        $lines = array_map(static fn (string $variationId): array => [
            'variationId' => $variationId,
            'quantity' => 1,
            'regularUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
            'finalUnitPrice' => Money::fromMinorUnits(1000, 'EUR'),
            'unitCost' => null,
            'productName' => 'Product',
            'sku' => 'SKU',
            'promotionDiscountShare' => Money::zero('EUR'),
            'discretionaryDiscount' => Money::zero('EUR'),
        ], $variationIds);

        app(SaleLineSnapshotBuilder::class)->buildForCart(
            lines: $lines,
            transactionId: '',
            clientId: 'client-1',
            status: SaleLineStatus::COMPLETED,
            recordedAt: new DateTimeImmutable(),
            effectiveAt: new DateTimeImmutable(),
        );
    }

    public function test_query_count_does_not_grow_between_a_two_line_and_a_ten_line_cart(): void
    {
        $twoVariationIds = [$this->simpleVariationId(), $this->simpleVariationId()];
        $queriesForTwo = $this->countQueries(fn () => $this->buildForVariations($twoVariationIds));

        $tenVariationIds = array_map(fn () => $this->simpleVariationId(), range(1, 10));
        $queriesForTen = $this->countQueries(fn () => $this->buildForVariations($tenVariationIds));

        fwrite(STDERR, "\n[query-count] SaleLineSnapshotBuilder::buildForCart(): 2 lines: {$queriesForTwo} queries, 10 lines: {$queriesForTen} queries\n");

        $this->assertSame($queriesForTwo, $queriesForTen, 'query count must not grow with the number of cart lines');
    }
}
