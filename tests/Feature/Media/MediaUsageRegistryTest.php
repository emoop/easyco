<?php

namespace Tests\Feature\Media;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Media\Usage\BrandLogo;
use App\Media\Usage\DesignZones;
use App\Media\Usage\HtmlBlockPictures;
use App\Media\Usage\MediaUsage;
use App\Media\Usage\MediaUsageProvider;
use App\Media\Usage\MediaUsageRegistry;
use App\Media\Usage\ProductPhotos;
use App\Media\Usage\VariationPhotos;
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
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-1a (media-library-design.md §3): the usage registry. Each built-in provider counts and lists its uses (the archived and the soft-deleted product too), `excludeUsed()` keeps exactly the
 * pictures nobody uses, an extension adds a provider through the `media.usages` hook and an invalid one is skipped.
 */
class MediaUsageRegistryTest extends TestCase
{
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
        app(HookRegistry::class)->clear(MediaUsageRegistry::HOOK);
    }

    private function asset(string $type = 'image'): int
    {
        return (int) MediaAssetModel::query()->create(['type' => $type, 'disk' => 'public', 'path' => 'uploads/2026/10/'.uniqid().'.jpg', 'processing_status' => 'ready', 'variants' => []])->id;
    }

    private function product(string $name): ProductModel
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper(substr(md5($name), 0, 6)), 'slug-'.strtolower(substr(md5($name), 0, 6)));
        app(ProductRepository::class)->save($product);

        return ProductModel::query()->findOrFail($product->id());
    }

    private function attach(ProductModel $product, int $mediaId, int $sort): void
    {
        ProductMediaModel::query()->create(['product_id' => $product->id, 'media_id' => $mediaId, 'sort_order' => $sort, 'autoplay' => false]);
    }

    /** @return array{0: ProductModel, 1: int} a variable product with one variation, and that variation's id */
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

        return [$model, (int) \Illuminate\Support\Facades\DB::table('catalog_variations')->where('product_id', $model->id)->value('id')];
    }

    private function labels(array $usages): array
    {
        return array_map(fn (MediaUsage $u): string => $u->label, $usages);
    }

    public function test_product_photos_count_and_list_main_gallery_video_and_the_archived_and_deleted_product(): void
    {
        $main = $this->asset();
        $gallery = $this->asset();
        $video = $this->asset('video');
        $unused = $this->asset();
        $shared = $this->asset();
        $live = $this->product('Рокля');
        $archived = $this->product('Стара рокля');
        $archived->update(['status' => 'archived']);
        $deleted = $this->product('Изтрита рокля');
        $this->attach($live, $main, 0);
        $this->attach($live, $gallery, 1);
        $this->attach($live, $video, 2);
        $this->attach($live, $shared, 3);
        $this->attach($archived, $shared, 0);
        $this->attach($deleted, $shared, 0);
        $deleted->delete();

        $provider = new ProductPhotos;

        $this->assertSame([$main => 1, $shared => 3], array_intersect_key($provider->counts([$main, $gallery, $unused, $shared]), [$main => 1, $shared => 1]));
        $this->assertArrayNotHasKey($unused, $provider->counts([$unused]));
        $this->assertSame(['Продукт „Рокля“ — главна снимка'], $this->labels($provider->usagesOf($main)));
        $this->assertSame(['Продукт „Рокля“ — галерия'], $this->labels($provider->usagesOf($gallery)));
        $this->assertSame(['Продукт „Рокля“ — видео'], $this->labels($provider->usagesOf($video)));

        $all = $provider->usagesOf($shared);
        $this->assertSame(['Продукт „Рокля“ — галерия', 'Продукт „Стара рокля“ — главна снимка (архивиран продукт)', 'Продукт „Изтрита рокля“ — главна снимка (архивиран продукт)'], $this->labels($all));
        $this->assertStringContainsString((string) $live->id, (string) $all[0]->editUrl);
        $this->assertNull($all[2]->editUrl, 'a deleted product has no page to open');
        $this->assertSame('product', $all[0]->kind);
    }

    public function test_variation_photos_count_and_list_with_the_combination(): void
    {
        [$product, $variationId] = $this->variableProduct();
        $used = $this->asset();
        $unused = $this->asset();
        VariationMediaModel::query()->create(['variation_id' => $variationId, 'media_id' => $used, 'sort_order' => 0]);

        $provider = new VariationPhotos;

        $this->assertSame([$used => 1], $provider->counts([$used, $unused]));
        $usages = $provider->usagesOf($used);
        $this->assertSame(['Вариация „Тениска — Цвят: Черен“'], $this->labels($usages));
        $this->assertStringContainsString((string) $product->id, (string) $usages[0]->editUrl);
        $this->assertStringContainsString('edit-variable', (string) $usages[0]->editUrl);
        $this->assertSame([], $provider->usagesOf($unused));
    }

    public function test_the_brand_logo_counts_and_lists(): void
    {
        $logo = $this->asset();
        $unused = $this->asset();
        $brand = BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $logo]);

        $provider = new BrandLogo;

        $this->assertSame([$logo => 1], $provider->counts([$logo, $unused]));
        $usages = $provider->usagesOf($logo);
        $this->assertSame(['Марка „Nike“ — лого'], $this->labels($usages));
        $this->assertStringContainsString('/'.$brand->id.'/edit', (string) $usages[0]->editUrl);
    }

    public function test_html_block_pictures_are_read_once_and_counted_by_position(): void
    {
        $a = $this->asset();
        $b = $this->asset();
        $unused = $this->asset();
        $block = HtmlBlockModel::query()->create(['title' => 'Промо', 'body' => '<p>x</p>', 'pictures' => [['media_asset_id' => $a, 'alt' => '', 'url' => ''], ['media_asset_id' => $b, 'alt' => '', 'url' => '']]]);
        HtmlBlockModel::query()->create(['title' => 'Друг', 'body' => '<p>y</p>', 'pictures' => [['media_asset_id' => $b, 'alt' => '', 'url' => '']]]);

        $provider = new HtmlBlockPictures;

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });

        $this->assertSame([$a => 1, $b => 2], $provider->counts([$a, $b, $unused]));
        $this->assertSame(['Промо', 'Друг'], array_map(fn (MediaUsage $u): string => preg_replace('/^HTML блок „(.+)“ — снимка \d$/u', '$1', $u->label), $provider->usagesOf($b)));
        $this->assertSame(['HTML блок „Промо“ — снимка 2', 'HTML блок „Друг“ — снимка 1'], $this->labels($provider->usagesOf($b)));
        $this->assertStringContainsString('/'.$block->id.'/edit', (string) $provider->usagesOf($a)[0]->editUrl);
        $this->assertCount(1, $queries, 'one read of all the blocks, however many pictures are asked');
    }

    public function test_design_zones_find_a_media_id_in_a_draft_and_in_the_published_layout(): void
    {
        $inDraft = $this->asset();
        $inPublished = $this->asset();
        $unused = $this->asset();
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::FOOTER_DRAFT_KEY, json_encode(['rows' => [['cells' => [['nodes' => [['type' => 'logo', 'settings' => ['media_asset_id' => $inDraft]]]]]]]]));
        $settings->set(AppearanceSettings::HEADER_PUBLISHED_KEY, json_encode(['desktop' => ['rows' => [['media_id' => (string) $inPublished]]]]));

        $provider = new DesignZones;

        $this->assertEquals([$inDraft => 1, $inPublished => 1], $provider->counts([$inDraft, $inPublished, $unused]));
        $this->assertSame(['Дизайн — Футър (чернова)'], $this->labels($provider->usagesOf($inDraft)));
        $this->assertSame(['Дизайн — Хедър'], $this->labels($provider->usagesOf($inPublished)));
        $this->assertSame([], $provider->usagesOf($unused));
    }

    public function test_design_zones_with_nothing_stored_find_nothing(): void
    {
        $this->assertSame([], (new DesignZones)->counts([$this->asset()]));
    }

    public function test_exclude_used_keeps_exactly_the_unused_pictures(): void
    {
        $product = $this->product('Рокля');
        [, $variationId] = $this->variableProduct();
        $onProduct = $this->asset();
        $onVariation = $this->asset();
        $onBrand = $this->asset();
        $inBlock = $this->asset();
        $inDesign = $this->asset();
        $unusedA = $this->asset();
        $unusedB = $this->asset('video');
        $this->attach($product, $onProduct, 0);
        VariationMediaModel::query()->create(['variation_id' => $variationId, 'media_id' => $onVariation, 'sort_order' => 0]);
        BrandModel::query()->create(['name' => 'Nike', 'slug' => 'nike', 'logo_media_asset_id' => $onBrand]);
        HtmlBlockModel::query()->create(['title' => 'Промо', 'body' => '<p>x</p>', 'pictures' => [['media_asset_id' => $inBlock, 'alt' => '', 'url' => '']]]);
        app(SiteSettingsRepository::class)->set(AppearanceSettings::FOOTER_DRAFT_KEY, json_encode(['x' => ['media_asset_id' => $inDesign]]));

        $registry = new MediaUsageRegistry;

        $unused = MediaAssetModel::query();
        $registry->excludeUsed($unused);
        $this->assertEqualsCanonicalizing([$unusedA, $unusedB], $unused->pluck('id')->map(fn ($id) => (int) $id)->all());

        $used = MediaAssetModel::query();
        $registry->onlyUsed($used);
        $this->assertEqualsCanonicalizing([$onProduct, $onVariation, $onBrand, $inBlock, $inDesign], $used->pluck('id')->map(fn ($id) => (int) $id)->all());

        $this->assertSame([$onProduct => 1, $onBrand => 1], array_intersect_key($registry->counts([$onProduct, $onBrand, $unusedA]), [$onProduct => 1, $onBrand => 1]));
        $this->assertArrayNotHasKey($unusedA, $registry->counts([$unusedA]));
    }

    public function test_the_hook_adds_a_provider_and_it_counts_everywhere(): void
    {
        $picture = $this->asset();
        $other = $this->asset();
        Hook::filter(MediaUsageRegistry::HOOK, function (array $providers) use ($picture): array {
            $providers[] = new class($picture) implements MediaUsageProvider
            {
                public function __construct(private readonly int $id)
                {
                }

                public function counts(array $ids): array
                {
                    return in_array($this->id, $ids, true) ? [$this->id => 2] : [];
                }

                public function usagesOf(int $id): array
                {
                    return $id === $this->id ? [new MediaUsage('extension', 'Разширение — слайдер')] : [];
                }

                public function excludeUsed(Builder $query): void
                {
                    $query->where('catalog_media.id', '!=', $this->id);
                }
            };

            return $providers;
        });

        $registry = new MediaUsageRegistry;

        $this->assertSame([$picture => 2], $registry->counts([$picture, $other]));
        $this->assertSame(['Разширение — слайдер'], $this->labels($registry->usagesOf($picture)));
        $unused = MediaAssetModel::query();
        $registry->excludeUsed($unused);
        $this->assertSame([$other], $unused->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_something_that_is_not_a_provider_is_skipped_and_logged_once(): void
    {
        $picture = $this->asset();
        Hook::filter(MediaUsageRegistry::HOOK, fn (array $providers): array => array_merge($providers, ['not a provider', new \stdClass, 42]));
        Log::spy();
        (new \ReflectionProperty(MediaUsageRegistry::class, 'logged'))->setValue(null, []);

        $registry = new MediaUsageRegistry;

        $this->assertCount(5, $registry->providers(), 'only the five built-in providers remain');
        $this->assertSame([], $registry->counts([$picture]));
        (new MediaUsageRegistry)->providers();

        Log::shouldHaveReceived('warning')->times(3);
    }
}
