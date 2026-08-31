<?php

namespace EasyCo\Inventory\Providers;

use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\Persistence\Eloquent\EloquentStockLevelRepository;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StockLevelRepository::class, EloquentStockLevelRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
