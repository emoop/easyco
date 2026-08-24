<?php

namespace EasyCo\Extensibility\Providers;

use EasyCo\Extensibility\HookRegistry;
use Illuminate\Support\ServiceProvider;

class HookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HookRegistry::class);
    }

    public function boot(): void
    {
        //
    }
}
