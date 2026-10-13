<?php

namespace EasyCo\Payment\Tests;

use EasyCo\Payment\Registry\PaymentAvailabilityContext;
use EasyCo\Payment\Registry\PaymentFlow;
use EasyCo\Payment\Registry\PaymentMethodDescriptor;
use EasyCo\Payment\Registry\PaymentMethodRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Checkout form v2, stage C1d-B (checkout-form-v2-design.md section 8.2): the registry and its descriptor, framework-free. */
final class PaymentMethodRegistryTest extends TestCase
{
    private function descriptor(string $code, int $sort = 100, ?callable $availability = null): PaymentMethodDescriptor
    {
        return new PaymentMethodDescriptor(
            code: $code,
            label: fn (string $locale): string => $code.' ['.$locale.']',
            sortOrder: $sort,
            availability: $availability === null ? null : \Closure::fromCallable($availability),
        );
    }

    private function context(string $currency = 'EUR', int $total = 1000, ?string $kind = null): PaymentAvailabilityContext
    {
        return new PaymentAvailabilityContext('BG', $currency, $total, $kind);
    }

    public function test_methods_come_back_in_display_order_sort_order_then_code(): void
    {
        $registry = new PaymentMethodRegistry();
        $registry->register($this->descriptor('zeta', 10));
        $registry->register($this->descriptor('beta', 20));
        $registry->register($this->descriptor('alpha', 20));

        $this->assertSame(['zeta', 'alpha', 'beta'], array_map(fn ($d) => $d->code, $registry->all()));
    }

    public function test_a_code_registered_twice_is_a_programming_error_not_a_silent_replacement(): void
    {
        $registry = new PaymentMethodRegistry();
        $registry->register($this->descriptor('card'));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->descriptor('card'));
    }

    public function test_the_availability_function_decides_per_order(): void
    {
        $registry = new PaymentMethodRegistry();
        $registry->register($this->descriptor('always'));
        $registry->register($this->descriptor('eur_only', 5, fn (PaymentAvailabilityContext $c): bool => $c->currency === 'EUR'));
        $registry->register($this->descriptor('small', 6, fn (PaymentAvailabilityContext $c): bool => $c->totalMinor <= 5000));
        $registry->register($this->descriptor('not_lockers', 7, fn (PaymentAvailabilityContext $c): bool => $c->deliveryKind !== 'locker'));

        $codes = fn (PaymentAvailabilityContext $c): array => array_map(fn ($d) => $d->code, $registry->available($c));

        $this->assertSame(['eur_only', 'small', 'not_lockers', 'always'], $codes($this->context()));
        $this->assertSame(['small', 'not_lockers', 'always'], $codes($this->context('USD')));
        $this->assertSame(['eur_only', 'not_lockers', 'always'], $codes($this->context('EUR', 9999)));
        $this->assertSame(['eur_only', 'small', 'always'], $codes($this->context('EUR', 1000, 'locker')));
    }

    public function test_text_is_asked_per_locale_and_blank_optional_text_is_none(): void
    {
        $descriptor = new PaymentMethodDescriptor(
            code: 'card', label: fn (string $l): string => "Card {$l}", description: fn (string $l): ?string => '  ', placeOrderLabel: fn (string $l): ?string => "Pay {$l}",
            icon: 'https://example.com/card.svg', flow: PaymentFlow::REDIRECT,
        );

        $this->assertSame('Card bg', $descriptor->label('bg'));
        $this->assertNull($descriptor->description('bg'), 'a blank description is no description');
        $this->assertSame('Pay en', $descriptor->placeOrderLabel('en'));
        $this->assertSame(PaymentFlow::REDIRECT, $descriptor->flow);
        $this->assertTrue($descriptor->isAvailable($this->context()), 'no availability function = always available');
    }

    public function test_a_code_is_the_adapter_key_suffix_so_its_shape_is_fixed(): void
    {
        foreach (['', 'Card', '1card', 'card-x', 'card x', str_repeat('a', 65)] as $bad) {
            try {
                $this->descriptor($bad);
                $this->fail("'{$bad}' must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('card_2', $this->descriptor('card_2')->code);
    }
}
