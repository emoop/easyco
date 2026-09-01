<?php

namespace EasyCo\Catalog\Providers;

use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\SkuSequenceRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentAttributeDefinitionRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentAttributeValueRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentBrandRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentCategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductCategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductTagRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentSkuSequenceRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentTagRepository;
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
        $this->app->bind(BrandRepository::class, EloquentBrandRepository::class);
        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
        $this->app->bind(TagRepository::class, EloquentTagRepository::class);
        $this->app->bind(ProductCategoryRepository::class, EloquentProductCategoryRepository::class);
        $this->app->bind(ProductTagRepository::class, EloquentProductTagRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
