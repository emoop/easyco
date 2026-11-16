<?php

namespace Tests\Feature\Storefront\Cache;

use App\Content\Models\PageModel;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;

/**
 * P8b: a small shop for the cache-contract tests. Categories 1 `clothing` > 2 `dresses`; brand 1 `acme`; tag 1 `new`; season 1 `summer`; collection 1 `col-one` (the
 * switch is on); products 1 (dresses, acme, new, summer, col-one), 2 (dresses), 3 (clothing); an info page `about`.
 */
trait BuildsCacheShop
{
    use BuildsStorefrontCatalog;

    protected function shop(): void
    {
        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        config(['app.url' => 'https://shop.test', 'app.name' => 'Raf Shop', 'easyco.noindex' => false, 'storefront.search.enabled' => true]);
        $this->fakePublicDiskWithUrl('https://cdn.test/storage');
        $this->withoutVite();
        $this->seedPricing();
        $this->showSoldOutProducts();

        $this->makeCategory(1, 'Clothing', 'clothing');
        $this->makeCategory(2, 'Dresses', 'dresses', 1);
        $this->makeBrand(1, 'Acme', 'acme');
        $this->makeTag(1, 'New', 'new');
        DB::table('catalog_seasons')->insert(['id' => 1, 'name' => 'Summer', 'slug' => 'summer', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('catalog.collections_enabled', '1');
        DB::table('catalog_collections')->insert(['id' => 1, 'name' => 'Col One', 'slug' => 'col-one', 'created_at' => self::STAMP, 'updated_at' => self::STAMP]);

        $this->makeProduct(1, ['name' => 'Alpha', 'slug' => 'alpha', 'categories' => [2], 'brand' => 1, 'tags' => [1], 'price' => '10.00', 'stock' => 5]);
        $this->makeProduct(2, ['name' => 'Beta', 'slug' => 'beta', 'categories' => [2], 'price' => '12.00', 'stock' => 5]);
        $this->makeProduct(3, ['name' => 'Gamma', 'slug' => 'gamma', 'categories' => [1], 'price' => '14.00', 'stock' => 5]);
        DB::table('catalog_products')->where('id', 1)->update(['season_id' => 1]);
        DB::table('catalog_product_collections')->insert(['product_id' => 1, 'collection_id' => 1]);

        PageModel::query()->create(['slug' => 'about', 'title' => 'About', 'body' => '<p>About</p>', 'status' => 'published', 'revision' => 1]);
        $this->app->forgetScopedInstances();
    }

    /** A fresh request-scoped state, as a new request has it. */
    protected function fresh(): void
    {
        $this->app->forgetScopedInstances();
    }

    /** @return list<string> the keys of the `xkey` header of a GET */
    protected function xkeys(string $url): array
    {
        $this->fresh();
        $response = $this->get($url);

        return array_values(array_filter(explode(' ', (string) $response->headers->get('xkey'))));
    }
}
