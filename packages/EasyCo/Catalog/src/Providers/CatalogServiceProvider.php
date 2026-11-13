<?php

namespace EasyCo\Catalog\Providers;

use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductGroupRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\CollectionRepository;
use EasyCo\Catalog\Contracts\ProductCollectionRepository;
use EasyCo\Catalog\Contracts\ProductTagRepository;
use EasyCo\Catalog\Contracts\ProductTemplateRepository;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Contracts\SkuSequenceRepository;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentAttributeDefinitionRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentAttributeValueRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentBrandRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentCategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductCategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductGroupRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentCollectionRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductCollectionRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductTagRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductTemplateRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentSeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentSkuSequenceRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentTagRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentVariationRepository;
use EasyCo\Catalog\Contracts\MeasureRepository;
use EasyCo\Catalog\Contracts\MeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\MeasurementValueRepository;
use EasyCo\Catalog\Contracts\ProductMeasurementTemplateRepository;
use EasyCo\Catalog\Contracts\SizeAxisResolver;
use EasyCo\Catalog\Persistence\Eloquent\EloquentMeasureRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentMeasurementTemplateRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentMeasurementValueRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentProductMeasurementTemplateRepository;
use EasyCo\Catalog\Persistence\Eloquent\EloquentSizeAxisResolver;
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
        $this->app->bind(SeasonRepository::class, EloquentSeasonRepository::class);
        $this->app->bind(CategoryRepository::class, EloquentCategoryRepository::class);
        $this->app->bind(TagRepository::class, EloquentTagRepository::class);
        $this->app->bind(ProductCategoryRepository::class, EloquentProductCategoryRepository::class);
        $this->app->bind(ProductTagRepository::class, EloquentProductTagRepository::class);
        $this->app->bind(CollectionRepository::class, EloquentCollectionRepository::class);
        $this->app->bind(ProductCollectionRepository::class, EloquentProductCollectionRepository::class);
        $this->app->bind(ProductGroupRepository::class, EloquentProductGroupRepository::class);
        $this->app->bind(ProductTemplateRepository::class, EloquentProductTemplateRepository::class);
        $this->app->bind(MeasureRepository::class, EloquentMeasureRepository::class);
        $this->app->bind(MeasurementTemplateRepository::class, EloquentMeasurementTemplateRepository::class);
        $this->app->bind(ProductMeasurementTemplateRepository::class, EloquentProductMeasurementTemplateRepository::class);
        $this->app->bind(MeasurementValueRepository::class, EloquentMeasurementValueRepository::class);
        $this->app->bind(SizeAxisResolver::class, EloquentSizeAxisResolver::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
