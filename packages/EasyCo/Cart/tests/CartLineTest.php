<?php

namespace EasyCo\Cart\Tests;

use EasyCo\Cart\CartLine;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CartLineTest extends TestCase
{
    public function test_valid_construction_succeeds(): void
    {
        $line = new CartLine(id: null, cartId: '', variationId: '5', quantity: 2);

        $this->assertNull($line->id());
        $this->assertSame('', $line->cartId());
        $this->assertSame('5', $line->variationId());
        $this->assertSame(2, $line->quantity());
        $this->assertNull($line->priceAtAddMinor());
        $this->assertNull($line->priceAtAddCurrency());
    }

    public function test_construction_with_price_at_add_both_set_succeeds(): void
    {
        $line = new CartLine(null, '', '5', 2, 2999, 'EUR');

        $this->assertSame(2999, $line->priceAtAddMinor());
        $this->assertSame('EUR', $line->priceAtAddCurrency());
    }

    public function test_an_empty_variation_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CartLine(null, '', '', 1);
    }

    public function test_quantity_below_one_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CartLine(null, '', '5', 0);
    }

    public function test_price_at_add_minor_set_without_currency_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CartLine(null, '', '5', 1, 2999, null);
    }

    public function test_price_at_add_currency_set_without_minor_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CartLine(null, '', '5', 1, null, 'EUR');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $line = new CartLine(null, '', '5', 1);
        $line->assignId('1');

        $this->assertSame('1', $line->id());

        $this->expectException(LogicException::class);
        $line->assignId('2');
    }

    public function test_cart_id_can_only_be_assigned_once(): void
    {
        $line = new CartLine(null, '', '5', 1);
        $line->assignCartId('10');

        $this->assertSame('10', $line->cartId());

        $this->expectException(LogicException::class);
        $line->assignCartId('11');
    }

    public function test_set_quantity_updates_the_quantity(): void
    {
        $line = new CartLine(null, '', '5', 1);

        $line->setQuantity(4);

        $this->assertSame(4, $line->quantity());
    }

    public function test_set_quantity_below_one_is_rejected(): void
    {
        $line = new CartLine(null, '', '5', 1);

        $this->expectException(InvalidArgumentException::class);
        $line->setQuantity(0);
    }

    public function test_increase_quantity_adds_to_the_current_quantity(): void
    {
        $line = new CartLine(null, '', '5', 2);

        $line->increaseQuantity(3);

        $this->assertSame(5, $line->quantity());
    }

    public function test_increase_quantity_with_a_non_positive_amount_is_rejected(): void
    {
        $line = new CartLine(null, '', '5', 2);

        $this->expectException(InvalidArgumentException::class);
        $line->increaseQuantity(0);
    }
}
