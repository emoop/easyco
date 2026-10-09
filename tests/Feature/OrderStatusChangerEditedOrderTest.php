<?php

namespace Tests\Feature;

use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderCurrentLinesResolver;
use App\Services\OrderEditor;
use App\Services\OrderPromotionCodeChange;
use App\Services\OrderStatusChanger;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Contracts\OrderRepository;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * The fix for OrderStatusChanger::performReturn() reading ONLY the placement
 * transaction: on an EDITED order the original line may have been reversed
 * away and replaced by a line in the edit's own transaction, so a cancel or a
 * return must act on the order's TRUE current lines
 * (OrderCurrentLinesResolver::resolveWithReturns()). Everything here runs the
 * real services end to end — CheckoutOrchestrator, OrderEditor,
 * OrderStatusChanger — with no hand-built rows except the status fixture.
 *
 * Fixture: Alpha x2 (10.00) + Beta x3 (5.00), stock 50 each. Editing Alpha
 * down to 1 leaves the ledger with a REVERSED original (qty 2) and a
 * replacement (qty 1).
 */
class OrderStatusChangerEditedOrderTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

    private ?PriceList $priceList = null;

    /** @var array<string, string> */
    private array $variations = [];

    private function variation(string $key, string $name, string $price): void
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper($key).strtoupper(Str::random(4)), 'slug-'.strtolower($key).'-'.strtolower(Str::random(4)));
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        app(PriceListItemRepository::class)->save(new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        ));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 50));

        $this->variations[$key] = $variationId;
    }

    private function place(): Order
    {
        $this->variation('A', 'Alpha Widget', '10.00');
        $this->variation('B', 'Beta Widget', '5.00');

        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $this->variations['A'], 2, null, null);
        app(CartLineAdder::class)->addLine($cart, $this->variations['B'], 3, null, null);

        return app(CheckoutOrchestrator::class)->place(new CheckoutInput(
            cartId: $cart->id(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            accountId: null,
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            addressId: null,
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
            shippingMethodId: $this->shippingMethodId(), quoteHandle: $this->lostShippingHandle(), expectedShippingMinor: 0,
        ), new DateTimeImmutable('2026-09-29 12:00:00'))->order();
    }

    private function order(Order $placed): Order
    {
        return app(OrderRepository::class)->findById($placed->id());
    }

    private function lineOf(Order $placed, string $key): \EasyCo\OperationalSales\SaleLine
    {
        foreach (app(OrderCurrentLinesResolver::class)->resolve($this->order($placed)) as $line) {
            if ($line->priceableId() === $this->variations[$key]) {
                return $line;
            }
        }

        $this->fail("No current line for {$key}.");
    }

    private function lineIdFor(Order $placed, string $key): string
    {
        foreach (app(OrderCurrentLinesResolver::class)->resolveRows($this->order($placed)) as $entry) {
            if ((string) $entry['row']->priceable_id === $this->variations[$key]) {
                return (string) $entry['row']->id;
            }
        }

        $this->fail("No line for {$key}.");
    }

    private function changeQuantity(Order $placed, string $key, int $quantity): void
    {
        app(OrderEditor::class)->apply(
            $placed->id(),
            (int) DB::table('orders')->where('id', $placed->id())->value('edit_revision'),
            [['change' => 'change_quantity', 'originatingLine' => $this->lineOf($placed, $key), 'quantity' => $quantity]],
            null,
            OrderPromotionCodeChange::unchanged(),
            null,
            null,
            null,
            new DateTimeImmutable('2026-09-30 10:00:00'),
        );
    }

    private function stock(string $key): int
    {
        return app(StockLevelRepository::class)->findByVariationId($this->variations[$key])->quantity();
    }

    /** @return array{Order, string, string} order, reversed original Alpha id, replacement Alpha id */
    private function editedOrder(): array
    {
        $order = $this->place();
        $original = $this->lineOf($order, 'A');
        $this->changeQuantity($order, 'A', 1);
        $replacement = $this->lineOf($order, 'A');

        $this->assertNotSame($original->id(), $replacement->id());
        $this->assertSame(49, $this->stock('A'), 'the edit returned one unit of Alpha to the shelf');

        return [$order, $original->id(), $replacement->id()];
    }

    /** @return array<int, object> */
    private function refundLines(): array
    {
        return DB::table('operational_sales_sale_lines')->where('type', 'refund')->orderBy('id')->get()->all();
    }

    public function test_cancelling_an_edited_order_restocks_the_post_edit_lines_not_the_original_ones(): void
    {
        [$order, $originalAlpha, $replacementAlpha] = $this->editedOrder();
        $betaId = $this->lineIdFor($order, 'B');

        app(OrderStatusChanger::class)->cancel($order->id(), new DateTimeImmutable('2026-10-01 10:00:00'), 'customer called');

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order->id())->value('status'));

        // Stock: Alpha 50 - 1 (after the edit) + 1 back = 50; Beta 50 - 3 + 3 = 50.
        // Reading the ORIGINAL line would have restocked 2 Alpha (51).
        $this->assertSame(50, $this->stock('A'));
        $this->assertSame(50, $this->stock('B'));

        $refunds = $this->refundLines();
        $byOrigin = [];
        foreach ($refunds as $refund) {
            $byOrigin[(string) $refund->originating_sale_line_id] = (int) $refund->quantity_returned;
        }

        $this->assertArrayNotHasKey($originalAlpha, $byOrigin, 'nothing is ever refunded against the reversed original');
        $this->assertSame(1, $byOrigin[$replacementAlpha]);
        $this->assertSame(3, $byOrigin[$betaId]);
        $this->assertCount(2, $refunds);
    }

    public function test_a_partial_return_on_an_edited_shipped_order_acts_on_the_replacement_line(): void
    {
        [$order, , $replacementAlpha] = $this->editedOrder();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);

        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $replacementAlpha, 'quantityReturned' => 1, 'restock' => true],
        ], new DateTimeImmutable('2026-10-01 10:00:00'));

        $this->assertSame('shipped', DB::table('orders')->where('id', $order->id())->value('status'), 'Beta is still outstanding, so no status moves');
        $this->assertSame(50, $this->stock('A'));
        $this->assertSame(47, $this->stock('B'));

        $refunds = $this->refundLines();
        $this->assertCount(1, $refunds);
        $this->assertSame($replacementAlpha, (string) $refunds[0]->originating_sale_line_id);
        $this->assertSame(1, (int) $refunds[0]->quantity_returned);
    }

    public function test_a_return_naming_the_reversed_original_line_is_refused_and_writes_nothing(): void
    {
        [$order, $originalAlpha] = $this->editedOrder();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);
        $stockBefore = $this->stock('A');

        try {
            app(OrderStatusChanger::class)->recordReturn($order->id(), [
                ['originatingSaleLineId' => $originalAlpha, 'quantityReturned' => 1, 'restock' => true],
            ], new DateTimeImmutable('2026-10-01 10:00:00'));
            $this->fail('A line an edit reversed away is no longer a line of the order.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not a current SALE line', $e->getMessage());
        }

        $this->assertSame([], $this->refundLines());
        $this->assertSame($stockBefore, $this->stock('A'));
    }

    public function test_a_return_in_full_of_an_edited_delivered_order_ends_it_refunded(): void
    {
        [$order, , $replacementAlpha] = $this->editedOrder();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'delivered']);

        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $replacementAlpha, 'quantityReturned' => 1, 'restock' => true],
            ['originatingSaleLineId' => $this->lineIdFor($order, 'B'), 'quantityReturned' => 3, 'restock' => true],
        ], new DateTimeImmutable('2026-10-01 10:00:00'));

        $this->assertSame('refunded', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(50, $this->stock('A'));
        $this->assertSame(50, $this->stock('B'));
    }

    public function test_cancelling_from_shipped_after_a_partial_return_of_a_replacement_line_takes_what_remains(): void
    {
        $order = $this->place();
        $this->changeQuantity($order, 'B', 2);
        $replacementBeta = $this->lineIdFor($order, 'B');
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);

        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $replacementBeta, 'quantityReturned' => 1, 'restock' => true],
        ], new DateTimeImmutable('2026-10-01 10:00:00'));

        // Cancelling from shipped now takes what REMAINS of the replacement (1 of 2), plus Alpha.
        app(OrderStatusChanger::class)->cancel($order->id(), new DateTimeImmutable('2026-10-02 10:00:00'));

        $this->assertSame('cancelled', DB::table('orders')->where('id', $order->id())->value('status'));
        $this->assertSame(50, $this->stock('A'));
        $this->assertSame(50, $this->stock('B'));
        $this->assertSame(2, (int) DB::table('operational_sales_sale_lines')->where('type', 'refund')
            ->where('originating_sale_line_id', $replacementBeta)->sum('quantity_returned'));
    }

    public function test_the_query_cost_of_cancel_is_reported_for_unedited_and_edited_orders(): void
    {
        $measure = function (callable $action): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            $action();

            return $count;
        };

        $plain = $this->place();
        $plainCancel = $measure(fn () => app(OrderStatusChanger::class)->cancel($plain->id(), new DateTimeImmutable('2026-10-01 10:00:00')));

        [$edited] = $this->editedOrder();
        $editedCancel = $measure(fn () => app(OrderStatusChanger::class)->cancel($edited->id(), new DateTimeImmutable('2026-10-01 10:00:00')));

        fwrite(STDERR, "\n[query-count] OrderStatusChanger::cancel() on a real 2-line order: unedited {$plainCancel} queries, edited once {$editedCancel} queries\n");

        // The edited order's lines live in two transactions, each a 2-query load.
        $this->assertLessThanOrEqual($plainCancel + 4, $editedCancel);
    }
}
