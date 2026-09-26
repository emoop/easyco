<?php

namespace Tests\Feature;

use App\Services\Exceptions\UnknownPaymentMethodException;
use App\Services\PaymentMethodAdapterResolver;
use EasyCo\Payment\Contracts\PaymentMethodAdapter;
use Tests\TestCase;

/**
 * availableMethods() is the ONE place the accepted payment-method codes are
 * declared, and it is only useful if it cannot drift away from what the resolver
 * can actually resolve. This test pins it in BOTH directions:
 *
 * - every code it declares must resolve to a real adapter (so a code left behind
 *   after an adapter is removed fails here rather than on a customer's checkout);
 * - every adapter PaymentServiceProvider binds as 'payment.adapter.<code>' must
 *   appear in the list (so adding a method without declaring it fails here rather
 *   than silently missing from the checkout page).
 *
 * The second direction DOES read the container's bindings — deliberately, and
 * only here: the production path must never derive the list by scanning keys this
 * class does not own (see availableMethods()'s own docblock), but a test is
 * exactly the place where "the declared list and the wired reality agree" should
 * be checked mechanically instead of trusted.
 *
 * No database and no RefreshDatabase: nothing here reads or writes a row.
 */
final class PaymentMethodAdapterResolverTest extends TestCase
{
    private const ADAPTER_BINDING_PREFIX = 'payment.adapter.';

    private function boundMethods(): array
    {
        $methods = [];

        foreach (array_keys(app()->getBindings()) as $key) {
            if (is_string($key) && str_starts_with($key, self::ADAPTER_BINDING_PREFIX)) {
                $methods[] = substr($key, strlen(self::ADAPTER_BINDING_PREFIX));
            }
        }

        sort($methods);

        return $methods;
    }

    public function test_every_declared_method_resolves_to_a_real_adapter(): void
    {
        $resolver = app(PaymentMethodAdapterResolver::class);
        $declared = $resolver->availableMethods();

        $this->assertNotSame([], $declared, 'the storefront must offer at least one payment method');
        $this->assertSame($declared, array_values(array_unique($declared)), 'the list must not repeat a method');
        $this->assertTrue(array_is_list($declared), 'the list must be a list, not a map');

        foreach ($declared as $method) {
            $this->assertInstanceOf(
                PaymentMethodAdapter::class,
                $resolver->resolve($method),
                "availableMethods() declares \"{$method}\", which does not resolve to an adapter"
            );
        }
    }

    public function test_every_bound_adapter_is_declared(): void
    {
        $bound = $this->boundMethods();
        $declared = app(PaymentMethodAdapterResolver::class)->availableMethods();
        sort($declared);

        $this->assertSame(
            $bound,
            $declared,
            'the declared list and the adapters PaymentServiceProvider binds must be the same set'
        );
    }

    public function test_an_unknown_method_is_still_rejected(): void
    {
        $this->expectException(UnknownPaymentMethodException::class);

        app(PaymentMethodAdapterResolver::class)->resolve('not_a_real_method');
    }
}
