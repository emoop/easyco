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

    /**
     * Every payment method this application accepts, in display order.
     *
     * THE ONE PLACE THOSE CODES ARE DECLARED. PaymentServiceProvider binds each one
     * as 'payment.adapter.<method>' but publishes no list of them, and deriving the
     * list by scanning the container's own bindings would make this answer depend on
     * binding order and on keys this class does not own — so the list is written out
     * here, where a human can read and change it, and
     * tests/Feature/PaymentMethodAdapterResolverTest.php keeps it honest in both
     * directions: every entry here must still resolve, and every adapter the
     * provider binds must appear here.
     *
     * @return list<string>
     */
    public function availableMethods(): array
    {
        return ['cash_on_delivery', 'bank_transfer'];
    }
}
