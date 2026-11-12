<?php

namespace Tests\Feature\Storefront;

use App\Filament\Pages\Design\DesignScreen;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeDrafts;
use App\Storefront\Home\HomeTilesRecompiler;
use App\Storefront\Home\HomeValidator;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * P6e-3 Part B: the collections on the home page — a product grid / carousel source `collection` (ONE chosen collection), the «Плочки» tile of a collection (the collection's picture and `/collection/{slug}`), the
 * tile's recompile triggers, and the OFF rule: nothing renders and the editors do not offer it, the stored data stays and ON restores it.
 *
 * World: «Clothing» (1); collection Wedding (1, with a picture) holds P1, P2 and P3 (P3 SOLD OUT); P4 is in no collection.
 */
class CollectionHomeTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use MakesPickerAssets;
    use RefreshDatabase;

    private MediaAssetModel $asset;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop']);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->seedPricing();
        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->asset = $this->pickerAsset();

        foreach ([1 => 3, 2 => 3, 3 => 0, 4 => 3] as $id => $stock) {
            $this->makeProduct($id, ['name' => "P{$id}", 'slug' => "p{$id}", 'categories' => [1], 'price' => '10.00', 'stock' => $stock]);
        }

        DB::table('catalog_collections')->insert(['id' => 1, 'name' => 'Wedding', 'slug' => 'wedding', 'picture_media_asset_id' => $this->asset->id, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([1, 2, 3] as $product) {
            DB::table('catalog_product_collections')->insert(['product_id' => $product, 'collection_id' => 1]);
        }

        $this->on();
    }

    private function on(bool $on = true): void
    {
        $on ? app(SiteSettingsRepository::class)->set('catalog.collections_enabled', '1') : app(SiteSettingsRepository::class)->forget('catalog.collections_enabled');
        $this->app->forgetScopedInstances();
    }

    /** the way CatalogSettings::save does it, after the switch is stored */
    private function switchTo(bool $on): void
    {
        $this->on($on);
        $this->recompiler()->collectionsSwitch();
        $this->recompiler()->flush();
    }

    private function recompiler(): HomeTilesRecompiler
    {
        return app(HomeTilesRecompiler::class);
    }

    /** @param  list<array<string, mixed>>  $sections */
    private function publish(array $sections): void
    {
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => $sections]);
        $this->assertSame([], $errors, json_encode($errors));
        HomeDrafts::publish($clean);
    }

    private function home(): string
    {
        $this->app->forgetScopedInstances();

        return $this->get('/')->assertOk()->getContent();
    }

    /** @return list<int> */
    private function cards(string $html): array
    {
        preg_match_all('/data-product-id="(\d+)"/', $html, $m);

        return array_map('intval', $m[1]);
    }

    private function grid(string $source = 'collection', ?int $entity = 1): array
    {
        return ['type' => 'product_grid', 'settings' => ['source' => $source, 'entity' => $entity, 'count' => '12', 'sort' => 'newest']];
    }

    private function carousel(): array
    {
        return ['type' => 'carousel', 'settings' => ['kind' => 'products', 'source' => 'collection', 'entity' => 1, 'count' => '12', 'sort' => 'newest']];
    }

    private function tile(array $over = []): array
    {
        return ['type' => 'tiles', 'settings' => ['tiles' => [$over + ['source' => 'collection', 'entity' => 1, 'label' => '', 'picture' => null, 'link' => null]]]];
    }

    // ---- ★ the product grid and the carousel ----------------------------------------------------------------------------------------------

    public function test_a_collection_grid_lists_exactly_the_collections_products_and_hides_the_sold_out_one(): void
    {
        $this->publish([$this->grid()]);
        $ids = $this->cards($this->home());
        sort($ids);

        $this->assertSame([1, 2], $ids, 'P1 and P2 (P3 is sold out and hidden by P6c; P4 is not in the collection)');
    }

    public function test_a_collection_carousel_lists_the_same_products(): void
    {
        $this->publish([$this->carousel()]);
        $ids = $this->cards($this->home());
        sort($ids);

        $this->assertSame([1, 2], array_values(array_unique($ids)));
    }

    public function test_off_renders_nothing_and_on_again_restores_it(): void
    {
        $this->publish([$this->grid(), $this->carousel()]);
        $this->assertNotSame([], $this->cards($this->home()));

        $this->on(false);
        $this->assertSame([], $this->cards($this->home()), 'OFF: a stored collection source prints nothing');

        $this->on();
        $this->assertNotSame([], $this->cards($this->home()), 'ON again: the stored source is back');
    }

    public function test_a_stored_collection_source_still_validates_while_off(): void
    {
        $this->on(false);
        [$clean, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid()]]);

        $this->assertSame([], $errors, 'the data stays: a publish while OFF does not refuse it');
        $this->assertSame('collection', $clean['sections'][0]['settings']['source']);
        $this->assertSame(1, $clean['sections'][0]['settings']['entity']);
    }

    public function test_a_collection_source_needs_an_item(): void
    {
        [, $errors] = HomeValidator::validate(['spacing' => '32', 'sections' => [$this->grid('collection', null)]]);

        $this->assertArrayHasKey('sections.0.settings.entity', $errors, 'like the other sources that need an item');
    }

    // ---- ★ the tile ----------------------------------------------------------------------------------------------------------------------

    public function test_a_collection_tile_has_the_collections_picture_and_page_and_is_gone_while_off(): void
    {
        $this->publish([$this->tile()]);
        $html = $this->home();

        $this->assertStringContainsString('href="/collection/wedding"', $html);
        $this->assertStringContainsString('class="sf-hp-tile"', $html);
        $this->assertStringContainsString('>Wedding<', $html);

        $this->switchTo(false);
        $html = $this->home();
        $this->assertStringNotContainsString('/collection/wedding', $html, 'OFF: the compiled copy no longer holds the tile');
        $this->assertStringNotContainsString('class="sf-hp-tile"', $html);

        $this->switchTo(true);
        $this->assertStringContainsString('href="/collection/wedding"', $this->home(), 'ON again brings the stored tile back');
    }

    public function test_a_tile_with_its_own_link_keeps_it(): void
    {
        $this->publish([$this->tile(['link' => '/special'])]);
        $html = $this->home();

        $this->assertStringContainsString('href="/special"', $html);
        $this->assertStringNotContainsString('/collection/wedding', $html);
    }

    public function test_the_tile_recompiles_when_the_collection_is_renamed_gets_a_new_picture_or_is_deleted(): void
    {
        $other = $this->pickerAsset();
        $this->publish([$this->tile()]);
        $this->home();
        $collection = CollectionModel::query()->findOrFail(1);

        $collection->update(['name' => 'Weddings']);
        $this->recompiler()->flush();
        $this->assertSame(1, $this->recompiler()->runs, 'renamed');
        $this->assertStringContainsString('>Weddings<', $this->home());

        $collection->update(['picture_media_asset_id' => $other->id]);
        $this->recompiler()->flush();
        $this->assertSame(2, $this->recompiler()->runs, 'new picture');

        // the pivot rows go first (a collection that is in use cannot be deleted in the admin; here only the model event matters)
        DB::table('catalog_product_collections')->delete();
        $collection->delete();
        $this->recompiler()->flush();
        $this->assertSame(3, $this->recompiler()->runs, 'deleted');
        $this->assertStringNotContainsString('class="sf-hp-tile"', $this->home(), 'its item is gone');
    }

    public function test_the_switch_recompiles_only_a_layout_that_holds_a_collection_tile(): void
    {
        $this->publish([['type' => 'heading', 'settings' => ['text' => 'Hi']]]);
        $this->recompiler()->collectionsSwitch();
        $this->recompiler()->flush();
        $this->assertSame(0, $this->recompiler()->runs, 'no collection tile: no recompile');

        $this->publish([$this->tile()]);
        $this->recompiler()->collectionsSwitch();
        $this->recompiler()->flush();
        $this->assertSame(1, $this->recompiler()->runs);
    }

    // ---- ★ the editors offer it only while ON -------------------------------------------------------------------------------------------------

    public function test_the_offered_sources_follow_the_switch(): void
    {
        $this->assertContains('collection', HomeCatalog::offered(HomeCatalog::PRODUCT_SOURCES));
        $this->assertContains('collection', HomeCatalog::offered(HomeCatalog::TILE_SOURCES));

        $this->on(false);
        $this->assertNotContains('collection', HomeCatalog::offered(HomeCatalog::PRODUCT_SOURCES));
        $this->assertNotContains('collection', HomeCatalog::offered(HomeCatalog::TILE_SOURCES));
        $this->assertContains('collection', HomeCatalog::PRODUCT_SOURCES, 'the closed list itself keeps it, so stored data validates');
    }

    private function screen(string $node): Testable
    {
        $this->actingAsStaff('Administrator');

        return Livewire::test(DesignScreen::class)->call('selectZone', 'home')->call('addNode', $node);
    }

    public function test_the_grid_and_tile_editors_offer_the_collection_only_while_on(): void
    {
        // the tile editor builds its source select from the same HomeCatalog::offered() (tested above); a new tiles section has no tile row to render
        foreach (['product_grid'] as $node) {
            $this->on();
            $screen = $this->screen($node);
            $this->assertStringContainsString('Collection', $screen->html(), "{$node}: the source is offered while ON");

            $node === 'product_grid' && $this->assertStringContainsString('Wedding', $screen->set('props.settings.source', 'collection')->html(), 'the item select lists the collections');

            $this->on(false);
            $this->assertStringNotContainsString('Collection', $this->screen($node)->html(), "{$node}: not offered while OFF");
        }
    }
}
