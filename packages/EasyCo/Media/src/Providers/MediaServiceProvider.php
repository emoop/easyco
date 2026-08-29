<?php

namespace EasyCo\Media\Providers;

use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Persistence\Eloquent\EloquentMediaAssetRepository;
use EasyCo\Media\Persistence\Eloquent\EloquentProductMediaRepository;
use EasyCo\Media\Persistence\Eloquent\EloquentVariationMediaRepository;
use Illuminate\Support\ServiceProvider;

class MediaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MediaAssetRepository::class, EloquentMediaAssetRepository::class);
        $this->app->bind(ProductMediaRepository::class, EloquentProductMediaRepository::class);
        $this->app->bind(VariationMediaRepository::class, EloquentVariationMediaRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
