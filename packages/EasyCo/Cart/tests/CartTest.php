<?php

namespace EasyCo\Cart\Tests;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLine;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    private function expiry(): DateTimeImmutable
    {
        return new DateTimeImmutable('+10 days');
    }

    public function test_for_account_creates_an_account_cart(): void
    {
        $cart = Cart::forAccount('7', $this->expiry());

        $this->assertNull($cart->id());
        $this->assertSame('7', $cart->accountId());
        $this->assertNull($cart->sessionToken());
    }

    public function test_for_guest_creates_a_guest_cart(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->assertNull($cart->accountId());
        $this->assertSame('token-abc', $cart->sessionToken());
    }

    public function test_both_account_id_and_session_token_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cart(null, '7', 'token-abc', $this->expiry());
    }

    public function test_neither_account_id_nor_session_token_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Cart(null, null, null, $this->expiry());
    }

    public function test_add_line_twice_for_the_same_variation_merges_into_one_line_with_summed_quantity(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $cart->addLine(new CartLine(null, '', '5', 2));
        $cart->addLine(new CartLine(null, '', '5', 3));

        $this->assertCount(1, $cart->lines());
        $this->assertSame(5, $cart->lines()[0]->quantity());
    }

    public function test_add_line_for_different_variations_appends_both(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $cart->addLine(new CartLine(null, '', '5', 2));
        $cart->addLine(new CartLine(null, '', '6', 1));

        $this->assertCount(2, $cart->lines());
        $this->assertSame(3, $cart->totalQuantity());
    }

    public function test_assign_id_back_fills_cart_id_on_lines_added_before_the_cart_had_an_id(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $line = new CartLine(null, '', '5', 1);
        $cart->addLine($line);

        $cart->assignId('42');

        $this->assertSame('42', $line->cartId());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->assignId('1');

        $this->assertSame('1', $cart->id());

        $this->expectException(LogicException::class);
        $cart->assignId('2');
    }

    public function test_update_line_quantity_on_a_missing_variation_throws(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->expectException(InvalidArgumentException::class);
        $cart->updateLineQuantity('999', 3);
    }

    public function test_update_line_quantity_updates_the_existing_line(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', '5', 2));

        $cart->updateLineQuantity('5', 9);

        $this->assertSame(9, $cart->lines()[0]->quantity());
    }

    public function test_update_line_quantity_below_one_is_rejected(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', '5', 2));

        $this->expectException(InvalidArgumentException::class);
        $cart->updateLineQuantity('5', 0);
    }

    public function test_remove_line_removes_it(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', '5', 2));

        $cart->removeLine('5');

        $this->assertTrue($cart->isEmpty());
    }

    public function test_remove_line_for_a_variation_not_present_is_a_harmless_no_op(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', '5', 2));

        $cart->removeLine('999');

        $this->assertCount(1, $cart->lines());
    }

    public function test_total_quantity_sums_all_lines(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', '5', 2));
        $cart->addLine(new CartLine(null, '', '6', 3));

        $this->assertSame(5, $cart->totalQuantity());
    }

    public function test_is_empty_is_true_for_a_freshly_constructed_cart(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->assertTrue($cart->isEmpty());
    }

    public function test_refresh_expiry_updates_the_expiry(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $newExpiry = new DateTimeImmutable('+30 days');

        $cart->refreshExpiry($newExpiry);

        $this->assertSame($newExpiry, $cart->expiresAt());
    }

    public function test_reconstitute_from_storage_rebuilds_a_cart_with_its_lines(): void
    {
        $line = new CartLine('1', '42', '5', 2);

        $cart = Cart::reconstituteFromStorage('42', '7', null, $this->expiry(), [$line]);

        $this->assertSame('42', $cart->id());
        $this->assertSame('7', $cart->accountId());
        $this->assertCount(1, $cart->lines());
        $this->assertSame('5', $cart->lines()[0]->variationId());
    }

    public function test_applied_promotion_code_defaults_to_null_for_account_cart(): void
    {
        $cart = Cart::forAccount('7', $this->expiry());

        $this->assertNull($cart->appliedPromotionCode());
    }

    public function test_applied_promotion_code_defaults_to_null_for_guest_cart(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->assertNull($cart->appliedPromotionCode());
    }

    public function test_apply_promotion_code_stores_it_lowercased(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $cart->applyPromotionCode('SUMMER20');

        $this->assertSame('summer20', $cart->appliedPromotionCode());
    }

    public function test_apply_promotion_code_trims_and_lowercases_whitespace(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $cart->applyPromotionCode('  summer20  ');

        $this->assertSame('summer20', $cart->appliedPromotionCode());
    }

    public function test_apply_promotion_code_with_an_empty_string_throws(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->expectException(InvalidArgumentException::class);
        $cart->applyPromotionCode('');
    }

    public function test_apply_promotion_code_with_an_all_whitespace_string_throws(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->expectException(InvalidArgumentException::class);
        $cart->applyPromotionCode('   ');
    }

    public function test_clear_promotion_code_resets_it_to_null(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->applyPromotionCode('summer20');

        $cart->clearPromotionCode();

        $this->assertNull($cart->appliedPromotionCode());
    }

    public function test_clear_promotion_code_when_already_null_does_not_throw(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $cart->clearPromotionCode();

        $this->assertNull($cart->appliedPromotionCode());
    }

    public function test_reconstitute_from_storage_round_trips_applied_promotion_code(): void
    {
        $cart = Cart::reconstituteFromStorage(
            id: '42',
            accountId: '7',
            sessionToken: null,
            expiresAt: $this->expiry(),
            appliedPromotionCode: 'summer20',
        );

        $this->assertSame('summer20', $cart->appliedPromotionCode());
    }
}
