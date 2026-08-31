<?php

namespace EasyCo\Account\Providers;

use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher;
use EasyCo\Account\Persistence\Eloquent\EloquentAccountRepository;
use EasyCo\Account\Security\LaravelPasswordHasher;
use Illuminate\Support\ServiceProvider;

class AccountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountRepository::class, EloquentAccountRepository::class);
        $this->app->bind(PasswordHasher::class, LaravelPasswordHasher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
