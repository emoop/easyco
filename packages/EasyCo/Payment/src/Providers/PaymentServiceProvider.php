<?php

namespace EasyCo\Payment\Providers;

use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Persistence\Eloquent\EloquentPaymentRefundRepository;
use EasyCo\Payment\Persistence\Eloquent\EloquentPaymentRepository;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);
        $this->app->bind(PaymentRefundRepository::class, EloquentPaymentRefundRepository::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
