<?php

namespace EasyCo\Promotions\Providers;

use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Persistence\Eloquent\EloquentPromotionRedemptionRepository;
use EasyCo\Promotions\Persistence\Eloquent\EloquentPromotionRepository;
use EasyCo\Promotions\Persistence\Eloquent\EloquentPromotionScopeRepository;
use Illuminate\Support\ServiceProvider;

class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PromotionRepository::class, EloquentPromotionRepository::class);
        $this->app->bind(PromotionScopeRepository::class, EloquentPromotionScopeRepository::class);
        $this->app->bind(PromotionRedemptionRepository::class, EloquentPromotionRedemptionRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
