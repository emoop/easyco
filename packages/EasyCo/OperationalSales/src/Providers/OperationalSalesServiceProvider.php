<?php

namespace EasyCo\OperationalSales\Providers;

use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\InstallmentPlanRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Persistence\Eloquent\EloquentClientRepository;
use EasyCo\OperationalSales\Persistence\Eloquent\EloquentInstallmentPlanRepository;
use EasyCo\OperationalSales\Persistence\Eloquent\EloquentTransactionRepository;
use Illuminate\Support\ServiceProvider;

class OperationalSalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ClientRepository::class, EloquentClientRepository::class);
        $this->app->bind(TransactionRepository::class, EloquentTransactionRepository::class);
        $this->app->bind(InstallmentPlanRepository::class, EloquentInstallmentPlanRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
