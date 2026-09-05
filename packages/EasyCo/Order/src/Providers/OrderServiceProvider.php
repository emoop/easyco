<?php

namespace EasyCo\Order\Providers;

use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Persistence\Eloquent\EloquentOrderRepository;
use Illuminate\Support\ServiceProvider;

class OrderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrderRepository::class, EloquentOrderRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
