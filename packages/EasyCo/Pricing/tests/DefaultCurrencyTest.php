<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Currency;
use EasyCo\Pricing\DefaultCurrency;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DefaultCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // DefaultCurrency is plain static state shared across every test
        // in this process — reset before each test so no earlier test's
        // configured value leaks in (same reasoning
        // EasyCo\OperationalSales\InstallmentPlanTest already applies).
        DefaultCurrency::reset();
    }

    protected function tearDown(): void
    {
        DefaultCurrency::reset();

        parent::tearDown();
    }

    public function test_get_throws_when_never_configured(): void
    {
        $this->expectException(LogicException::class);

        DefaultCurrency::get();
    }

    public function test_set_followed_by_get_returns_the_configured_currency(): void
    {
        DefaultCurrency::set(Currency::GBP());

        $this->assertTrue(DefaultCurrency::get()->equals(Currency::GBP()));
    }

    public function test_reset_clears_the_configured_value_so_get_throws_again(): void
    {
        DefaultCurrency::set(Currency::GBP());
        DefaultCurrency::reset();

        $this->expectException(LogicException::class);
        DefaultCurrency::get();
    }

    public function test_is_configured_reflects_state_before_and_after_set_and_reset(): void
    {
        $this->assertFalse(DefaultCurrency::isConfigured());

        DefaultCurrency::set(Currency::EUR());
        $this->assertTrue(DefaultCurrency::isConfigured());

        DefaultCurrency::reset();
        $this->assertFalse(DefaultCurrency::isConfigured());
    }
}
