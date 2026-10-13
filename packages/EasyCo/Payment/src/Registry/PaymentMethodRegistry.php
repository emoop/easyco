<?php

namespace EasyCo\Payment\Registry;

use InvalidArgumentException;

/**
 * THE ONE LIST of the payment methods this application offers (checkout-form-v2-design.md section 8.2). The two built-in methods and
 * every extension register a PaymentMethodDescriptor from their own service provider; the checkout page and the API ask this class,
 * never a hard-coded list.
 */
final class PaymentMethodRegistry
{
    /** @var array<string, PaymentMethodDescriptor> */
    private array $descriptors = [];

    /** @throws InvalidArgumentException A code registered twice is a programming error, never silently replaced. */
    public function register(PaymentMethodDescriptor $descriptor): void
    {
        if (isset($this->descriptors[$descriptor->code])) {
            throw new InvalidArgumentException("The payment method \"{$descriptor->code}\" is already registered.");
        }

        $this->descriptors[$descriptor->code] = $descriptor;
    }

    public function find(string $code): ?PaymentMethodDescriptor
    {
        return $this->descriptors[$code] ?? null;
    }

    /**
     * Every registered descriptor, in display order (sortOrder, then code).
     *
     * @return list<PaymentMethodDescriptor>
     */
    public function all(): array
    {
        $list = array_values($this->descriptors);
        usort($list, static fn (PaymentMethodDescriptor $a, PaymentMethodDescriptor $b): int => [$a->sortOrder, $a->code] <=> [$b->sortOrder, $b->code]);

        return $list;
    }

    /**
     * The descriptors available for this order or page, in display order.
     *
     * @return list<PaymentMethodDescriptor>
     */
    public function available(PaymentAvailabilityContext $context): array
    {
        return array_values(array_filter($this->all(), static fn (PaymentMethodDescriptor $d): bool => $d->isAvailable($context)));
    }
}
