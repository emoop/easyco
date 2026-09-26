<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\Exceptions\AddressNotFoundForCheckoutException;
use App\Services\Exceptions\CartNotFoundForCheckoutException;
use App\Services\Exceptions\EmptyCartException;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\UnknownPaymentMethodException;
use App\Services\PaymentMethodAdapterResolver;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Inventory\Exceptions\InsufficientStockException;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Order;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Exceptions\PriceNotConfiguredException;
use EasyCo\Pricing\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;

/**
 * The Checkout HTTP surface — the final piece over checkout-domain-
 * design.md, now that CheckoutOrchestrator (domain assembly) is complete.
 * No new domain logic lives here: validate input, resolve the current
 * cart exactly the way CartController already does, call place(), map
 * its documented exceptions onto clean HTTP responses.
 *
 * Available to guests AND logged-in customers alike (§8.1) — this route
 * is deliberately NOT behind auth:customer, mirroring
 * AddressController::store()'s own "works for either" posture.
 */
class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutOrchestrator $orchestrator,
        private readonly PaymentMethodAdapterResolver $adapterResolver,
        private readonly TransactionRepository $transactions,
    ) {
    }

    /**
     * IMPORTANT — the replay case: when the orchestrator reports
     * isAlreadyPlaced() === true, this still returns 201 with the same
     * order body, never an error. A double-clicked "Pay" button is a
     * successful, idempotent outcome (checkout-domain-design.md §6),
     * not a failure — do not "fix" this into a 409 later.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->validationRules());

        // THE CART TO CHECK OUT IS THE ONE THE PAGE DISPLAYED, AND IT IS REQUIRED
        // (cart-domain-design.md §14.2). The server deliberately no longer resolves
        // "the current cart" for this request: a claimed cart is nobody's current
        // cart any more, and only the client can name the cart it is confirming —
        // which is what keeps a replay answerable. Unknown, not-yours and
        // someone-else's-cart all come back from the orchestrator as the same clean
        // 404; a request without the field is a 422 from the rules above.

        // Validated BEFORE Phase 1 runs at all, deliberately duplicating
        // the orchestrator's own UnknownPaymentMethodException throw
        // site rather than relying on it: Phase 2 (the actual charge)
        // runs AFTER Phase 1 has committed, so an unknown method
        // discovered only inside place() would leave a real Order with
        // no Payment and a claimed cart — and a retry hits the
        // idempotent-replay fast path (payment: null), never
        // re-attempting the charge. The result would be an order the
        // customer can never pay for. Failing here, before place() is
        // ever called, makes the whole request a clean no-op the
        // customer can simply retry with a valid method. The
        // orchestrator's own throw stays exactly as it is — it remains
        // correct for any non-HTTP caller — and this is a guard in
        // front of it, not a replacement.
        try {
            $this->adapterResolver->resolve($validated['payment_method']);
        } catch (UnknownPaymentMethodException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'unknown_payment_method',
            ], 422);
        }

        $accountId = Auth::guard('customer')->check() ? (string) Auth::guard('customer')->id() : null;

        $input = new CheckoutInput(
            cartId: (string) $validated['cart_id'],
            email: $validated['email'],
            recipientName: $validated['recipient_name'],
            phone: $validated['phone'],
            paymentMethod: $validated['payment_method'],
            accountId: $accountId,
            guestCartToken: $accountId === null ? $request->session()->get('cart_token') : null,
            addressId: $validated['address_id'] ?? null,
            deliveryType: isset($validated['delivery_type']) ? AddressDeliveryType::from($validated['delivery_type']) : null,
            country: $validated['country'] ?? null,
            city: $validated['city'] ?? null,
            postalCode: $validated['postal_code'] ?? null,
            addressLine1: $validated['address_line_1'] ?? null,
            addressLine2: $validated['address_line_2'] ?? null,
            carrierCode: $validated['carrier_code'] ?? null,
            pickupPointReference: $validated['pickup_point_reference'] ?? null,
            settlement: $validated['settlement'] ?? null,
        );

        // Each caught explicitly — never a broad \Throwable/\RuntimeException
        // catch, which would also swallow a genuine bug. InvalidArgumentException
        // is deliberately NOT caught here: the orchestrator only throws it for
        // caller-contract violations the validation rules below already
        // prevent (e.g. address_id with no account), so it surfacing as a 500
        // would mean a real bug in this controller, not bad user input.
        try {
            $result = $this->orchestrator->place($input, new DateTimeImmutable());
        } catch (EmptyCartException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PromotionNoLongerValidException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'promotion_no_longer_valid',
            ], 422);
        } catch (InsufficientStockException $e) {
            // 409, not 422 — the request was well-formed, the world
            // changed underneath it.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'insufficient_stock',
            ], 409);
        } catch (PriceNotConfiguredException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'price_not_available',
            ], 409);
        } catch (AddressNotFoundForCheckoutException $e) {
            // 404, not 403 — the posture that exception's own docblock
            // documents; never "improved" to reveal existence.
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (UnknownPaymentMethodException $e) {
            // Unreachable via this controller now that the pre-check
            // above rejects an unknown method before place() is ever
            // called — kept, not deleted, as a genuine belt-and-braces:
            // a resolver binding could in principle change between the
            // pre-check and this call.
            return response()->json([
                'message' => $e->getMessage(),
                'reason' => 'unknown_payment_method',
            ], 422);
        } catch (CartNotFoundForCheckoutException $e) {
            // Belt-and-braces — findCurrentCart()'s own 404 above should
            // already have caught this.
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'order' => $this->orderToArray($result->order()),
            'payment' => $result->payment() !== null ? $this->paymentToArray($result->payment()) : null,
            'already_placed' => $result->isAlreadyPlaced(),
        ], 201);
    }

    // findCurrentCart() used to live here, and is deliberately GONE rather than
    // merely unused: resolving "the current cart" for a checkout is exactly what the
    // after-checkout fix removed (cart-domain-design.md §14.2). After a claim, the
    // cart being confirmed is nobody's current cart, and only the client can name it.
    // The identity this request DOES need — the account, or the session's own guest
    // token — is read in store() and handed to the orchestrator, which is where both
    // the claim and the live cart are checked against it.

    /**
     * The address fields are only required when NO address_id is given.
     *
     * NOTE ON prohibited_with: verified against this project's actual
     * installed Laravel version (13.17) — vendor/laravel/framework's
     * ValidatesAttributes has no validateProhibitedWith() method, so
     * "prohibited_with" is not a real rule here despite one stray
     * docblock reference to it elsewhere in the framework. The genuinely
     * existing inverse-direction rule is `prohibits`: declared on
     * address_id itself, it fails validation if address_id is present
     * together with any of the listed delivery/address fields —
     * achieving exactly the "address_id excludes the new-address fields"
     * requirement with a rule that actually exists.
     *
     * Otherwise mirrors AddressController::validationRules()'s exact
     * required_if/prohibited_if delivery-type conditional shape.
     *
     * @return array<string, string>
     */
    private function validationRules(): array
    {
        return [
            // REQUIRED, not optional — cart-domain-design.md §14.2: the cart the page
            // displayed is the only thing that can answer a replay after the claim has
            // taken that cart out of "the current cart" for this identity.
            'cart_id' => 'required|string',
            'email' => 'required|email',
            'recipient_name' => 'required|string',
            'phone' => 'required|string',
            'payment_method' => 'required|string',
            'address_id' => 'nullable|string|prohibits:delivery_type,country,city,postal_code,address_line_1,address_line_2,carrier_code,pickup_point_reference,settlement',
            'delivery_type' => 'required_without:address_id|in:street_address,pickup_point',
            'country' => 'required_if:delivery_type,street_address|prohibited_if:delivery_type,pickup_point|string',
            'city' => 'required_if:delivery_type,street_address|prohibited_if:delivery_type,pickup_point|string',
            'address_line_1' => 'required_if:delivery_type,street_address|prohibited_if:delivery_type,pickup_point|string',
            'postal_code' => 'nullable|prohibited_if:delivery_type,pickup_point|string',
            'address_line_2' => 'nullable|prohibited_if:delivery_type,pickup_point|string',
            'carrier_code' => 'required_if:delivery_type,pickup_point|prohibited_if:delivery_type,street_address|string',
            'pickup_point_reference' => 'required_if:delivery_type,pickup_point|prohibited_if:delivery_type,street_address|string',
            'settlement' => 'required_if:delivery_type,pickup_point|prohibited_if:delivery_type,street_address|string',
        ];
    }

    private function orderToArray(Order $order): array
    {
        return [
            'id' => $order->id(),
            'email' => $order->email(),
            'subtotal' => $this->moneyToArray($order->subtotal()),
            'total' => $this->moneyToArray($order->total()),
            'discount_amount' => $this->moneyToArray($order->discount()),
            'status' => $order->status()->value,
            'applied_promotion_code' => $order->appliedPromotionCode(),
            'delivery_type' => $order->deliveryType()->value,
            'recipient_name' => $order->recipientName(),
            'phone' => $order->phone(),
            'country' => $order->country(),
            'city' => $order->city(),
            'postal_code' => $order->postalCode(),
            'address_line_1' => $order->addressLine1(),
            'address_line_2' => $order->addressLine2(),
            'carrier_code' => $order->carrierCode(),
            'pickup_point_reference' => $order->pickupPointReference(),
            'settlement' => $order->settlement(),
            'lines' => $this->saleLinesToArray($order->transactionId()),
        ];
    }

    /**
     * The order's lines, straight from the sale-line SNAPSHOT this request has
     * just written — never re-derived from the cart (already claimed by this
     * checkout: the claim records the order id and leaves the cart's lines in
     * place — a known Cart defect, see sandbox-manual-test-checklist.md's
     * scenario 10) and never re-priced. Eight display fields per line: product
     * name, SKU, the sold attributes exactly as recorded, quantity, final and
     * regular unit price, this line's share of the promotion discount, and what
     * was actually paid for it.
     *
     * COST IS DELIBERATELY ABSENT — no unit cost, no profit, no margin of any kind.
     * SaleLine carries those operational facts (§3.13) and the admin surface reads
     * them, but this is the CUSTOMER-facing storefront API: a customer's browser
     * must never receive the shop's cost basis.
     * tests/Feature/CheckoutResponseOrderLinesTest.php asserts their absence.
     *
     * Read server-side by the transaction id the order already carries, so the
     * storefront never needs a second lookup to render a confirmation page.
     *
     * @return array<int, array<string, mixed>>
     */
    private function saleLinesToArray(string $transactionId): array
    {
        $transaction = $this->transactions->findByIdWithSaleLines($transactionId);

        if ($transaction === null) {
            // Structurally impossible in this request: the orchestrator wrote this
            // transaction moments ago. A missing one is real corruption, so this
            // fails loudly rather than returning an order with no lines.
            throw new LogicException(
                "CheckoutController: transaction \"{$transactionId}\" was not found immediately after checkout."
            );
        }

        return array_map(fn (SaleLine $line): array => [
            'product_name' => $line->productName(),
            'sku' => $line->sku(),
            'attributes' => $line->soldAttributes(),
            'quantity' => $line->quantity(),
            'final_unit_price' => $this->moneyToArrayOrNull($line->finalUnitPrice()),
            'regular_unit_price' => $this->moneyToArrayOrNull($line->regularUnitPrice()),
            'promotion_discount_share' => $this->moneyToArrayOrNull($line->promotionDiscountShare()),
            'net_paid_amount' => $this->moneyToArrayOrNull($line->netPaidAmount()),
        ], $transaction->saleLines());
    }

    private function paymentToArray(Payment $payment): array
    {
        return [
            'id' => $payment->id(),
            'method' => $payment->method(),
            'status' => $payment->status()->value,
            'amount' => $this->moneyToArray($payment->amount()),
        ];
    }

    /** Same {minor, currency} shape CartController::moneyToArray() already produces. */
    private function moneyToArray(Money $money): array
    {
        return ['minor' => $money->minorValue(), 'currency' => $money->currency()->code()];
    }

    /**
     * SaleLine's snapshot amounts are nullable (§3.13 Q2 — genuinely unknown, not
     * zero), so they pass through as null rather than being invented here.
     *
     * @return array{minor: int, currency: string}|null
     */
    private function moneyToArrayOrNull(?Money $money): ?array
    {
        return $money === null ? null : $this->moneyToArray($money);
    }
}
