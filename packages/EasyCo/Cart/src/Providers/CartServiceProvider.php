<?php

namespace EasyCo\Cart\Providers;

use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Persistence\Eloquent\EloquentCartRepository;
use Illuminate\Support\ServiceProvider;

class CartServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CartRepository::class, EloquentCartRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
