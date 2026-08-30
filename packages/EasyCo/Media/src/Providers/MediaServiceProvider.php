<?php

namespace EasyCo\Media\Providers;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Image\LaravelMediaImageProcessor;
use EasyCo\Media\Persistence\Eloquent\EloquentMediaAssetRepository;
use EasyCo\Media\Persistence\Eloquent\EloquentProductMediaRepository;
use EasyCo\Media\Persistence\Eloquent\EloquentVariationMediaRepository;
use EasyCo\Media\ProductMediaCountGuard;
use EasyCo\Media\Storage\LaravelMediaStorageAdapter;
use EasyCo\Media\VariationMediaCountGuard;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaAssetRepository::class, EloquentMediaAssetRepository::class);
        $this->app->bind(ProductMediaRepository::class, EloquentProductMediaRepository::class);
        $this->app->bind(VariationMediaRepository::class, EloquentVariationMediaRepository::class);

        $this->app->bind(ProductMediaCountGuard::class, function ($app) {
            return new ProductMediaCountGuard(
                $app->make(ProductMediaRepository::class),
                (int) config('services.media.max_photos_per_product', 10),
            );
        });

        $this->app->bind(VariationMediaCountGuard::class, function ($app) {
            return new VariationMediaCountGuard(
                $app->make(VariationMediaRepository::class),
                (int) config('services.media.max_photos_per_variation', 3),
            );
        });

        $this->app->bind(MediaStorageAdapter::class, function ($app) {
            return new LaravelMediaStorageAdapter(
                config('services.media.default_disk', 'public'),
            );
        });

        $this->app->bind(MediaImageProcessor::class, function ($app) {
            return new LaravelMediaImageProcessor(
                $app->make(MediaStorageAdapter::class),
                config('services.media.image_variants', []),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
