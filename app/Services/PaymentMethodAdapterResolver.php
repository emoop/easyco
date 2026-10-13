<?php

namespace App\Services;

use App\Services\Exceptions\UnknownPaymentMethodException;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
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
        private readonly PaymentMethodRegistry $registry,
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
     * THE REGISTRY IS THE ONE PLACE THOSE CODES ARE DECLARED (checkout form v2, C1d-B): every payment
     * method — the two built-in ones and any extension's — registers a PaymentMethodDescriptor from its own
     * service provider, next to its 'payment.adapter.<method>' binding, and this answers from it (display
     * order). Nothing here is hard-coded. Deriving the list by scanning the container's bindings would make
     * the answer depend on keys this class does not own, so the registry is the list, and
     * tests/Feature/PaymentMethodAdapterResolverTest.php keeps it honest in both directions: every
     * descriptor has an adapter and every adapter has a descriptor.
     *
     * This is the list of ACCEPTED codes, availability for a given order NOT applied: the page and the
     * checkout ask the registry with their own PaymentAvailabilityContext.
     *
     * @return list<string>
     */
    public function availableMethods(): array
    {
        return array_map(static fn ($descriptor): string => $descriptor->code, $this->registry->all());
    }
}
