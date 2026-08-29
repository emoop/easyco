<?php

namespace App\Providers;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\Persistence\Eloquent\EloquentSiteSettingsRepository;
use Illuminate\Support\ServiceProvider;

class SiteSettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SiteSettingsRepository::class, EloquentSiteSettingsRepository::class);
    }
}
