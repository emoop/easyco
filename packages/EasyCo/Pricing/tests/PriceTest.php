<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase
{
    // --- Tax-exclusive prices --------------------------------------------

    public function test_exclusive_price_net_equals_input(): void
    {
        $price = Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000);

        $this->assertSame(1999, $price->net()->minorValue());
    }

    public function test_exclusive_price_gross_at_20_percent(): void
    {
        // 19.99 net + 20% => 23.99 gross (2399 minor units)
        $price = Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000);

        $this->assertSame(2399, $price->gross()->minorValue());
    }

    public function test_exclusive_price_at_9_percent(): void
    {
        $price = Price::exclusiveOfTax(Money::fromDecimal('100.00', 'EUR'), 900);

        $this->assertSame(10900, $price->gross()->minorValue());
    }

    public function test_exclusive_price_at_zero_percent(): void
    {
        $price = Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 0);

        $this->assertSame($price->net()->minorValue(), $price->gross()->minorValue());
        $this->assertTrue($price->tax()->isZero());
    }

    // --- Tax-inclusive prices --------------------------------------------

    public function test_inclusive_price_gross_equals_input(): void
    {
        $price = Price::inclusiveOfTax(Money::fromDecimal('23.99', 'EUR'), 2000);

        $this->assertSame(2399, $price->gross()->minorValue());
    }

    public function test_inclusive_price_net_at_20_percent(): void
    {
        // 23.99 gross at 20% VAT => net ~= 19.99
        $price = Price::inclusiveOfTax(Money::fromDecimal('23.99', 'EUR'), 2000);

        $this->assertSame(1999, $price->net()->minorValue());
    }

    // --- net + tax === gross identity, across rates and amounts ------------

    #[DataProvider('netTaxGrossCases')]
    public function test_net_plus_tax_always_equals_gross(string $amount, int $basisPoints, bool $inclusive): void
    {
        $money = Money::fromDecimal($amount, 'EUR');
        $price = $inclusive
            ? Price::inclusiveOfTax($money, $basisPoints)
            : Price::exclusiveOfTax($money, $basisPoints);

        $sum = $price->net()->add($price->tax());

        $this->assertSame($price->gross()->minorValue(), $sum->minorValue());
    }

    public static function netTaxGrossCases(): array
    {
        $amounts = ['0.01', '0.10', '19.99', '100.00', '33.33', '1.00'];
        $rates = [0, 900, 2000]; // 0%, 9%, 20%
        $cases = [];

        foreach ($amounts as $amount) {
            foreach ($rates as $rate) {
                $cases["{$amount} EUR at {$rate}bp exclusive"] = [$amount, $rate, false];
                $cases["{$amount} EUR at {$rate}bp inclusive"] = [$amount, $rate, true];
            }
        }

        return $cases;
    }

    // --- Rate representation ------------------------------------------------

    public function test_tax_rate_percent_is_display_convenience(): void
    {
        $price = Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 2000);

        $this->assertSame(20.0, $price->taxRatePercent());
        $this->assertSame(2000, $price->taxRateBasisPoints());
    }

    public function test_rejects_negative_tax_rate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), -100);
    }

    // --- Currency delegation -------------------------------------------

    public function test_currency_delegates_to_money(): void
    {
        $price = Price::exclusiveOfTax(Money::fromDecimal('10.00', 'USD'), 0);

        $this->assertSame('USD', $price->currency()->code());
    }

    // --- isTaxInclusive flag -------------------------------------------

    public function test_is_tax_inclusive_reflects_construction(): void
    {
        $this->assertTrue(Price::inclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)->isTaxInclusive());
        $this->assertFalse(Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)->isTaxInclusive());
    }

    // --- Equality -----------------------------------------------------

    public function test_equals_true_for_matching_prices(): void
    {
        $a = Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 2000);
        $b = Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 2000);

        $this->assertTrue($a->equals($b));
    }

    public function test_equals_false_when_tax_treatment_differs(): void
    {
        $a = Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 2000);
        $b = Price::inclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 2000);

        $this->assertFalse($a->equals($b));
    }

    // --- Immutability -----------------------------------------------------

    public function test_price_does_not_mutate_on_access(): void
    {
        $price = Price::exclusiveOfTax(Money::fromDecimal('19.99', 'EUR'), 2000);

        $price->gross();
        $price->tax();

        $this->assertSame(1999, $price->net()->minorValue());
    }
}
