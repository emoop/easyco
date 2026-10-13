<?php

namespace EasyCo\Payment\Providers;

use EasyCo\Payment\Adapters\BankTransferPaymentMethodAdapter;
use EasyCo\Payment\Adapters\CashOnDeliveryPaymentMethodAdapter;
use EasyCo\Payment\Contracts\PaymentReceiptRepository;
use EasyCo\Payment\Contracts\PaymentRefundRepository;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Persistence\Eloquent\EloquentPaymentReceiptRepository;
use EasyCo\Payment\Persistence\Eloquent\EloquentPaymentRefundRepository;
use EasyCo\Payment\Persistence\Eloquent\EloquentPaymentRepository;
use EasyCo\Payment\Registry\PaymentFlow;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentRepository::class, EloquentPaymentRepository::class);
        $this->app->bind(PaymentRefundRepository::class, EloquentPaymentRefundRepository::class);
        $this->app->bind(PaymentReceiptRepository::class, EloquentPaymentReceiptRepository::class);

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

        /**
         * THE REGISTRY of what the checkout offers (checkout-form-v2-design.md section 8.2). Each payment method registers its
         * DESCRIPTOR here, next to its adapter binding above; an extension does the same from its own provider and no core file
         * lists it. The closures are evaluated lazily, per locale, so translation is not touched while the application boots.
         * The labels are the storefront's existing `payment_methods.*` strings (the order-received page reads the same ones).
         */
        $this->app->singleton(PaymentMethodRegistry::class, function (): PaymentMethodRegistry {
            $registry = new PaymentMethodRegistry();

            $registry->register(new PaymentMethodDescriptor(
                code: 'cash_on_delivery',
                label: static fn (string $locale): string => (string) __('storefront.payment_methods.cash_on_delivery', [], $locale),
                description: static fn (string $locale): ?string => (string) __('storefront.payment_descriptions.cash_on_delivery', [], $locale),
                flow: PaymentFlow::OFFLINE,
                sortOrder: 10,
            ));
            $registry->register(new PaymentMethodDescriptor(
                code: 'bank_transfer',
                label: static fn (string $locale): string => (string) __('storefront.payment_methods.bank_transfer', [], $locale),
                description: static fn (string $locale): ?string => (string) __('storefront.payment_descriptions.bank_transfer', [], $locale),
                flow: PaymentFlow::OFFLINE,
                sortOrder: 20,
            ));

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
