<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Cart\Persistence\Eloquent\CartModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneExpiredCartsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_carts_are_removed_and_live_carts_are_kept(): void
    {
        $expired = Cart::forGuest('token-expired', new DateTimeImmutable('-1 day'));
        $live = Cart::forGuest('token-live', new DateTimeImmutable('+10 days'));

        app(CartRepository::class)->save($expired);
        app(CartRepository::class)->save($live);

        $this->assertSame(2, CartModel::count());

        $this->artisan('cart:prune')
            ->expectsOutputToContain('Pruned 1 expired cart(s).')
            ->assertExitCode(0);

        $this->assertSame(1, CartModel::count());
        $this->assertNotNull(app(CartRepository::class)->findById($live->id()));
    }

    public function test_no_expired_carts_prunes_zero(): void
    {
        $live = Cart::forGuest('token-live', new DateTimeImmutable('+10 days'));
        app(CartRepository::class)->save($live);

        $this->artisan('cart:prune')
            ->expectsOutputToContain('Pruned 0 expired cart(s).')
            ->assertExitCode(0);

        $this->assertSame(1, CartModel::count());
    }
}
