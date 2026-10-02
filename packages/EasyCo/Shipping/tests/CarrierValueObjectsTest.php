<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Carrier\CallBudget;
use EasyCo\Shipping\Carrier\PickupPoint;
use EasyCo\Shipping\Carrier\ShipmentLabel;
use EasyCo\Shipping\Carrier\ShipmentRequest;
use EasyCo\Shipping\Carrier\ShippingContext;
use EasyCo\Shipping\Carrier\ShippingQuote;
use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;
use PHPUnit\Framework\TestCase;

/** The §6 value objects: validated at construction, nothing normalized, no customer data in a message. */
final class CarrierValueObjectsTest extends TestCase
{
    private function context(array $override = []): ShippingContext
    {
        return new ShippingContext(...array_merge([
            'countryCode' => 'BG', 'settlement' => 'Sofia', 'isPickupPoint' => false, 'currency' => 'EUR', 'goodsValueMinor' => 5000,
        ], $override));
    }

    private function request(array $override = []): ShipmentRequest
    {
        return new ShipmentRequest(...array_merge([
            'orderId' => '42', 'attempt' => 1, 'carrierCode' => 'econt', 'serviceCode' => 'office', 'recipientName' => 'Ivan Ivanov',
            'recipientPhone' => '+359888123456', 'countryCode' => 'BG', 'settlement' => 'Sofia', 'currency' => 'EUR', 'goodsValueMinor' => 5000,
            'addressLine' => 'Vitosha Blvd 1',
        ], $override));
    }

    private function point(array $override = []): PickupPoint
    {
        return new PickupPoint(...array_merge([
            'carrierCode' => 'econt', 'reference' => 'E-123', 'name' => 'Econt Lozenets', 'countryCode' => 'BG', 'settlement' => 'Sofia', 'addressLine' => 'Bul. Bulgaria 10',
        ], $override));
    }

    // --- ShippingContext --------------------------------------------------------------------------------------------

