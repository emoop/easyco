<?php

namespace Tests\Feature;

use App\Services\ProductStatusChanger;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * App\Services\ProductStatusChanger — the ONE place a product's own status
 * changes (D4): the domain call, the save, the archived-media cleanup and the
 * activity-log entry, shared by the edit page's Status field and the products
 * list's bulk actions.
 *
 * DRIVEN AGAINST THE REAL DATABASE AND THE REAL SERVICES, never mocks: the
 * whole point of the class is the ORDER and the SET of real side effects, which
 * a mocked repository would report exactly as the test's own assumption wrote
 * them. `attachMedia()` therefore writes real catalog_media/catalog_product_media
 * rows, and the log assertions read the real activity_log table with the
 * `admin.activity_log_enabled` gate explicitly ON — the gate this codebase's own
 * ActivityLogJournalTest uses, not a mock of it.
 *
 * THE MEDIA PROOF IS DELIBERATELY NARROW: the cleaner's real file-by-file
 * behaviour (thumbnail demotion, on-disk deletion) is already covered by
 * ProductArchiveMediaCleanupTest, so this file asserts only that the cleaner RAN
 * at all and in the right order — the second pivot and its asset are gone, the
 * first (which has no 'thumbnail' variant to demote to, so the cleaner leaves it
 * untouched) is still there.
 */
class ProductStatusChangerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

    private function changer(): ProductStatusChanger
    {
        return app(ProductStatusChanger::class);
    }

    /** A persisted SIMPLE product, DRAFT (the state Product::createSimple() starts in). */
    private function simpleProduct(string $slug = 'status-product', string $sku = 'SKU-STATUS'): ProductModel
    {
        $product = Product::createSimple('Status Product', $sku, $slug);
        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /**
     * A persisted VARIABLE product, DRAFT, with `$liveVariations` STANDARD
     * variations (0 = the state Product::publish()'s own guard refuses).
     */
    private function variableProduct(int $liveVariations, string $slug = 'status-variable', string $baseSku = 'SKU-VAR'): ProductModel
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $product = Product::createVariable('Status Variable', $baseSku, $slug);
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);

        if ($liveVariations > 0) {
            $product->addStandardVariation([$definition->id() => $black->id()], $baseSku.'-BLACK');
        }

        if ($liveVariations > 1) {
            $product->addStandardVariation([$definition->id() => $white->id()], $baseSku.'-WHITE');
        }

        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /** One real image pivot (plus the catalog_media asset behind it) at the given sort_order. */
    private function attachMedia(string $productId, int $sortOrder, string $path): int
    {
        $mediaId = (int) DB::table('catalog_media')->insertGetId([
            'type' => 'image',
            'disk' => 'public',
            'path' => $path,
            'alt_text' => null,
            'processing_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_product_media')->insert([
            'product_id' => $productId,
            'media_id' => $mediaId,
            'sort_order' => $sortOrder,
        ]);

        return $mediaId;
    }

    /**
     * Every 'status' field-change row for one product, oldest first — read with
     * the real column names ActivityLogger::write() inserts.
     *
     * @return array<int, object>
     */
    private function statusLogRows(string $productId): array
    {
        return DB::table('activity_log')
            ->where('entity_type', 'product')
            ->where('entity_id', $productId)
            ->where('field', 'status')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function test_archive_transitions_saves_cleans_the_media_and_logs_the_change(): void
    {
        $product = $this->simpleProduct();
        $productId = (string) $product->id;

        $mainAssetId = $this->attachMedia($productId, 0, 'products/main.jpg');
        $galleryAssetId = $this->attachMedia($productId, 1, 'products/gallery.jpg');

        $this->changer()->archive($productId);

        // SAVED — the standalone operation owns the write.
        $this->assertSame(ProductStatus::ARCHIVED->value, ProductModel::find($productId)->status);

        // THE CLEANER RAN: the gallery pivot and its asset are gone; the main
        // photo is still there (no 'thumbnail' variant to demote to, so the
        // cleaner deliberately leaves it alone rather than lose the image).
        $remainingSortOrders = array_map(
            static fn ($value): int => (int) $value,
            DB::table('catalog_product_media')->where('product_id', $productId)->pluck('sort_order')->all(),
        );
        $this->assertSame([0], $remainingSortOrders);
        $this->assertNull(DB::table('catalog_media')->where('id', $galleryAssetId)->first());
        $this->assertNotNull(DB::table('catalog_media')->where('id', $mainAssetId)->first());

        // LOGGED — once, with the real values, through the same ActivityLogger
        // the edit page uses.
        $rows = $this->statusLogRows($productId);
        $this->assertCount(1, $rows);
        $this->assertSame('updated', $rows[0]->action);
        $this->assertSame(ProductStatus::DRAFT->value, $rows[0]->old_value);
        $this->assertSame(ProductStatus::ARCHIVED->value, $rows[0]->new_value);
    }

    public function test_archive_is_a_silent_no_op_on_an_already_archived_product(): void
    {
        $product = $this->simpleProduct('status-already-archived', 'SKU-STATUS-ARCH');
        $productId = (string) $product->id;

        $this->changer()->archive($productId);

        $this->changer()->archive($productId);

        // Idempotent by construction: an unchanged status logs nothing and
        // writes nothing, which is what makes an already-archived product in a
        // bulk selection harmless (D5).
        $this->assertSame(ProductStatus::ARCHIVED->value, ProductModel::find($productId)->status);
        $this->assertCount(1, $this->statusLogRows($productId));
    }

    public function test_publish_transitions_and_saves_a_draft_product(): void
    {
        $product = $this->simpleProduct('status-to-publish', 'SKU-STATUS-PUB');
        $productId = (string) $product->id;

        $this->changer()->publish($productId);

        $this->assertSame(ProductStatus::ACTIVE->value, ProductModel::find($productId)->status);

        $rows = $this->statusLogRows($productId);
        $this->assertCount(1, $rows);
        $this->assertSame(ProductStatus::DRAFT->value, $rows[0]->old_value);
        $this->assertSame(ProductStatus::ACTIVE->value, $rows[0]->new_value);
    }

    public function test_publish_refuses_a_variable_product_with_no_live_standard_variation_and_changes_no_status(): void
    {
        $product = $this->variableProduct(0);
        $productId = (string) $product->id;

        // The DOMAIN's own guard, uncaught here on purpose: this class adds no
        // rules of its own (the bulk action is what turns this into a per-product
        // refusal sentence).
        $caught = false;
        try {
            $this->changer()->publish($productId);
        } catch (CannotPublishEmptyVariableProductException) {
            $caught = true;
        }

        $this->assertTrue($caught, 'publish() must refuse a VARIABLE product with no non-ARCHIVED STANDARD variation.');
        $this->assertSame(ProductStatus::DRAFT->value, ProductModel::find($productId)->status);
    }

    public function test_publish_accepts_a_variable_product_that_has_a_live_standard_variation(): void
    {
        $product = $this->variableProduct(1, 'status-variable-live', 'SKU-VAR-LIVE');
        $productId = (string) $product->id;

        $this->changer()->publish($productId);

        $this->assertSame(ProductStatus::ACTIVE->value, ProductModel::find($productId)->status);
    }

    public function test_archive_throws_for_a_product_that_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->changer()->archive('00000000-0000-0000-0000-000000000000');
    }

    public function test_apply_status_transitions_and_logs_without_saving_the_aggregate(): void
    {
        $productModel = $this->simpleProduct('status-apply', 'SKU-STATUS-APPLY');
        $productId = (string) $productModel->id;

        // The aggregate the CALLER owns — loaded, mutated, NOT saved. The edit
        // page is exactly this caller: one submission mutates a dozen fields and
        // saves ONCE, so applying a status must not write on its own.
        $product = app(ProductRepository::class)->findByIdWithVariations($productId);
        $this->assertNotNull($product);

        $this->changer()->applyStatus($product, ProductStatus::ARCHIVED);

        $this->assertSame(ProductStatus::ARCHIVED, $product->status());
        $this->assertSame(
            ProductStatus::DRAFT->value,
            ProductModel::find($productId)->status,
            'applyStatus() must not save on behalf of a caller that owns the write.',
        );
        $this->assertCount(1, $this->statusLogRows($productId));

        // ...and the caller's own save is what makes the transition real.
        app(ProductRepository::class)->save($product);
        $this->assertSame(ProductStatus::ARCHIVED->value, ProductModel::find($productId)->status);
    }

    public function test_apply_status_is_a_no_op_when_the_status_does_not_change(): void
    {
        $productModel = $this->simpleProduct('status-apply-same', 'SKU-STATUS-SAME');
        $productId = (string) $productModel->id;

        $product = app(ProductRepository::class)->findByIdWithVariations($productId);
        $this->assertNotNull($product);

        $this->changer()->applyStatus($product, ProductStatus::DRAFT);

        $this->assertCount(0, $this->statusLogRows($productId));
        $this->assertSame(ProductStatus::DRAFT, $product->status());
    }

    public function test_apply_status_propagates_the_domains_own_refusal(): void
    {
        $productModel = $this->variableProduct(0, 'status-apply-refused', 'SKU-VAR-REFUSED');

        $product = app(ProductRepository::class)->findByIdWithVariations((string) $productModel->id);
        $this->assertNotNull($product);

        $caught = false;
        try {
            $this->changer()->applyStatus($product, ProductStatus::ACTIVE);
        } catch (CannotPublishEmptyVariableProductException) {
            $caught = true;
        }

        $this->assertTrue($caught);
        $this->assertSame(ProductStatus::DRAFT, $product->status());
    }

    public function test_requires_archived_media_cleanup_is_true_only_for_a_move_into_archived(): void
    {
        $changer = $this->changer();

        $this->assertTrue($changer->requiresArchivedMediaCleanup(ProductStatus::DRAFT, ProductStatus::ARCHIVED));
        $this->assertTrue($changer->requiresArchivedMediaCleanup(ProductStatus::ACTIVE, ProductStatus::ARCHIVED));
        $this->assertFalse(
            $changer->requiresArchivedMediaCleanup(ProductStatus::ARCHIVED, ProductStatus::ARCHIVED),
            're-saving an already-archived product must not re-run the cleanup.',
        );
        $this->assertFalse($changer->requiresArchivedMediaCleanup(ProductStatus::ARCHIVED, ProductStatus::ACTIVE));
        $this->assertFalse($changer->requiresArchivedMediaCleanup(ProductStatus::DRAFT, ProductStatus::ACTIVE));
    }
}
