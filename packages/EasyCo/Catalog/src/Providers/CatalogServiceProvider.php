<?php

namespace EasyCo\Catalog\Providers;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\SkuSequenceRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentSkuSequenceRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentVariationRepository;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(VariationRepository::class, EloquentVariationRepository::class);
        $this->app->bind(SkuSequenceRepository::class, EloquentSkuSequenceRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
