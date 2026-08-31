<?php

namespace App\Listeners;

use DateTimeImmutable;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLine;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Guest-to-account cart merge on login — see cart-domain-design.md §8.
 * Listens on Laravel's own Illuminate\Auth\Events\Login rather than
 * this project's Hook:: system, because it's reacting to a framework
 * event (any 'customer'-guard login), not a domain extension point.
 *
 * Fires for BOTH explicit login (AccountSessionController::store()'s
 * Auth::guard('customer')->attempt()) and registration's auto-login
 * (AccountRegistrationController::store()'s ->login()) — confirmed
 * directly against Illuminate\Auth\SessionGuard's source: attempt()
 * calls login() internally, and login() always fires this event. A
 * guest who registers keeps their cart exactly like a guest who logs
 * into an existing account.
 *
 * KNOWN LIMITATION, not silently ignored: decision #8 says clamping
 * should "ideally" be visible in the login/register response, but
 * this task's scope explicitly forbids editing
 * AccountSessionController/AccountRegistrationController (a different,
 * already-shipped domain). The merge still happens correctly and
 * safely; the clamped result just isn't surfaced until the customer's
 * next GET /api/cart, not in the login/register response itself.
 */
class MergeGuestCartIntoAccountCart
{
    public function __construct(
        private readonly Request $request,
        private readonly CartRepository $carts,
        private readonly StockLevelRepository $stockLevels,
    ) {
    }

    public function handle(Login $event): void
    {
        if ($event->guard !== 'customer') {
            return;
        }

        $token = $this->request->session()->get('cart_token');

        if ($token === null) {
            return;
        }

        $guestCart = $this->carts->findBySessionToken($token);

        if ($guestCart === null) {
            $this->request->session()->forget('cart_token');

            return;
        }

        $accountId = (string) $event->user->getAuthIdentifier();
        $accountCart = $this->carts->findByAccountId($accountId)
            ?? Cart::forAccount($accountId, new DateTimeImmutable('+30 days'));

        foreach ($guestCart->lines() as $guestLine) {
            $this->mergeLine($accountCart, $guestLine);
        }

        $accountCart->refreshExpiry(new DateTimeImmutable('+30 days'));
        $this->carts->save($accountCart);
        $this->carts->delete($guestCart->id());

        $this->request->session()->forget('cart_token');
    }

    /**
     * Same variationId in both → sum the quantities, then clamp to
     * available stock (never fail the login/registration over it — a
     * merge is not the moment to reject a customer's own credentials
     * because of unrelated stock math). A clamp to zero drops the
     * line entirely rather than persisting an invalid zero-quantity
     * CartLine.
     */
    private function mergeLine(Cart $accountCart, CartLine $guestLine): void
    {
        $existingQuantity = 0;

        foreach ($accountCart->lines() as $accountLine) {
            if ($accountLine->variationId() === $guestLine->variationId()) {
                $existingQuantity = $accountLine->quantity();
                break;
            }
        }

        $available = $this->stockLevels->findByVariationId($guestLine->variationId())->quantity();
        $clamped = min($existingQuantity + $guestLine->quantity(), $available);

        if ($clamped < 1) {
            return;
        }

        if ($existingQuantity > 0) {
            $accountCart->updateLineQuantity($guestLine->variationId(), $clamped);

            return;
        }

        $accountCart->addLine(new CartLine(
            id: null,
            cartId: $accountCart->id() ?? '',
            variationId: $guestLine->variationId(),
            quantity: $clamped,
            priceAtAddMinor: $guestLine->priceAtAddMinor(),
            priceAtAddCurrency: $guestLine->priceAtAddCurrency(),
        ));
    }
}
