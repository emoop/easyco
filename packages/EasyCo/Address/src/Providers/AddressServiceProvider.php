<?php

namespace EasyCo\Address\Providers;

use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Persistence\Eloquent\EloquentAddressRepository;
use Illuminate\Support\ServiceProvider;

class AddressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AddressRepository::class, EloquentAddressRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
