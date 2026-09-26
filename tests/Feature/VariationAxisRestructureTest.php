<?php

namespace Tests\Feature;

use App\Services\ActivityLogger;
use App\Services\AxesRestructureImpact;
use App\Services\AxesRestructureResult;
use App\Services\VariationAxisRestructure;
use DateTimeImmutable;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\AxesRestructureRefusal;
use EasyCo\Catalog\Enums\VariationDeletionRefusal;
use EasyCo\Catalog\Enums\VariationStatus;
use EasyCo\Catalog\Exceptions\AxesRestructureRefused;
use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
use EasyCo\Catalog\Exceptions\VariationNotDeletableException;
use EasyCo\Catalog\Exceptions\VariationNotRestorableException;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\Variation;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\Hook;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\Persistence\Eloquent\SaleLineModel;
use EasyCo\OperationalSales\Persistence\Eloquent\TransactionModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Persistence\Eloquent\ProductCostModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * App\Services\VariationAxisRestructure — catalog-domain-design.md §3.19.8 C,
 * on top of §3.17's directional guard and §3.9's revival-by-signature.
 *
 * THE PLAN'S OWN PROPERTY IS ASSERTED, NOT JUST ITS EFFECTS: every list comes
 * from one predicate ("does this variation's own combination still fit the new
 * axes?"), and the guard is what decides whether the set may be declared at
 * all — so these tests pin both halves: which variation ends up where, and
 * that `apply()` leaves the product exactly as it was when any part of it
 * refuses.
 *
 * Fixture shapes mirror EditVariableProductAxesAndRestoreTest's and
 * CatalogDeletionTest's own established constructions (same repositories, same
 * domain objects) rather than inventing new ones.
 */
class VariationAxisRestructureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The rows the concurrency test commits on its SECOND connection —
     * committed outside this test's own transaction, so nothing else can clean
     * them up. See tearDown().
     */
    private ?string $raceClientId = null;

    private ?string $raceTransactionId = null;

    protected function tearDown(): void
    {
        // parent::tearDown() FIRST — see CatalogDeletionTest::tearDown()'s own
        // comment: it rolls back this test's wrapping transaction, and only
        // then are its locks released. Deleting the committed rows while it is
        // still open blocks on those locks.
        parent::tearDown();

        if ($this->raceClientId === null) {
            return;
        }

        DB::connection('deletion_race')->table('operational_sales_sale_lines')
            ->where('client_id', $this->raceClientId)
            ->delete();

        DB::connection('deletion_race')->table('operational_sales_transactions')
            ->where('id', $this->raceTransactionId)
            ->delete();

        DB::connection('deletion_race')->table('operational_sales_clients')
            ->where('id', $this->raceClientId)
            ->delete();

        $this->raceClientId = null;
        $this->raceTransactionId = null;
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue, 3: AttributeValue} Color: Black, White, Red */
    private function persistedColorDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $red = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Red');
        app(AttributeValueRepository::class)->save($red);

        return [$definition, $black, $white, $red];
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue} Size: S */
    private function persistedSizeDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $small = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'S');
        app(AttributeValueRepository::class)->save($small);

        return [$definition, $small];
    }

    /** A declared axis from a definition and the values to enable on it. */
    private function axis(AttributeDefinition $definition, AttributeValue ...$values): VariationAxis
    {
        return new VariationAxis($definition, array_values($values));
    }

    /**
     * A VARIABLE product with the given axes and explicitly-SKUd variations —
     * the SKUs are the test's own so a deliberate collision with an ARCHIVED
     * variation's SKU can be constructed (§0's own question).
     *
     * @param VariationAxis[] $axes
     * @param list<array{combination: array<string, string>, sku: string, status?: VariationStatus}> $variations
     */
    private function variableProduct(string $baseSku, string $slug, array $axes, array $variations): Product
    {
        $product = Product::createVariable('Variable Shirt', $baseSku, $slug);
        $product->declareVariationAxes($axes);

        foreach ($variations as $spec) {
            $variation = $product->addStandardVariation($spec['combination'], $spec['sku']);

            if (($spec['status'] ?? VariationStatus::DRAFT) === VariationStatus::ARCHIVED) {
                $variation->archive();
            }
        }

        app(ProductRepository::class)->save($product);

        return $product;
    }

    private function addStockLevel(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    private function addCostRow(string $variationId): void
    {
        ProductCostModel::create([
            'priceable_id' => $variationId,
            'cost_amount_minor' => 1200,
            'cost_currency' => 'EUR',
        ]);
    }

    private function addSaleLine(string $variationId): void
    {
        $clientId = (string) ClientModel::create(['name' => 'Test Client'])->id;

        $transaction = new Transaction(id: null, channel: Channel::POS);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(2500, 'EUR'),
            profit: Money::fromMinorUnits(400, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-08-25 10:00:00'),
            effectiveAt: new DateTimeImmutable('2026-08-20 09:00:00'),
            productName: 'Variable Shirt',
            sku: 'SKU-AX-1',
            regularUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(2500, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);
    }

    /** The product's PERSISTED axes as definition id => sorted value ids — real storage, not the in-memory aggregate. */
    private function persistedAxesOf(string $productId): array
    {
        $axes = [];

        foreach (DB::table('catalog_product_axis_values')
            ->where('product_id', $productId)
            ->get(['attribute_definition_id', 'attribute_value_id']) as $row) {
            $axes[(string) $row->attribute_definition_id][] = (string) $row->attribute_value_id;
        }

        foreach ($axes as $definitionId => $valueIds) {
            sort($valueIds, SORT_STRING);
            $axes[$definitionId] = $valueIds;
        }

        ksort($axes);

        return $axes;
    }

    /** Every variation row of the product (soft-deleted included) as sku => status. */
    private function variationStatusBySku(string $productId): array
    {
        $statuses = [];

        foreach (VariationModel::withTrashed()->where('product_id', $productId)
            ->orderBy('id')
            ->get(['sku', 'status', 'deleted_at']) as $row) {
            $statuses[(string) $row->sku] = $row->deleted_at !== null ? 'soft-deleted' : (string) $row->status;
        }

        return $statuses;
    }

    private function variationIdBySku(string $productId, string $sku): string
    {
        return (string) VariationModel::withTrashed()->where('product_id', $productId)->where('sku', $sku)->value('id');
    }

    /** @param VariationAxis[] $newAxes */
    private function impactFor(Product $product, array $newAxes, bool $mayDelete = true): AxesRestructureImpact
    {
        return app(VariationAxisRestructure::class)->impact((string) $product->id(), $newAxes, $mayDelete);
    }

    /**
     * Applies the change the way a real surface does: the fingerprint of the plan
     * computed HERE is what the caller would have shown the merchant, so an
     * immediately-following apply() has nothing to refuse. Tests that change the
     * product between the impact and the apply call the service directly instead
     * (see the staleness tests below), because that is exactly the difference
     * they assert.
     *
     * @param VariationAxis[] $newAxes
     */
    private function applyFor(Product $product, array $newAxes, bool $mayDelete = true): AxesRestructureResult
    {
        return app(VariationAxisRestructure::class)->apply(
            (string) $product->id(),
            $newAxes,
            $mayDelete,
            $this->impactFor($product, $newAxes, $mayDelete)->fingerprint,
        );
    }

    /**
     * §3.19.8 C's own case, and the deliverable this stage exists for: an axis
     * is REMOVED while live variations exist (R2 — a plain save refuses it), so
     * the restructure first gets them out of the way: the one with no history
     * and zero stock is DELETED (through CatalogDeletion, so its row and its
     * SKU are genuinely freed), the one with history is ARCHIVED, the new axes
     * are declared, and the new combinations are generated as DRAFT variations.
     *
     * THIS TEST IS ALSO THE STALE-AGGREGATE REGRESSION: a re-declaration is only
     * legal once the live variations are out of the way, so if apply() re-used
     * the pre-deletion aggregate for declareVariationAxes() — whose variations
     * array still holds the deleted rows as LIVE objects — R2 would refuse and
     * nothing would be applied at all. The success asserted below is reachable
     * only through the re-load.
     */
    public function test_removing_an_axis_deletes_the_clean_variation_archives_the_one_with_history_and_generates_the_new_combinations(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
            ['combination' => [$color->id() => $white->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-5'],
        ]);

        $productId = (string) $product->id();

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $newAxes = [$this->axis($color, $black, $white)];

        // The normal save cannot do this — the reason the action exists.
        $this->assertAxisRemovalIsRefusedByAPlainSave($productId, $newAxes);

        $impact = $this->impactFor($product, $newAxes);

        $this->assertTrue($impact->canApply());
        $this->assertTrue($impact->axesDiffer);
        $this->assertSame('Color: Black, White; Size: S', $impact->currentAxesSummary);
        $this->assertSame('Color: Black, White', $impact->newAxesSummary);
        // The report also carries the axes THEMSELVES, not only their rendered
        // summaries (§3.19.8 C's own "current axes vs new axes").
        $this->assertCount(2, $impact->currentAxes);
        $this->assertSame(
            [(string) $color->id()],
            array_map(static fn (VariationAxis $axis): string => $axis->attributeDefinitionId(), $impact->newAxes),
        );
        $this->assertSame(
            ['SKU-AX-1'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBeDeleted),
        );
        $this->assertSame(
            ['SKU-AX-5'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBeArchived),
        );
        $this->assertSame([], $impact->willBecomeUnrestorable);
        $this->assertSame(1, $impact->willBeArchived[0]->saleLineCount);

        $result = $this->applyFor($product, $newAxes);

        $this->assertTrue($result->changed);
        $this->assertSame(1, $result->deletedVariationCount);
        $this->assertSame(1, $result->archivedVariationCount);
        $this->assertSame(2, $result->createdVariationCount);
        $this->assertSame(0, $result->restoredVariationCount);
        $this->assertSame([
            (string) $color->id() => [(string) $black->id(), (string) $white->id()],
        ], $this->persistedAxesOf($productId));

        // The deleted row (and its SKU's unique entry) is genuinely gone, the
        // one with history is archived, and both new combinations exist as DRAFT
        // variations. The generated numbers are 2 and 3 — the deleted
        // variation's position is reclaimed (§3.19.11's own mechanics).
        $this->assertSame(0, VariationModel::withTrashed()->where('sku', 'SKU-AX-1')->count());
        $this->assertSame([
            'SKU-AX-5' => 'archived',
            'SKU-AX-2' => 'draft',
            'SKU-AX-3' => 'draft',
        ], $this->variationStatusBySku($productId));

        // The axes are really persisted, not just declared in memory.
        $this->assertSame([
            (string) $color->id() => [(string) $black->id(), (string) $white->id()],
        ], $this->persistedAxesOf($productId));
    }

    /**
     * @param VariationAxis[] $newAxes
     */
    private function assertAxisRemovalIsRefusedByAPlainSave(string $productId, array $newAxes): void
    {
        $aggregate = app(ProductRepository::class)->findByIdWithVariations($productId);
        $thrown = null;

        try {
            $aggregate->declareVariationAxes($newAxes);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(UnsafeAxisRedeclarationException::class, $thrown);
    }

    public function test_without_product_delete_every_blocker_is_archived_instead_and_nothing_is_deleted(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
            ['combination' => [$color->id() => $white->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-5'],
        ]);

        $productId = (string) $product->id();

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $newAxes = [$this->axis($color, $black, $white)];

        $impact = $this->impactFor($product, $newAxes, mayDelete: false);

        $this->assertTrue($impact->canApply());
        $this->assertFalse($impact->mayDelete);
        $this->assertFalse($impact->deletesVariations());
        $this->assertSame([], $impact->willBeDeleted);
        $this->assertSame(
            ['SKU-AX-1', 'SKU-AX-5'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBeArchived),
        );

        $result = $this->applyFor($product, $newAxes, mayDelete: false);

        $this->assertSame(0, $result->deletedVariationCount);
        $this->assertSame(2, $result->archivedVariationCount);
        $this->assertSame(2, $result->createdVariationCount);

        // Both rows are still there, archived — so both SKUs stay occupied,
        // which is the fact the modal has to state (§3.19.8 C's own wording).
        $this->assertSame([
            'SKU-AX-1' => 'archived',
            'SKU-AX-5' => 'archived',
            'SKU-AX-3' => 'draft',
            'SKU-AX-4' => 'draft',
        ], $this->variationStatusBySku($productId));

        $this->assertSame(1, VariationModel::withTrashed()->where('sku', 'SKU-AX-1')->count());
    }

    /**
     * §3.17's trade-off, reported before it happens: removing a VALUE that only
     * an ARCHIVED variation uses blocks nothing (R5 — no LIVE variation depends
     * on it), so the change goes through, and the archived variation becomes
     * unrestorable — which is exactly what restoreArchivedVariation() then
     * refuses with.
     */
    public function test_removing_a_value_used_by_an_archived_variation_reports_it_unrestorable_and_restore_then_refuses(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
        ], [
            ['combination' => [$color->id() => $white->id()], 'sku' => 'SKU-AX-1'],
            ['combination' => [$color->id() => $black->id()], 'sku' => 'SKU-AX-2', 'status' => VariationStatus::ARCHIVED],
        ]);

        $productId = (string) $product->id();

        $newAxes = [$this->axis($color, $white)];

        $impact = $this->impactFor($product, $newAxes);

        $this->assertTrue($impact->canApply());
        $this->assertSame([], $impact->willBeDeleted);
        $this->assertSame([], $impact->willBeArchived);
        $this->assertSame(
            ['SKU-AX-2'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBecomeUnrestorable),
        );

        $result = $this->applyFor($product, $newAxes);

        $this->assertSame(0, $result->deletedVariationCount);
        $this->assertSame(0, $result->archivedVariationCount);
        $this->assertSame(0, $result->createdVariationCount);
        $this->assertSame([
            (string) $color->id() => [(string) $white->id()],
        ], $this->persistedAxesOf($productId));

        // The live White variation already exists, so generation skips it; the
        // archived Black one stays archived — and can no longer come back.
        $this->assertSame([
            'SKU-AX-1' => 'draft',
            'SKU-AX-2' => 'archived',
        ], $this->variationStatusBySku($productId));

        $thrown = null;

        try {
            $this->domainVariationBySku($productId, 'SKU-AX-2');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, 'The archived variation must still exist — it is archived, not deleted.');

        $aggregate = app(ProductRepository::class)->findByIdWithVariations($productId);
        $archivedVariation = null;

        foreach ($aggregate->variations() as $variation) {
            if ($variation->sku() === 'SKU-AX-2') {
                $archivedVariation = $variation;
            }
        }

        $this->assertNotNull($archivedVariation);

        $refused = null;

        try {
            // The SAME aggregate instance the variation came from — restoreArchivedVariation()
            // compares by identity, exactly as Product::removeStandardVariation() does.
            $aggregate->restoreArchivedVariation($archivedVariation);
        } catch (\Throwable $e) {
            $refused = $e;
        }

        $this->assertInstanceOf(VariationNotRestorableException::class, $refused);
    }

    /** The aggregate's own domain object for a persisted sku. */
    private function domainVariationBySku(string $productId, string $sku): Variation
    {
        $product = app(ProductRepository::class)->findByIdWithVariations($productId);

        foreach ($product->variations() as $variation) {
            if ($variation->sku() === $sku) {
                return $variation;
            }
        }

        $this->fail("No variation with sku \"{$sku}\" in product \"{$productId}\".");
    }

    /**
     * §0's own question, answered by test rather than by reading: the
     * `catalog.variation.sku` hook's candidate is `{baseSku}-{count+1}` and it
     * does NOT search for a free number — it cannot, and it does not have to. A
     * candidate therefore collides whenever an ARCHIVED variation (a real row
     * that keeps its own SKU) happens to hold that number, which this fixture
     * forces deliberately: the deleted variation's reclaimed position IS the
     * archived one's number.
     *
     * THE COLLISION IS RESOLVED, NOT FATAL: the repository's own
     * DB-constraint-driven retry saves the same candidate with a numeric suffix
     * (`-1`, `-2`, …) and writes the saved value BACK onto the aggregate, so the
     * new variation gets a different, genuinely free SKU while the archived row
     * keeps its own. That is the documented "best-effort candidate + the
     * authoritative retry" contract (§3.19.11), so no fix is needed — this test
     * is what proves the outcome instead of assuming it.
     */
    public function test_a_generated_sku_that_collides_with_an_archived_variations_sku_is_saved_under_a_different_sku(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
            ['combination' => [$color->id() => $white->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-2', 'status' => VariationStatus::ARCHIVED],
        ]);

        $productId = (string) $product->id();

        // One axis removed: the live variation goes, the archived one stays
        // archived and keeps SKU-AX-2 — so the first generated candidate
        // ("SKU-AX-2", count 1 + 1) collides with it.
        $newAxes = [$this->axis($color, $black, $white)];

        $impact = $this->impactFor($product, $newAxes);

        $this->assertSame(
            ['SKU-AX-1'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBeDeleted),
        );
        $this->assertSame(
            ['SKU-AX-2'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBecomeUnrestorable),
        );

        $result = $this->applyFor($product, $newAxes);

        $this->assertSame(1, $result->deletedVariationCount);
        $this->assertSame(0, $result->archivedVariationCount);
        $this->assertSame(2, $result->createdVariationCount);

        $statuses = $this->variationStatusBySku($productId);

        // The archived variation kept its own SKU, untouched...
        $this->assertSame('archived', $statuses['SKU-AX-2']);
        // ...and the new variation was saved under a different, free SKU (the
        // retry's own suffix), never under the colliding candidate.
        $this->assertSame('draft', $statuses['SKU-AX-2-1']);
        $this->assertSame('draft', $statuses['SKU-AX-3']);
        $this->assertSame(1, VariationModel::withTrashed()->where('sku', 'SKU-AX-2')->count());
    }

    /**
     * Atomicity, with the failure injected where the design's own stage plan
     * puts it — DURING GENERATION, after the deletions and the archiving have
     * already run inside the transaction. The product must be exactly as it was:
     * the same axes, the same variation rows and statuses, and the deleted
     * variation's stock and price rows still there.
     *
     * The seam is the real one: the generation path calls the
     * `catalog.variation.sku` filter, so a listener that throws for this
     * product's base SKU is a genuine mid-flight failure, not a mocked shortcut.
     * (The Hook registry is bound per application instance, so the listener
     * exists only inside this test.)
     */
    public function test_a_failure_during_generation_rolls_the_whole_restructure_back(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
            ['combination' => [$color->id() => $white->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-2'],
        ]);

        $productId = (string) $product->id();
        $deletableId = $this->variationIdBySku($productId, 'SKU-AX-1');

        // Quantity 0 keeps it deletable; both rows are what must survive the
        // rollback untouched.
        $this->addStockLevel($deletableId, 0);
        $this->addCostRow($deletableId);

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-2'));

        $newAxes = [$this->axis($color, $black, $white)];

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeStatuses = $this->variationStatusBySku($productId);

        Hook::filter('catalog.variation.sku', function (string $value, string $baseSku): string {
            if ($baseSku === 'SKU-AX') {
                throw new RuntimeException('Injected failure during generation.');
            }

            return $value;
        });

        $thrown = null;

        try {
            $this->applyFor($product, $newAxes);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('Injected failure during generation.', $thrown->getMessage());

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeStatuses, $this->variationStatusBySku($productId));
        $this->assertSame(1, DB::table('stock_levels')->where('variation_id', $deletableId)->count());
        $this->assertSame(1, DB::table('pricing_product_costs')->where('priceable_id', $deletableId)->count());
        $this->assertSame(0, DB::table('activity_log')->count());
    }

    /**
     * The locking window §3.19.5 exists for, on the restructure's own path: a
     * sale line committed on a variation the plan would DELETE, after the impact
     * was read. `apply()` re-derives the plan inside its transaction, but this
     * connection's snapshot predates that commit — so the plan still says
     * "deletable", and it is CatalogDeletion::deleteVariation()'s own LOCKING
     * history read that sees the line and refuses. The whole restructure rolls
     * back: the axes are untouched, the variation is still live, and the only
     * new row anywhere is the sale line the other connection committed.
     */
    public function test_a_sale_line_committed_after_the_impact_makes_the_variation_delete_refuse_and_rolls_everything_back(): void
    {
        config()->set(
            'database.connections.deletion_race',
            config('database.connections.'.config('database.default'))
        );
        DB::purge('deletion_race');

        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
        ]);

        $productId = (string) $product->id();
        $variationId = $this->variationIdBySku($productId, 'SKU-AX-1');

        $newAxes = [$this->axis($color, $black, $white)];

        // The merchant's own view of the world: this variation is deletable.
        $impact = $this->impactFor($product, $newAxes);

        $this->assertSame(
            ['SKU-AX-1'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBeDeleted),
        );

        // A checkout sells it, committed immediately on the other connection.
        $this->commitSaleLineOnSecondConnection($variationId);

        $refusal = null;

        try {
            $this->applyFor($product, $newAxes);
        } catch (\Throwable $e) {
            $refusal = $e;
        }

        $this->assertInstanceOf(VariationNotDeletableException::class, $refusal);
        $this->assertSame(VariationDeletionRefusal::HAS_HISTORY, $refusal->reason);

        // Nothing was restructured...
        $this->assertSame([
            (string) $color->id() => [(string) $black->id(), (string) $white->id()],
            (string) $size->id() => [(string) $small->id()],
        ], $this->persistedAxesOf($productId));
        $this->assertSame(['SKU-AX-1' => 'draft'], $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->count());

        // ...and the sale line is counted on the OTHER connection, because this
        // one's snapshot genuinely cannot see it — the blindness the locking
        // read had to see past.
        $this->assertSame(
            1,
            DB::connection('deletion_race')->table('operational_sales_sale_lines')
                ->where('priceable_id', $variationId)
                ->count()
        );
    }

    /**
     * Commits a sale line for $variationId on the SECOND connection — outside
     * this test's own wrapping transaction, so its write is genuinely committed
     * and genuinely invisible to the snapshot the first connection already
     * established.
     */
    private function commitSaleLineOnSecondConnection(string $variationId): void
    {
        $clientId = (string) ClientModel::on('deletion_race')->create(['name' => 'Race Client'])->id;
        $transactionId = (string) TransactionModel::on('deletion_race')->create(['channel' => Channel::POS->value])->id;

        $this->raceClientId = $clientId;
        $this->raceTransactionId = $transactionId;

        SaleLineModel::on('deletion_race')->create([
            'transaction_id' => $transactionId,
            'client_id' => $clientId,
            'priceable_id' => $variationId,
            'type' => 'sale',
            'status' => 'completed',
            'quantity' => 1,
            'amount_minor' => 2500,
            'amount_currency' => 'EUR',
            'profit_minor' => 400,
            'profit_currency' => 'EUR',
            'recorded_at' => '2026-08-25 10:00:00',
            'effective_at' => '2026-08-20 09:00:00',
        ]);
    }

    /**
     * R1 on the restructure's own path: the same set resubmitted — deliberately
     * in a different axis ORDER, since R1 is order-insensitive — is a no-op that
     * writes nothing at all: no deletion, no archive, no generation, no log
     * entry. §3.17's own reason for R1 is that a plain re-save must stay
     * harmless, and a merchant who opens the modal and confirms without editing
     * anything gets exactly that.
     */
    public function test_an_identical_axis_set_is_a_no_op_that_writes_nothing(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
        ]);

        $productId = (string) $product->id();

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeStatuses = $this->variationStatusBySku($productId);

        $reordered = [
            $this->axis($size, $small),
            $this->axis($color, $black, $white),
        ];

        $impact = $this->impactFor($product, $reordered);

        $this->assertTrue($impact->canApply());
        $this->assertFalse($impact->axesDiffer);
        $this->assertFalse($impact->affectsVariations());
        $this->assertSame([], $impact->willBeDeleted);
        $this->assertSame([], $impact->willBeArchived);

        $result = $this->applyFor($product, $reordered);

        $this->assertFalse($result->changed);
        $this->assertSame(0, $result->deletedVariationCount);
        $this->assertSame(0, $result->archivedVariationCount);
        $this->assertSame(0, $result->createdVariationCount);
        $this->assertSame(0, $result->restoredVariationCount);

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeStatuses, $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->count());
    }

    /**
     * A set the domain will never declare, whatever the variations are: the same
     * attribute twice. The impact carries the refusal instead of a plan (so the
     * modal shows the reason and no submit button), and apply() throws the same
     * refusal without writing anything.
     */
    public function test_duplicate_axis_definitions_are_refused_and_nothing_is_written(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
        ], [
            ['combination' => [$color->id() => $black->id()], 'sku' => 'SKU-AX-1'],
        ]);

        $productId = (string) $product->id();
        $newAxes = [$this->axis($color, $black, $white), $this->axis($color, $black)];

        $impact = $this->impactFor($product, $newAxes);

        $this->assertFalse($impact->canApply());
        $this->assertNotNull($impact->refusal);
        $this->assertSame(AxesRestructureRefusal::INVALID_AXES, $impact->refusal->reason);
        $this->assertSame([], $impact->willBeDeleted);
        $this->assertSame([], $impact->willBeArchived);
        $this->assertStringContainsString('more than once', (string) $impact->refusal->detail);

        $thrown = null;

        try {
            $this->applyFor($product, $newAxes);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(AxesRestructureRefused::class, $thrown);
        $this->assertSame(AxesRestructureRefusal::INVALID_AXES, $thrown->reason);

        $this->assertSame([
            (string) $color->id() => [(string) $black->id(), (string) $white->id()],
        ], $this->persistedAxesOf($productId));
        $this->assertSame(['SKU-AX-1' => 'draft'], $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->count());
    }

    /** A stale id (already deleted, or never existed) is reported, never half-acted-on. */
    public function test_an_unknown_product_is_reported(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(VariationAxisRestructure::class)->impact('999999', []);
    }

    /**
     * THE CONFIRMED PLAN IS THE EXECUTED PLAN (§3.19.8 C): a variation that
     * appears between the impact being read and the change being applied makes
     * the fingerprints differ, so `apply()` refuses — and refuses BEFORE anything
     * is written: the variation the plan never saw is untouched, the one it did
     * see is still live, the axes are what they were, and not a single
     * activity-log row exists (the deletion snapshot bypasses the log setting, so
     * an empty table is real proof that nothing was deleted).
     */
    public function test_a_variation_added_between_the_impact_and_the_apply_makes_it_refuse_and_change_nothing(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
        ]);

        $productId = (string) $product->id();

        $newAxes = [$this->axis($color, $black, $white)];

        // What the merchant was shown: exactly one variation, and it is deletable.
        $impact = $this->impactFor($product, $newAxes);

        $this->assertSame(
            ['SKU-AX-1'],
            array_map(static fn ($variation): string => $variation->sku, $impact->willBeDeleted),
        );

        // Between the dialog and the submit a SECOND live variation appears — an
        // import, another tab, another staff member. The confirmed plan never saw
        // it.
        $fresh = app(ProductRepository::class)->findByIdWithVariations($productId);
        $fresh->addStandardVariation([$color->id() => $white->id(), $size->id() => $small->id()], 'SKU-AX-2');
        app(ProductRepository::class)->save($fresh);

        $refusal = null;

        try {
            app(VariationAxisRestructure::class)->apply($productId, $newAxes, true, $impact->fingerprint);
        } catch (\Throwable $e) {
            $refusal = $e;
        }

        $this->assertInstanceOf(AxesRestructureRefused::class, $refusal);
        $this->assertSame(AxesRestructureRefusal::PLAN_CHANGED, $refusal->reason);

        $this->assertSame([
            'SKU-AX-1' => 'draft',
            'SKU-AX-2' => 'draft',
        ], $this->variationStatusBySku($productId));
        $this->assertSame([
            (string) $color->id() => [(string) $black->id(), (string) $white->id()],
            (string) $size->id() => [(string) $small->id()],
        ], $this->persistedAxesOf($productId));
        $this->assertSame(0, DB::table('activity_log')->count());
    }

    /**
     * The same refusal from the other direction: a fingerprint that does not
     * describe THIS plan — one from a different axes set, a fabricated string, an
     * empty one, or none at all — is refused exactly like a changed product, with
     * nothing written. A tampered fingerprint can only ever cause a refusal; the
     * lists that would be deleted are still the ones apply() derives itself.
     */
    public function test_a_fingerprint_from_another_plan_is_refused_like_a_changed_product(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
        ]);

        $productId = (string) $product->id();

        $newAxes = [$this->axis($color, $black, $white)];

        // The plan for the CURRENT axes: a no-op, so a genuinely different plan
        // from the one the merchant would be confirming.
        $otherFingerprint = $this->impactFor($product, [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ])->fingerprint;

        foreach ([$otherFingerprint, str_repeat('a', 64), '', null] as $tampered) {
            $refusal = null;

            try {
                app(VariationAxisRestructure::class)->apply($productId, $newAxes, true, $tampered);
            } catch (\Throwable $e) {
                $refusal = $e;
            }

            $this->assertInstanceOf(
                AxesRestructureRefused::class,
                $refusal,
                'fingerprint: '.var_export($tampered, true),
            );
            $this->assertSame(AxesRestructureRefusal::PLAN_CHANGED, $refusal->reason);
        }

        $this->assertSame(['SKU-AX-1' => 'draft'], $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->count());
    }

    /**
     * The fingerprint's own contract, so the check above means something: one
     * plan hashes the same however it is spelled (axes in another order, values
     * in another order), and a different plan — a different axes set, and so a
     * different variation in the delete list — hashes differently.
     */
    public function test_the_fingerprint_is_stable_for_one_plan_and_differs_for_another(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = $this->variableProduct('SKU-AX', 'variable-shirt', [
            $this->axis($color, $black, $white),
            $this->axis($size, $small),
        ], [
            ['combination' => [$color->id() => $black->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-1'],
            ['combination' => [$color->id() => $white->id(), $size->id() => $small->id()], 'sku' => 'SKU-AX-2'],
        ]);

        $planFor = fn (array $axes): string => $this->impactFor($product, $axes)->fingerprint;

        $oneAxis = [$this->axis($color, $black, $white)];

        // The same plan, spelled differently.
        $this->assertSame($planFor($oneAxis), $planFor([$this->axis($color, $white, $black)]));

        // Different axes — and therefore different lists — so a different plan.
        $this->assertNotSame($planFor($oneAxis), $planFor([$this->axis($color, $black)]));
        $this->assertNotSame($planFor($oneAxis), $planFor([$this->axis($color, $white)]));
    }
}