    public function test_a_context_holds_what_a_carrier_needs_and_no_customer_identity_or_cost(): void
    {
        $context = $this->context(['cashOnDeliveryMinor' => 5000, 'weightGrams' => 1200, 'lengthMm' => 300, 'widthMm' => 200, 'heightMm' => 100]);

        $this->assertTrue($context->isCashOnDelivery());
        $this->assertTrue($context->hasDimensions());

        $fields = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(ShippingContext::class))->getProperties());
        foreach (['name', 'phone', 'email', 'street', 'account', 'client', 'cost', 'unitCost', 'margin'] as $forbidden) {
            foreach ($fields as $field) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $field, "ShippingContext must not carry \"{$forbidden}\"");
            }
        }
    }

    public function test_unknown_weight_and_dimensions_are_null_not_zero_and_prepaid_has_no_cod_amount(): void
    {
        $context = $this->context();

        $this->assertNull($context->weightGrams);
        $this->assertFalse($context->hasDimensions());
        $this->assertFalse($context->isCashOnDelivery());
    }

    public function test_a_zero_goods_value_is_valid_a_fully_discounted_cart(): void
    {
        $this->assertSame(0, $this->context(['goodsValueMinor' => 0])->goodsValueMinor);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badContexts(): array
    {
        return [
            'negative goods value' => [['goodsValueMinor' => -1], 'goodsValueMinor'],
            'negative cod' => [['cashOnDeliveryMinor' => -1], 'cashOnDeliveryMinor'],
            'negative weight' => [['weightGrams' => -5], 'weightGrams'],
            'malformed currency (lowercase)' => [['currency' => 'eur'], 'currency'],
            'malformed currency (two letters)' => [['currency' => 'EU'], 'currency'],
            'country not alpha-2' => [['countryCode' => 'BGR'], 'countryCode'],
            'country lowercase' => [['countryCode' => 'bg'], 'countryCode'],
            'empty settlement' => [['settlement' => '  '], 'settlement'],
            'partial dimensions' => [['lengthMm' => 100, 'widthMm' => 100], 'lengthMm/widthMm/heightMm'],
            'negative dimension' => [['lengthMm' => -1, 'widthMm' => 1, 'heightMm' => 1], 'lengthMm'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badContexts')]
    public function test_a_malformed_context_is_refused(array $override, string $field): void
    {
        try {
            $this->context($override);
            $this->fail('expected a refusal');
        } catch (InvalidCarrierDataException $e) {
            $this->assertStringContainsString($field, $e->getMessage());
        }
    }

    public function test_the_canonical_array_is_stable_and_complete(): void
    {
        $a = $this->context(['weightGrams' => 10])->toCanonicalArray();
        $b = $this->context(['weightGrams' => 10])->toCanonicalArray();

        $this->assertSame($a, $b);
        $this->assertSame(['country', 'settlement', 'pickup', 'currency', 'goods', 'cod', 'weight', 'length', 'width', 'height'], array_keys($a));
        $this->assertNotSame($a, $this->context(['weightGrams' => 11])->toCanonicalArray());
    }

    // --- ShippingQuote ---------------------------------------------------------------------------------------------

    public function test_a_quote_may_be_free_but_never_negative_and_needs_a_valid_currency(): void
    {
        $this->assertSame(0, (new ShippingQuote('office', 'To office', 0, 'EUR'))->amountMinor);

        foreach ([[-1, 'EUR'], [100, 'euro'], [100, 'eur']] as [$amount, $currency]) {
            try {
                new ShippingQuote('office', 'To office', $amount, $currency);
                $this->fail('expected a refusal');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- PickupPoint -----------------------------------------------------------------------------------------------

    public function test_a_pickup_point_carries_its_own_country(): void
    {
        $this->assertSame('BG', $this->point()->countryCode);
    }

    public function test_a_pickup_point_without_a_country_is_refused(): void
    {
        foreach (['', '  ', 'bg', 'BGR', 'B1'] as $bad) {
            try {
                $this->point(['countryCode' => $bad]);
                $this->fail("a pickup point with country \"{$bad}\" must be refused");
            } catch (InvalidCarrierDataException $e) {
                $this->assertStringContainsString('countryCode', $e->getMessage());
            }
        }
    }

    public function test_a_pickup_point_needs_a_valid_carrier_code_and_the_other_fields(): void
    {
        foreach (['carrierCode' => 'Econt', 'reference' => '', 'name' => ' ', 'settlement' => '', 'addressLine' => ''] as $field => $bad) {
            try {
                $this->point([$field => $bad]);
                $this->fail("{$field} must be refused");
            } catch (InvalidCarrierDataException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
    }

    // --- ShipmentRequest -------------------------------------------------------------------------------------------

    public function test_a_shipment_request_carries_its_idempotency_reference_from_order_and_attempt(): void
    {
        $this->assertSame('42#1', $this->request()->idempotencyReference);
        $this->assertSame('42#2', $this->request(['attempt' => 2])->idempotencyReference);
        $this->assertSame($this->request()->idempotencyReference, $this->request()->idempotencyReference, 'the same order and attempt always give the same reference');
    }

    public function test_a_shipment_request_cannot_be_built_without_what_makes_its_reference(): void
    {
        foreach ([['orderId' => ''], ['orderId' => '  '], ['attempt' => 0], ['attempt' => -1]] as $override) {
            try {
                $this->request($override);
                $this->fail('a request without a valid order id and attempt must be refused');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_shipment_request_goes_to_an_address_or_to_an_office_never_both_or_neither(): void
    {
        $this->assertFalse($this->request()->isPickupPoint());
        $this->assertTrue($this->request(['addressLine' => null, 'pickupPointReference' => 'E-123'])->isPickupPoint());

        foreach ([['addressLine' => null], ['addressLine' => 'Street 1', 'pickupPointReference' => 'E-123']] as $override) {
            try {
                $this->request($override);
                $this->fail('expected a refusal');
            } catch (InvalidCarrierDataException $e) {
                $this->assertStringContainsString('addressLine/pickupPointReference', $e->getMessage());
            }
        }
    }

    public function test_a_shipment_request_validates_its_amounts_codes_and_never_echoes_the_recipient_in_a_message(): void
    {
        foreach ([['goodsValueMinor' => -1], ['cashOnDeliveryMinor' => -1], ['currency' => 'eur'], ['countryCode' => 'bg'], ['carrierCode' => 'Econt']] as $override) {
            try {
                $this->request($override);
                $this->fail('expected a refusal');
            } catch (InvalidCarrierDataException $e) {
                $this->assertStringNotContainsString('Ivan', $e->getMessage());
                $this->assertStringNotContainsString('Vitosha', $e->getMessage());
            }
        }
    }

    // --- ShipmentLabel / CallBudget ---------------------------------------------------------------------------------

    public function test_a_label_needs_a_tracking_number_and_a_sane_url(): void
    {
        $label = new ShipmentLabel('econt', '42#1', 'TRK-1', 'https://example.test/label.pdf');
        $this->assertSame('TRK-1', $label->trackingNumber);

        foreach ([['econt', '42#1', ''], ['econt', '', 'TRK'], ['econt', '42#1', 'TRK', 'ftp://x/y'], ['econt', '42#1', 'TRK', null, '']] as $args) {
            try {
                new ShipmentLabel(...$args);
                $this->fail('expected a refusal');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_call_budget_is_a_positive_bounded_number_of_milliseconds(): void
    {
        $this->assertSame(1500, CallBudget::milliseconds(1500)->inMilliseconds());
        $this->assertSame(1.5, CallBudget::milliseconds(1500)->inSeconds());

        foreach ([0, -5, CallBudget::MAX_MILLISECONDS + 1] as $bad) {
            try {
                CallBudget::milliseconds($bad);
                $this->fail('expected a refusal');
            } catch (InvalidCarrierDataException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
