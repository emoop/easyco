<?php

namespace EasyCo\Staff\Providers;

use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Persistence\Eloquent\EloquentRoleRepository;
use EasyCo\Staff\Persistence\Eloquent\EloquentStaffRepository;
use EasyCo\Staff\Security\LaravelPasswordHasher;
use Illuminate\Support\ServiceProvider;

class StaffServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RoleRepository::class, EloquentRoleRepository::class);
        $this->app->bind(StaffRepository::class, EloquentStaffRepository::class);
        $this->app->bind(PasswordHasher::class, LaravelPasswordHasher::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
