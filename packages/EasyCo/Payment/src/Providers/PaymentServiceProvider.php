<?php

namespace EasyCo\Payment\Providers;

use EasyCo\Payment\Adapters\BankTransferPaymentMethodAdapter;
use EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter;
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

        /**
         * Named bindings, not the PaymentMethodAdapter interface itself
         * — there are two concrete implementations, not one, so there
         * is no single "the" adapter to bind the interface to. Picking
         * an adapter by Payment::method() is a decision for whoever
         * writes the actual orchestration layer later; no registry/
         * factory is invented here beyond these two container keys.
         */
        $this->app->bind('payment.adapter.cash_on_delivery', CashOnDeliveryPaymentMethodAdapter::class);
        $this->app->bind('payment.adapter.bank_transfer', BankTransferPaymentMethodAdapter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
