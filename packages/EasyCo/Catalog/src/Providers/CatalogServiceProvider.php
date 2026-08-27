<?php

namespace EasyCo\Catalog\Providers;

use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\SkuSequenceRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentAttributeDefinitionRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentAttributeValueRepository;
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
        $this->app->bind(AttributeDefinitionRepository::class, EloquentAttributeDefinitionRepository::class);
        $this->app->bind(AttributeValueRepository::class, EloquentAttributeValueRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
