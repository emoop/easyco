<?php

namespace App\Services;

use App\Services\Exceptions\UnknownPaymentMethodException;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves a PaymentMethodAdapter by Payment's own method string.
 *
 * EasyCo\Payment\Providers\PaymentServiceProvider binds each adapter as
 * a NAMED container key ('payment.adapter.cash_on_delivery',
 * 'payment.adapter.bank_transfer'), deliberately not the
 * PaymentMethodAdapter interface itself — its own docblock explains why:
 * there are two concrete implementations, not one, so there is no single
 * "the" adapter to bind the interface to. Picking an adapter by method is
 * exactly the "orchestration layer" decision that provider's docblock
 * left open — this class is that decision, and nothing more.
 */
final class PaymentMethodAdapterResolver
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /** @throws UnknownPaymentMethodException */
    public function resolve(string $method): PaymentMethodAdapter
    {
        $key = 'payment.adapter.'.$method;

        if (! $this->container->bound($key)) {
            throw new UnknownPaymentMethodException($method);
        }

        return $this->container->make($key);
    }
}
