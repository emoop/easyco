<?php

namespace Tests\Feature\Media;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Media\DeleteOutcome;
use App\Media\MediaDeleter;
use App\Services\CatalogDeletion;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Jobs\DeleteMediaFilesJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-1b (media-library-design.md §4, ML-D3 / ML-D4): the delete core and the database guard. A picture that is used anywhere is never deleted (the provider re-check inside the lock; the
 * foreign keys of the product, variation and brand tables as the last line), the files go after the commit, and deleting a PRODUCT still removes its own pivot rows.
 */
class MediaDeleterTest extends TestCase
{
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveProductsAShippingClass();
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
    }

    /** @param  list<string>  $variantTiers */
    private function asset(array $variantTiers = ['thumbnail', 'medium', 'large'], bool $withFiles = true): MediaAssetModel
    {
        $uuid = uniqid();
        $path = "uploads/2026/10/{$uuid}.jpg";
        $variants = array_map(fn (string $tier): array => ['tier' => $tier, 'width' => 100, 'height' => 100, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"], $variantTiers);

        if ($withFiles) {
            Storage::disk('public')->put($path, 'original');
            foreach ($variants as $variant) {
                Storage::disk('public')->put($variant['path'], 'variant');
            }
        }

        return MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => $path, 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "{$uuid}.jpg", 'size_bytes' => 8]);
    }

    private function product(string $name = 'Рокля'): ProductModel
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper(substr(md5($name), 0, 6)), 'slug-'.strtolower(substr(md5($name), 0, 6)));
        app(ProductRepository::class)->save($product);

        return ProductModel::query()->findOrFail($product->id());
    }

    /** @return array{0: ProductModel, 1: int} */
    private function variableProduct(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Цвят', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Черен');
        app(AttributeValueRepository::class)->save($black);
        $product = Product::createVariable('Тениска', 'SKU-VAR', 'teniska');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        app(ProductRepository::class)->save($product);
        $model = ProductModel::query()->findOrFail($product->id());

        return [$model, (int) DB::table('catalog_variations')->where('product_id', $model->id)->value('id')];
    }

    private function deleter(): MediaDeleter
    {
        return app(MediaDeleter::class);
    }

    // ---- the core ----------------------------------------------------------------------------------------------------------------

    public function test_an_unused_asset_is_deleted_and_the_files_job_carries_the_original_and_every_variant(): void
    {
        Queue::fake();
        $asset = $this->asset();
        $original = $asset->path;
        $variants = array_column($asset->variants, 'path');

        $outcome = $this->deleter()->delete((int) $asset->id, 5);

        $this->assertSame(DeleteOutcome::DELETED, $outcome->status);
        $this->assertNull(MediaAssetModel::query()->find($asset->id));
        Queue::assertPushed(DeleteMediaFilesJob::class, function (DeleteMediaFilesJob $job) use ($original, $variants): bool {
            $values = [];
            foreach ((new \ReflectionObject($job))->getProperties() as $p) {
                $p->setAccessible(true);
                $values[$p->getName()] = $p->getValue($job);
            }

            return $values['disk'] === 'public' && $values['path'] === $original && $values['variantPaths'] === $variants;
        });
    }

    public function test_the_files_are_removed_by_the_job_including_a_stray_variant_left_by_a_failed_processing(): void
    {
        $asset = $this->asset(['thumbnail']);
        $base = pathinfo($asset->path, PATHINFO_FILENAME);
        $stray = "uploads/2026/10/{$base}-medium.webp";
        $neighbour = 'uploads/2026/10/someone-else-thumbnail.webp';
        Storage::disk('public')->put($stray, 'left over');
        Storage::disk('public')->put($neighbour, 'not ours');

        $this->deleter()->delete((int) $asset->id);

        $this->assertSame(['uploads/2026/10/someone-else-thumbnail.webp'], Storage::disk('public')->allFiles(), 'only this asset\'s files went: the original, the listed variant and the stray one');
    }

    public function test_a_row_whose_file_is_already_missing_is_deleted_without_an_error(): void
    {
        Queue::fake();
        $asset = $this->asset(withFiles: false);
        $asset->update(['missing_at' => now()]);

        $this->assertSame(DeleteOutcome::DELETED, $this->deleter()->delete((int) $asset->id)->status);

        $this->assertNull(MediaAssetModel::query()->find($asset->id));
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->assertSame(DeleteOutcome::NOT_FOUND, $this->deleter()->delete(987654)->status);
    }

    public function test_the_files_job_survives_a_missing_file_and_an_unsafe_path(): void
    {
        Storage::disk('public')->put('keep/me.jpg', 'x');

        (new DeleteMediaFilesJob('public', 'uploads/nothing/here.jpg', ['uploads/nothing/here-thumbnail.webp']))->handle();
        (new DeleteMediaFilesJob('public', '../keep/me.jpg'))->handle();
        (new DeleteMediaFilesJob('public', ''))->handle();

        $this->assertSame(['keep/me.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_the_files_job_is_retried_when_a_file_cannot_be_removed(): void
    {
        $job = new DeleteMediaFilesJob('public', 'a.jpg');

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff);

        $failing = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $failing->shouldReceive('files')->andReturn([]);
        $failing->shouldReceive('exists')->andReturn(true);
        $failing->shouldReceive('delete')->andThrow(new \RuntimeException('disk down'));
        Storage::shouldReceive('disk')->with('public')->andReturn($failing);

        $this->expectException(\RuntimeException::class);
        $job->handle();
    }

    // ---- in use ------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: callable(self, MediaAssetModel): void}> */
    public static function usages(): array
    {
        return [
            'a product photo' => [fn (self $t, MediaAssetModel $a) => ProductMediaModel::query()->create(['product_id' => $t->product()->id, 'media_id' => $a->id, 'sort_order' => 0, 'autoplay' => false])],
            'a variation photo' => [function (self $t, MediaAssetModel $a): void {
                [, $variationId] = $t->variableProduct();
                VariationMediaModel::query()->create(['variation_id' => $variationId, 'media_id' => $a->id, 'sort_order' => 0]);
            }],
            'a brand logo' => [fn (self $t, MediaAssetModel $a) => BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $a->id])],
            'an HTML block picture' => [fn (self $t, MediaAssetModel $a) => HtmlBlockModel::query()->create(['title' => 'Промо', 'body' => '<p>x</p>', 'pictures' => [['media_asset_id' => $a->id, 'alt' => '', 'url' => '']]])],
            'a design draft' => [fn (self $t, MediaAssetModel $a) => app(SiteSettingsRepository::class)->set(AppearanceSettings::FOOTER_DRAFT_KEY, json_encode(['x' => ['media_asset_id' => $a->id]]))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('usages')]
    public function test_a_used_asset_is_never_deleted_and_nothing_changes(callable $use): void
    {
        Queue::fake();
        $asset = $this->asset();
        $use($this, $asset);

        $outcome = $this->deleter()->delete((int) $asset->id);

        $this->assertSame(DeleteOutcome::IN_USE, $outcome->status);
        $this->assertGreaterThan(0, $outcome->uses);
        $this->assertNotNull(MediaAssetModel::query()->find($asset->id));
        Queue::assertNothingPushed();
        $this->assertCount(4, Storage::disk('public')->allFiles());
    }

    // ---- the database guard ------------------------------------------------------------------------------------------------------

    public function test_the_database_refuses_to_delete_a_used_asset_behind_the_applications_back(): void
    {
        $cases = [
            'product pivot' => function (MediaAssetModel $a): void {
                ProductMediaModel::query()->create(['product_id' => $this->product()->id, 'media_id' => $a->id, 'sort_order' => 0, 'autoplay' => false]);
            },
            'variation pivot' => function (MediaAssetModel $a): void {
                [, $variationId] = $this->variableProduct();
                VariationMediaModel::query()->create(['variation_id' => $variationId, 'media_id' => $a->id, 'sort_order' => 0]);
            },
            'brand logo' => function (MediaAssetModel $a): void {
                BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $a->id]);
            },
        ];

        foreach ($cases as $name => $use) {
            $asset = $this->asset();
            $use($asset);

            try {
                DB::table('catalog_media')->where('id', $asset->id)->delete();
                $this->fail("{$name}: the row was deleted");
            } catch (QueryException $e) {
                $this->assertSame('23000', $e->errorInfo[0] ?? null, $name);
                $this->assertSame(1451, (int) ($e->errorInfo[1] ?? 0), $name);
            }

            $this->assertNotNull(MediaAssetModel::query()->find($asset->id), $name);
        }
    }

    public function test_the_constraint_names_are_short_and_explicit(): void
    {
        $names = collect(DB::select('select constraint_name n, delete_rule d from information_schema.referential_constraints where constraint_schema = database() and constraint_name in (?, ?, ?)', ['cpm_media_fk', 'cvm_media_fk', 'cb_logo_media_fk']));

        $this->assertCount(3, $names);
        $this->assertSame(['RESTRICT'], $names->pluck('d')->unique()->values()->all());
    }

    public function test_deleting_a_product_with_photos_still_works_and_leaves_the_pictures_unused(): void
    {
        $product = $this->product();
        $a = $this->asset();
        $b = $this->asset();
        ProductMediaModel::query()->create(['product_id' => $product->id, 'media_id' => $a->id, 'sort_order' => 0, 'autoplay' => false]);
        ProductMediaModel::query()->create(['product_id' => $product->id, 'media_id' => $b->id, 'sort_order' => 1, 'autoplay' => false]);

        $product->update(['status' => 'archived']); // only an archived product can be deleted
        app(CatalogDeletion::class)->deleteProduct((string) $product->id);

        $this->assertNull(ProductModel::withTrashed()->find($product->id));
        $this->assertSame(0, ProductMediaModel::query()->count(), 'the pivot rows went with the product');
        $this->assertNotNull(MediaAssetModel::query()->find($a->id), 'the pictures stay in the library');
        $this->assertSame(DeleteOutcome::DELETED, $this->deleter()->delete((int) $a->id)->status, 'and are now unused: deletable');
    }

    public function test_deleting_a_variation_with_photos_leaves_the_picture_unused(): void
    {
        [, $variationId] = $this->variableProduct();
        $a = $this->asset();
        VariationMediaModel::query()->create(['variation_id' => $variationId, 'media_id' => $a->id, 'sort_order' => 0]);

        app(CatalogDeletion::class)->deleteVariation((string) $variationId);

        $this->assertSame(0, VariationMediaModel::query()->count());
        $this->assertSame(DeleteOutcome::DELETED, $this->deleter()->delete((int) $a->id)->status);
    }
}
