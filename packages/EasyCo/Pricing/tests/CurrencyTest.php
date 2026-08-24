<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function test_known_currency_resolves(): void
    {
        $eur = Currency::of('EUR');

        $this->assertSame('EUR', $eur->code());
        $this->assertSame(2, $eur->decimalPlaces());
    }

    public function test_lowercase_input_is_normalized_to_uppercase(): void
    {
        $eur = Currency::of('eur');

        $this->assertSame('EUR', $eur->code());
    }

    public function test_whitespace_is_trimmed(): void
    {
        $eur = Currency::of('  EUR  ');

        $this->assertSame('EUR', $eur->code());
    }

    public function test_unknown_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::of('XYZ');
    }

    public function test_zero_decimal_currency(): void
    {
        $this->assertSame(0, Currency::of('JPY')->decimalPlaces());
    }

    public function test_three_decimal_currency(): void
    {
        $this->assertSame(3, Currency::of('KWD')->decimalPlaces());
    }

    public function test_named_constructors_match_of(): void
    {
        $this->assertTrue(Currency::EUR()->equals(Currency::of('EUR')));
        $this->assertTrue(Currency::USD()->equals(Currency::of('USD')));
        $this->assertTrue(Currency::BGN()->equals(Currency::of('BGN')));
    }

    public function test_equals_true_for_same_code(): void
    {
        $this->assertTrue(Currency::of('EUR')->equals(Currency::of('EUR')));
    }

    public function test_equals_false_for_different_code(): void
    {
        $this->assertFalse(Currency::of('EUR')->equals(Currency::of('USD')));
    }

    public function test_from_accepts_currency_instance_or_string(): void
    {
        $fromString = Currency::from('EUR');
        $fromInstance = Currency::from(Currency::EUR());

        $this->assertTrue($fromString->equals($fromInstance));
    }
}
