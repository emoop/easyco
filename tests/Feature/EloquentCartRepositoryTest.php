<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLine;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Persistence\Eloquent\CartLineModel;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\OperationalSales\Client;
use EasyCo\OperationalSales\Contracts\ClientRepository;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Order;
use EasyCo\Pricing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EloquentCartRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private static int $productCounter = 0;

    private function repository(): CartRepository
    {
        return app(CartRepository::class);
    }

    private function variationId(): string
    {
        self::$productCounter++;
        $suffix = (string) self::$productCounter;

        $product = Product::createSimple("Product {$suffix}", "SKU-{$suffix}", "product-slug-{$suffix}");
        app(ProductRepository::class)->save($product);

        return $product->variations()[0]->id();
    }

    private function accountId(string $email): string
    {
        $account = Account::register($email, 'hashed-password');
        app(AccountRepository::class)->save($account);

        return $account->id();
    }

    private function expiry(): DateTimeImmutable
    {
        return new DateTimeImmutable('+10 days');
    }

    /** Same Client -> Transaction -> Order chain already used in Step 1b's/PromotionRedemption's own Feature tests. */
    private function orderId(): string
    {
        $client = new Client(null, 'Ivan Ivanov');
        app(ClientRepository::class)->save($client);

        $transaction = new Transaction(null, Channel::WEB);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $client->id(),
            priceableId: 'variation-1',
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(1000, 'EUR'),
            profit: Money::fromMinorUnits(200, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-01-01'),
            effectiveAt: new DateTimeImmutable('2026-01-01'),
            productName: 'Product One',
            sku: 'SKU-1',
            regularUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(1000, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(1000, 'EUR'),
            soldAttributes: [],
        ));
        app(TransactionRepository::class)->save($transaction);

        $order = Order::create(
            clientId: $client->id(),
            transactionId: $transaction->id(),
            email: 'buyer@example.com',
            currency: 'EUR',
            subtotal: Money::fromMinorUnits(1000, 'EUR'),
            discount: Money::fromMinorUnits(0, 'EUR'),
            deliveryType: OrderDeliveryType::STREET_ADDRESS,
            recipientName: 'Ivan Ivanov',
            phone: '+359888123456',
            placedAt: new DateTimeImmutable('2026-01-01'),
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );
        app(OrderRepository::class)->save($order);

        return $order->id();
    }

    public function test_the_real_cart_line_composite_unique_constraint(): void
    {
        // Confirms the actual constraint this repository's collision
        // handling depends on — not just trusting the migration file
        // (CLAUDE.md rule 2/project convention).
        $createTable = DB::select('SHOW CREATE TABLE cart_lines')[0]->{'Create Table'};

        $this->assertStringContainsString('UNIQUE KEY `cart_lines_cart_variation_unique`', $createTable);
    }

    public function test_save_then_find_by_id_round_trips_a_guest_cart(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->repository()->save($cart);

        $this->assertNotNull($cart->id());

        $reloaded = $this->repository()->findById($cart->id());
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->accountId());
        $this->assertSame('token-abc', $reloaded->sessionToken());
    }

    public function test_save_then_find_by_account_id_round_trips_an_account_cart(): void
    {
        $accountId = $this->accountId('user@example.com');
        $cart = Cart::forAccount($accountId, $this->expiry());

        $this->repository()->save($cart);

        $reloaded = $this->repository()->findByAccountId($accountId);
        $this->assertNotNull($reloaded);
        $this->assertSame($accountId, $reloaded->accountId());
        $this->assertNull($reloaded->sessionToken());
    }

    public function test_find_by_session_token_round_trips(): void
    {
        $cart = Cart::forGuest('token-xyz', $this->expiry());
        $this->repository()->save($cart);

        $reloaded = $this->repository()->findBySessionToken('token-xyz');

        $this->assertNotNull($reloaded);
        $this->assertSame($cart->id(), $reloaded->id());
    }

    public function test_find_by_id_for_a_nonexistent_id_returns_null(): void
    {
        $this->assertNull($this->repository()->findById('999999'));
    }

    public function test_lines_are_persisted_and_rehydrated(): void
    {
        $variationId = $this->variationId();
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', $variationId, 3, 2999, 'EUR'));

        $this->repository()->save($cart);

        $reloaded = $this->repository()->findById($cart->id());
        $this->assertCount(1, $reloaded->lines());
        $line = $reloaded->lines()[0];
        $this->assertSame($variationId, $line->variationId());
        $this->assertSame(3, $line->quantity());
        $this->assertSame(2999, $line->priceAtAddMinor());
        $this->assertSame('EUR', $line->priceAtAddCurrency());
    }

    public function test_updating_an_existing_carts_lines_syncs_add_change_and_remove_without_orphan_rows(): void
    {
        $variationA = $this->variationId();
        $variationB = $this->variationId();
        $variationC = $this->variationId();

        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', $variationA, 1));
        $cart->addLine(new CartLine(null, '', $variationB, 2));
        $this->repository()->save($cart);

        $this->assertSame(2, CartLineModel::count());

        // Reload (simulating a fresh request), then change B's quantity,
        // remove... nothing removed yet, add C.
        $reloaded = $this->repository()->findById($cart->id());
        $reloaded->updateLineQuantity($variationB, 9);
        $reloaded->addLine(new CartLine(null, $reloaded->id(), $variationC, 4));
        $reloaded->removeLine($variationA);
        $this->repository()->save($reloaded);

        $this->assertSame(2, CartLineModel::count());

        $final = $this->repository()->findById($cart->id());
        $byVariation = [];
        foreach ($final->lines() as $line) {
            $byVariation[$line->variationId()] = $line->quantity();
        }

        $this->assertArrayNotHasKey($variationA, $byVariation);
        $this->assertSame(9, $byVariation[$variationB]);
        $this->assertSame(4, $byVariation[$variationC]);
    }

    public function test_delete_removes_the_cart_and_its_lines(): void
    {
        $variationId = $this->variationId();
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->addLine(new CartLine(null, '', $variationId, 1));
        $this->repository()->save($cart);
        $cartId = $cart->id();

        $this->repository()->delete($cartId);

        $this->assertNull($this->repository()->findById($cartId));
        $this->assertSame(0, CartLineModel::count());
    }

    public function test_delete_expired_removes_only_genuinely_expired_carts_and_returns_the_count(): void
    {
        $expired1 = Cart::forGuest('token-expired-1', new DateTimeImmutable('-1 day'));
        $expired2 = Cart::forGuest('token-expired-2', new DateTimeImmutable('-10 days'));
        $stillLive = Cart::forGuest('token-live', new DateTimeImmutable('+10 days'));

        $this->repository()->save($expired1);
        $this->repository()->save($expired2);
        $this->repository()->save($stillLive);

        $deleted = $this->repository()->deleteExpired(new DateTimeImmutable());

        $this->assertSame(2, $deleted);
        $this->assertSame(1, CartModel::count());
        $this->assertNotNull($this->repository()->findById($stillLive->id()));
    }

    public function test_a_cart_with_an_applied_promotion_code_round_trips_it(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->applyPromotionCode('SUMMER20');

        $this->repository()->save($cart);

        $reloaded = $this->repository()->findById($cart->id());
        $this->assertSame('summer20', $reloaded->appliedPromotionCode());
    }

    public function test_a_cart_with_no_applied_promotion_code_round_trips_null(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());

        $this->repository()->save($cart);

        $reloaded = $this->repository()->findById($cart->id());
        $this->assertNull($reloaded->appliedPromotionCode());
    }

    public function test_applying_then_clearing_a_promotion_code_and_saving_again_round_trips_null(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $cart->applyPromotionCode('summer20');
        $this->repository()->save($cart);

        $cart->clearPromotionCode();
        $this->repository()->save($cart);

        $reloaded = $this->repository()->findById($cart->id());
        $this->assertNull($reloaded->appliedPromotionCode());
    }

    public function test_claim_for_order_on_an_unclaimed_cart_succeeds_and_find_order_id_for_cart_returns_it(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $this->repository()->save($cart);
        $orderId = $this->orderId();

        $claimed = $this->repository()->claimForOrder($cart->id(), $orderId);

        $this->assertTrue($claimed);
        $this->assertSame($orderId, $this->repository()->findOrderIdForCart($cart->id()));
    }

    /**
     * The real proof that "iff not already claimed" actually holds, not
     * just that the method exists — a second claim attempt with a
     * DIFFERENT orderId must be rejected and must never overwrite the
     * first claim.
     */
    public function test_claiming_an_already_claimed_cart_a_second_time_with_a_different_order_id_fails_and_does_not_overwrite(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $this->repository()->save($cart);
        $firstOrderId = $this->orderId();
        $secondOrderId = $this->orderId();

        $firstClaim = $this->repository()->claimForOrder($cart->id(), $firstOrderId);
        $this->assertTrue($firstClaim);

        $secondClaim = $this->repository()->claimForOrder($cart->id(), $secondOrderId);

        $this->assertFalse($secondClaim);
        $this->assertSame($firstOrderId, $this->repository()->findOrderIdForCart($cart->id()));
    }

    public function test_find_order_id_for_cart_on_a_never_claimed_cart_returns_null(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $this->repository()->save($cart);

        $this->assertNull($this->repository()->findOrderIdForCart($cart->id()));
    }

    public function test_claiming_a_different_cart_with_an_already_claimed_order_id_is_rejected_by_the_database(): void
    {
        $firstCart = Cart::forGuest('token-first', $this->expiry());
        $this->repository()->save($firstCart);
        $orderId = $this->orderId();
        $this->assertTrue($this->repository()->claimForOrder($firstCart->id(), $orderId));

        $secondCart = Cart::forGuest('token-second', $this->expiry());
        $this->repository()->save($secondCart);

        $this->expectException(QueryException::class);

        $this->repository()->claimForOrder($secondCart->id(), $orderId);
    }

    public function test_the_real_carts_order_id_unique_and_null_on_delete_constraint(): void
    {
        $createTable = DB::select('SHOW CREATE TABLE carts')[0]->{'Create Table'};

        $this->assertStringContainsString('UNIQUE KEY `carts_order_id_unique`', $createTable);

        // §14.1/§14.5: the live columns KEEP their unique indexes — which now mean "at
        // most one LIVE cart per identity" — while the claimed columns are indexed but
        // deliberately NOT unique, and the claimed FK carries the "a cart does not
        // outlive its account" rule for a row whose live account_id is NULL.
        $this->assertStringContainsString('UNIQUE KEY `carts_account_id_unique`', $createTable);
        $this->assertStringContainsString('UNIQUE KEY `carts_session_token_unique`', $createTable);
        $this->assertStringContainsString('`claimed_session_token`', $createTable);
        $this->assertStringContainsString(
            'CONSTRAINT `carts_claimed_account_id_foreign` FOREIGN KEY (`claimed_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE',
            $createTable
        );
        $this->assertStringContainsString(
            'CONSTRAINT `carts_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL',
            $createTable
        );
    }

    public function test_deleting_the_backing_order_nulls_the_carts_order_id_but_the_cart_row_survives(): void
    {
        $cart = Cart::forGuest('token-abc', $this->expiry());
        $this->repository()->save($cart);
        $orderId = $this->orderId();
        $this->repository()->claimForOrder($cart->id(), $orderId);

        DB::table('orders')->where('id', $orderId)->delete();

        $this->assertNull($this->repository()->findOrderIdForCart($cart->id()));

        // The row survives, but it is no longer a CART: deleting the order un-claimed
        // it (order_id is nullOnDelete) WITHOUT restoring the identity the claim moved
        // away, so findById() — which refuses to build a Cart from a row with no live
        // identity — reports it as absent (cart-domain-design.md §14.1). Disposable
        // history, not a cart anybody could add to.
        $this->assertNotNull(CartModel::find($cart->id()));
        $this->assertNull($this->repository()->findById($cart->id()));
    }

    // ---------------------------------------------------------------------------------
    // cart-domain-design.md §14 — a claimed cart is not the identity's current cart
    // ---------------------------------------------------------------------------------

    public function test_claiming_moves_the_identity_to_the_claimed_columns_and_nulls_the_live_ones(): void
    {
        $cart = Cart::forGuest('token-moving', $this->expiry());
        $this->repository()->save($cart);
        $orderId = $this->orderId();

        $this->assertTrue($this->repository()->claimForOrder($cart->id(), $orderId));

        $row = CartModel::findOrFail($cart->id());
        $this->assertNull($row->session_token, 'the live identity column must be freed for the next cart');
        $this->assertSame('token-moving', $row->claimed_session_token);
        $this->assertSame($orderId, (string) $row->order_id);

        // The account half of the same move, asserted the same way: what must land in
        // claimed_account_id is the PREVIOUS live account_id, not the NULL this one
        // statement writes into that live column. The order of claimForOrder()'s SET
        // list is what makes this true — see its docblock.
        $accountId = $this->accountId('moving-identity@example.com');
        $accountCart = Cart::forAccount($accountId, $this->expiry());
        $this->repository()->save($accountCart);

        $this->assertTrue($this->repository()->claimForOrder($accountCart->id(), $this->orderId()));

        $accountRow = CartModel::findOrFail($accountCart->id());
        $this->assertNull($accountRow->account_id, 'the live identity column must be freed for the next cart');
        $this->assertSame($accountId, (string) $accountRow->claimed_account_id);
    }

    public function test_a_claimed_cart_is_no_longer_the_identities_current_cart(): void
    {
        $accountId = $this->accountId('claimed-account@example.com');
        $cart = Cart::forAccount($accountId, $this->expiry());
        $this->repository()->save($cart);

        $this->assertNotNull($this->repository()->findByAccountId($accountId));

        $this->repository()->claimForOrder($cart->id(), $this->orderId());

        $this->assertNull($this->repository()->findByAccountId($accountId), 'the next add must not find the bought cart');
        $this->assertNull($this->repository()->findById($cart->id()), 'and no Cart aggregate is ever built from a claimed row');
    }

    public function test_the_same_identity_can_start_a_new_cart_after_a_claim_without_a_duplicate_key_error(): void
    {
        $accountId = $this->accountId('second-purchase@example.com');
        $first = Cart::forAccount($accountId, $this->expiry());
        $this->repository()->save($first);
        $this->repository()->claimForOrder($first->id(), $this->orderId());

        // The row the unique index used to forbid: without the identity move, this
        // save would hit carts_account_id_unique.
        $second = Cart::forAccount($accountId, $this->expiry());
        $this->repository()->save($second);

        $this->assertNotSame($first->id(), $second->id());
        $this->assertSame($second->id(), $this->repository()->findByAccountId($accountId)?->id());

        // A guest's next cart reuses the SAME session token (§14.1) — the live unique
        // index is free again precisely because the claim moved that token away.
        $guestFirst = Cart::forGuest('token-reused', $this->expiry());
        $this->repository()->save($guestFirst);
        $this->repository()->claimForOrder($guestFirst->id(), $this->orderId());

        $guestSecond = Cart::forGuest('token-reused', $this->expiry());
        $this->repository()->save($guestSecond);

        $this->assertSame($guestSecond->id(), $this->repository()->findBySessionToken('token-reused')?->id());
    }

    public function test_find_claim_for_identity_answers_only_the_identity_that_owned_the_cart(): void
    {
        $accountId = $this->accountId('claim-owner@example.com');
        $cart = Cart::forAccount($accountId, $this->expiry());
        $this->repository()->save($cart);
        $orderId = $this->orderId();
        $this->repository()->claimForOrder($cart->id(), $orderId);

        $this->assertSame($orderId, $this->repository()->findClaimForIdentity($cart->id(), $accountId, null)?->orderId);
        $this->assertNull(
            $this->repository()->findClaimForIdentity($cart->id(), $this->accountId('claim-stranger@example.com'), null)
        );
        $this->assertNull($this->repository()->findClaimForIdentity($cart->id(), null, null), 'an identity-less caller owns nothing');
        $this->assertNull($this->repository()->findClaimForIdentity('00000000-0000-7000-8000-000000000000', $accountId, null));

        // A guest claim answers to the session's own token, and to nothing else.
        $guestCart = Cart::forGuest('token-claimed', $this->expiry());
        $this->repository()->save($guestCart);
        $guestOrderId = $this->orderId();
        $this->repository()->claimForOrder($guestCart->id(), $guestOrderId);

        $this->assertSame($guestOrderId, $this->repository()->findClaimForIdentity($guestCart->id(), null, 'token-claimed')?->orderId);
        $this->assertNull($this->repository()->findClaimForIdentity($guestCart->id(), null, 'token-not-mine'));
    }

    public function test_find_claim_for_identity_is_null_for_a_live_cart(): void
    {
        $cart = Cart::forGuest('token-live-claim', $this->expiry());
        $this->repository()->save($cart);

        $this->assertNull($this->repository()->findClaimForIdentity($cart->id(), null, 'token-live-claim'));
    }

    public function test_a_claimed_cart_is_still_pruned_on_its_own_expiry_and_then_has_no_claim(): void
    {
        $cart = Cart::forGuest('token-expiring-claimed', new DateTimeImmutable('-1 day'));
        $this->repository()->save($cart);
        $this->repository()->claimForOrder($cart->id(), $this->orderId());

        $this->assertSame(1, $this->repository()->deleteExpired(new DateTimeImmutable()));

        // §14.4: the replay window ends with the cart — documented, not discovered.
        $this->assertNull($this->repository()->findClaimForIdentity($cart->id(), null, 'token-expiring-claimed'));
    }
}
