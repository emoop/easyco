<?php

namespace EasyCo\Payment\Registry;

use Closure;
use InvalidArgumentException;

/**
 * What the checkout needs to KNOW about a payment method, registered beside its adapter (checkout-form-v2-design.md section 8.2). An
 * extension that brings a payment method registers one of these from its own service provider; no core file lists it.
 *
 * Everything a person reads (label, description, the order button's label) is a closure of the locale, so a store in any language
 * works; NOTHING here is ever rendered as HTML (the page escapes it). `icon` is the URL of a logo (an <img>), `availability` says
 * "not for this order" (null = always available). The code is the Payment's own method string and the suffix of the adapter's
 * container key `payment.adapter.<code>`.
 */
final class PaymentMethodDescriptor
{
    /** @var Closure(string): string */
    private readonly Closure $label;

    /**
     * @param  Closure(string): string  $label
     * @param  (Closure(string): ?string)|null  $description  one line under the label
     * @param  (Closure(string): ?string)|null  $placeOrderLabel  replaces the order button's label while the method is chosen; it must keep the obligation wording (section 7)
     * @param  (Closure(PaymentAvailabilityContext): bool)|null  $availability
     * @param  bool  $blockable  an order paid this way can be STOPPED by a block on the customer's IP, phone or email (cod-protection-design.md section 4): true for cash on delivery, false for a method captured up front (bank transfer, a card). An extension decides for its own method; the default is NOT blockable.
     */
    public function __construct(
        public readonly string $code,
        Closure $label,
        private readonly ?Closure $description = null,
        public readonly ?string $icon = null,
        public readonly PaymentFlow $flow = PaymentFlow::OFFLINE,
        private readonly ?Closure $placeOrderLabel = null,
        public readonly int $sortOrder = 100,
        private readonly ?Closure $availability = null,
        public readonly bool $blockable = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A payment method code is lower-case letters, digits and underscores, starting with a letter, at most 64 characters.');
        }

        $this->label = $label;
    }

    public function label(string $locale): string
    {
        return ($this->label)($locale);
    }

    public function description(string $locale): ?string
    {
        $text = $this->description === null ? null : ($this->description)($locale);

        return $text === null || trim($text) === '' ? null : $text;
    }

    public function placeOrderLabel(string $locale): ?string
    {
        $text = $this->placeOrderLabel === null ? null : ($this->placeOrderLabel)($locale);

        return $text === null || trim($text) === '' ? null : $text;
    }

    public function isAvailable(PaymentAvailabilityContext $context): bool
    {
        return $this->availability === null || (bool) ($this->availability)($context);
    }
}
