<?php

namespace EasyCo\Order\Tests;

use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Checkout form v2, stage C1d-B (order-context-design.md 2.3 / 9.1, checkout-form-v2-design.md 6): what the customer ticked is a
 * create-time fact of the Order. NULL = not asked / placed before the field existed; the terms proof is both-or-neither; the consent
 * sentence is present exactly when the consent is TRUE.
 */
final class OrderCheckoutChoicesTest extends TestCase
{
    private function order(array $choices = []): Order
    {
        return Order::create(...array_merge([
            'clientId' => 'c', 'transactionId' => 't', 'email' => 'b@example.com', 'currency' => 'EUR', 'subtotal' => Money::fromMinorUnits(1000, 'EUR'),
            'discount' => Money::zero('EUR'), 'deliveryType' => OrderDeliveryType::STREET_ADDRESS, 'recipientFirstName' => 'Ivan', 'recipientLastName' => 'Ivanov',
            'phone' => '+359888123456', 'placedAt' => new DateTimeImmutable('2026-10-14 10:00:00'), 'country' => 'BG', 'city' => 'Sofia', 'addressLine1' => 'V 1',
        ], $choices));
    }

    public function test_an_order_placed_without_the_choices_has_none_not_false(): void
    {
        $order = $this->order();

        $this->assertSame([null, null, null, null, null, null], [
            $order->termsAcceptedAt(), $order->termsVersion(), $order->confirmationRequested(), $order->callBeforeShipping(), $order->marketingConsent(), $order->marketingConsentText(),
        ]);
    }

    public function test_the_choices_are_read_back_as_given_and_false_is_not_null(): void
    {
        $at = new DateTimeImmutable('2026-10-14 10:00:00');
        $order = $this->order([
            'termsAcceptedAt' => $at, 'termsVersion' => ' 2026-10-14 ', 'confirmationRequested' => false, 'callBeforeShipping' => false,
            'marketingConsent' => true, 'marketingConsentText' => 'Subscribe me',
        ]);

        $this->assertSame($at, $order->termsAcceptedAt());
        $this->assertSame('2026-10-14', $order->termsVersion(), 'trimmed');
        $this->assertFalse($order->confirmationRequested());
        $this->assertFalse($order->callBeforeShipping());
        $this->assertTrue($order->marketingConsent());
        $this->assertSame('Subscribe me', $order->marketingConsentText());
    }

    public function test_the_terms_proof_is_both_or_neither(): void
    {
        foreach ([['termsVersion' => '2026-10-14'], ['termsAcceptedAt' => new DateTimeImmutable()]] as $half) {
            try {
                $this->order($half);
                $this->fail('half a proof must be refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('together', $e->getMessage());
            }
        }
    }

    public function test_the_consent_sentence_exists_exactly_when_the_consent_is_true(): void
    {
        foreach ([
            ['marketingConsent' => true],
            ['marketingConsent' => false, 'marketingConsentText' => 'x'],
            ['marketingConsent' => null, 'marketingConsentText' => 'x'],
            ['marketingConsentText' => 'x'],
            ['marketingConsent' => true, 'marketingConsentText' => '  '],
            ['marketingConsent' => true, 'marketingConsentText' => str_repeat('я', 501)],
        ] as $bad) {
            try {
                $this->order($bad);
                $this->fail('refused: '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(str_repeat('я', 500), $this->order(['marketingConsent' => true, 'marketingConsentText' => str_repeat('я', 500)])->marketingConsentText());
    }

    public function test_a_version_is_1_to_32_characters(): void
    {
        $this->assertSame(str_repeat('v', 32), $this->order(['termsAcceptedAt' => new DateTimeImmutable(), 'termsVersion' => str_repeat('v', 32)])->termsVersion());

        $this->expectException(InvalidArgumentException::class);

        $this->order(['termsAcceptedAt' => new DateTimeImmutable(), 'termsVersion' => str_repeat('v', 33)]);
    }

    public function test_an_edit_of_the_delivery_never_touches_what_was_ticked(): void
    {
        $order = $this->order(['termsAcceptedAt' => new DateTimeImmutable('2026-10-14 10:00:00'), 'termsVersion' => 'v1', 'callBeforeShipping' => true, 'marketingConsent' => true, 'marketingConsentText' => 'Yes']);

        $order->reviseDelivery(OrderDeliveryType::STREET_ADDRESS, 'Petar', 'Petrov', '+359888999999', 'BG', 'Plovdiv', null, 'X 1', null, null, null, null);

        $this->assertSame(['v1', true, true, 'Yes'], [$order->termsVersion(), $order->callBeforeShipping(), $order->marketingConsent(), $order->marketingConsentText()]);
    }
}
