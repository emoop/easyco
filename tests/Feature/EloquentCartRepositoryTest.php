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
}
