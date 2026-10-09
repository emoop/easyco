<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderAdminReader;
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
use EasyCo\OperationalSales\SaleLine;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\TestCase;

/**
 * order-editing-design.md §4.4, stage 4a — the order's CURRENT lines, resolved
 * once (App\Services\OrderCurrentLinesResolver) and shown by the admin
 * reader/View page. Every order here is placed through the REAL
 * CheckoutOrchestrator and edited through the REAL OrderEditor, never built
 * from hand-written rows.
 *
 * Fixture: "Alpha Widget" at 10.00 and "Beta Widget" at 5.00; the default
 * order is Alpha x2 + Beta x3.
 */
class OrderCurrentLinesTest extends TestCase
{
    use RefreshDatabase;
    use ProvidesCheckoutShipping;

    private ?PriceList $priceList = null;

    /** @var array<string, string> */
    private array $variations = [];

    private function variation(string $key, string $name, string $price): void
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper($key), 'slug-'.strtolower($key).'-'.strtolower(Str::random(4)));
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
        return app(\EasyCo\Order\Contracts\OrderRepository::class)->findById($placed->id());
    }

    private function resolver(): OrderCurrentLinesResolver
    {
        return app(OrderCurrentLinesResolver::class);
    }

    /** @return array<int, SaleLine> */
    private function lines(Order $placed): array
    {
        return $this->resolver()->resolve($this->order($placed));
    }

    private function lineOf(Order $placed, string $key): SaleLine
    {
        foreach ($this->lines($placed) as $line) {
            if ($line->priceableId() === $this->variations[$key]) {
                return $line;
            }
        }

        $this->fail("No current line for {$key}.");
    }

    private function changeQuantity(Order $placed, string $key, int $quantity): void
    {
        $this->edit($placed, [['change' => 'change_quantity', 'originatingLine' => $this->lineOf($placed, $key), 'quantity' => $quantity]]);
    }

    /** @param array<int, array<string, mixed>> $changes */
    private function edit(Order $placed, array $changes): void
    {
        app(OrderEditor::class)->apply(
            $placed->id(),
            (int) DB::table('orders')->where('id', $placed->id())->value('edit_revision'),
            $changes,
            null,
            OrderPromotionCodeChange::unchanged(),
            null,
            null,
            null,
            new DateTimeImmutable('2026-09-30 10:00:00'),
        );
    }

    /** @return array<string, int> variation key => quantity, as the reader's Lines section shows them. */
    private function shownQuantities(Order $placed): array
    {
        app()->forgetScopedInstances();
        $shown = [];

        foreach (app(OrderAdminReader::class)->forOrder($placed->id())->lines as $line) {
            $shown[$line->productName] = $line->quantity;
        }

        ksort($shown);

        return $shown;
    }

    // --- the resolver ------------------------------------------------------------

    public function test_an_order_with_no_edits_resolves_exactly_its_placement_lines(): void
    {
        $order = $this->place();

        $placement = DB::table('operational_sales_sale_lines')->where('transaction_id', $order->transactionId())->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->assertSame($placement, array_map(fn (SaleLine $l) => $l->id(), $this->lines($order)));
        $this->assertSame($placement, array_map(fn (array $e) => (string) $e['row']->id, $this->resolver()->resolveRows($this->order($order))));
    }

    public function test_an_order_edited_once_resolves_the_new_line_not_the_reversed_original(): void
    {
        $order = $this->place();
        $originalA = $this->lineOf($order, 'A');
        $originalB = $this->lineOf($order, 'B');

        $this->changeQuantity($order, 'A', 3);

        $lines = $this->lines($order);
        $this->assertCount(2, $lines);

        $newA = $this->lineOf($order, 'A');
        $this->assertNotSame($originalA->id(), $newA->id(), 'the reversed original is gone');
        $this->assertSame(3, $newA->quantity());
        $this->assertSame($originalA->id(), $newA->originatingSaleLineId());
        $this->assertSame($originalB->id(), $this->lineOf($order, 'B')->id(), 'an untouched line is still the placement line');
    }

    public function test_an_order_edited_twice_on_the_same_line_resolves_only_the_latest_state(): void
    {
        $order = $this->place();

        $this->changeQuantity($order, 'A', 3);
        $afterFirst = $this->lineOf($order, 'A');
        $this->changeQuantity($order, 'A', 1);

        $lines = array_values(array_filter($this->lines($order), fn (SaleLine $l) => $l->priceableId() === $this->variations['A']));
        $this->assertCount(1, $lines);
        $this->assertSame(1, $lines[0]->quantity());
        $this->assertNotSame($afterFirst->id(), $lines[0]->id());
        $this->assertCount(2, $this->lines($order));
    }

    public function test_a_removed_line_is_not_current(): void
    {
        $order = $this->place();

        $this->edit($order, [['change' => 'remove', 'originatingLine' => $this->lineOf($order, 'B')]]);

        $this->assertSame([$this->variations['A']], array_map(fn (SaleLine $l) => $l->priceableId(), $this->lines($order)));
    }

    public function test_a_fully_returned_line_stays_a_row_but_is_not_editable(): void
    {
        $order = $this->place();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);
        $b = $this->lineOf($order, 'B');

        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $b->id(), 'quantityReturned' => 3, 'restock' => true],
        ], new DateTimeImmutable('2026-10-01 10:00:00'));

        $rows = $this->resolver()->resolveRows($this->order($order));
        $this->assertCount(2, $rows, 'a returned-in-full line is still a line of the order');
        $byId = [];
        foreach ($rows as $entry) {
            $byId[(string) $entry['row']->id] = $entry;
        }
        $this->assertSame(3, $byId[$b->id()]['returned']);

        $this->assertSame([$this->variations['A']], array_map(fn (SaleLine $l) => $l->priceableId(), $this->lines($order)), 'remaining = 0, so it is not one an edit can act on');
    }

    public function test_the_resolver_costs_a_constant_number_of_queries_however_many_lines(): void
    {
        $order = $this->place();
        $this->changeQuantity($order, 'A', 3);
        $orderAfter = $this->order($order);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->resolver()->resolveRows($orderAfter);
        $rowsCost = $count;

        $count = 0;
        $this->resolver()->resolve($orderAfter);
        $resolveCost = $count;

        fwrite(STDERR, "\n[query-count] OrderCurrentLinesResolver: resolveRows() {$rowsCost} queries; resolve() {$resolveCost} queries (2 transactions hold current lines)\n");

        // events + lines + returned + edited-away.
        $this->assertSame(4, $rowsCost);
        $this->assertSame(4 + 2 * 2, $resolveCost, 'TransactionRepository::findByIdWithSaleLines() is two queries per transaction that still holds a current line');
    }

    // --- the reader and the View page ------------------------------------------------

    public function test_the_reader_shows_the_true_current_lines_after_an_edit(): void
    {
        $order = $this->place();

        $before = $this->shownQuantities($order);
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $before);

        $this->changeQuantity($order, 'A', 5);
        $this->edit($order, [['change' => 'remove', 'originatingLine' => $this->lineOf($order, 'B')]]);

        $after = $this->shownQuantities($order);

        // The core regression: before this stage the reader kept showing the
        // placement transaction's own lines — Alpha x2 and Beta x3.
        $this->assertSame(['Alpha Widget' => 5], $after);
    }

    public function test_remaining_editable_is_correct_for_an_edited_line_and_an_untouched_one(): void
    {
        $order = $this->place();
        $this->changeQuantity($order, 'A', 4);

        app()->forgetScopedInstances();
        $lines = [];
        foreach (app(OrderAdminReader::class)->forOrder($order->id())->lines as $line) {
            $lines[$line->productName] = $line;
        }

        $this->assertSame(4, $lines['Alpha Widget']->quantity);
        $this->assertSame(4, $lines['Alpha Widget']->remainingEditable, 'the replacement line: nothing of it has been edited away or returned');
        $this->assertSame(4, $lines['Alpha Widget']->remainingReturnable, 'remainingReturnable keeps its return-only meaning');
        $this->assertSame(3, $lines['Beta Widget']->remainingEditable);
        $this->assertSame(3, $lines['Beta Widget']->remainingReturnable);
    }

    public function test_remaining_editable_and_returnable_diverge_only_by_what_each_operation_has_taken(): void
    {
        $order = $this->place();
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);
        app(OrderStatusChanger::class)->recordReturn($order->id(), [
            ['originatingSaleLineId' => $this->lineOf($order, 'B')->id(), 'quantityReturned' => 1, 'restock' => true],
        ], new DateTimeImmutable('2026-10-01 10:00:00'));

        app()->forgetScopedInstances();
        $lines = [];
        foreach (app(OrderAdminReader::class)->forOrder($order->id())->lines as $line) {
            $lines[$line->productName] = $line;
        }

        $this->assertSame(2, $lines['Beta Widget']->remainingReturnable);
        $this->assertSame(2, $lines['Beta Widget']->remainingEditable, 'a return also takes units an edit could have changed');
        $this->assertSame(2, $lines['Alpha Widget']->remainingEditable);
    }

    public function test_the_view_page_lists_the_current_lines_after_an_edit(): void
    {
        $roleRepository = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roleRepository);
        $role = $roleRepository->findSystemRoleByName('Administrator');
        $staff = Staff::create('admin.lines@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');

        $order = $this->place();
        $url = OrderResource::getUrl('view', ['record' => $order->id()]);

        $this->get($url)->assertOk()->assertSee('Alpha Widget')->assertSee('Beta Widget');

        $this->edit($order, [['change' => 'remove', 'originatingLine' => $this->lineOf($order, 'B')]]);
        app()->forgetScopedInstances();

        // The CURRENT lines no longer list the removed product. The History section below
        // now names the goods an edit took off (that is its job), so the check is scoped to
        // what comes before it instead of the whole page.
        $html = $this->get($url)->assertOk()->getContent();
        $linesPart = substr($html, 0, (int) strpos($html, __('orders.sections.history')));

        $this->assertStringContainsString('Alpha Widget', $linesPart);
        $this->assertStringNotContainsString('Beta Widget', $linesPart);
        $this->assertStringContainsString('Beta Widget', substr($html, strlen($linesPart)), 'and the history names what the edit removed');
    }

    public function test_the_view_page_query_cost_is_reported_and_does_not_grow_with_edits(): void
    {
        $roleRepository = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roleRepository);
        $role = $roleRepository->findSystemRoleByName('Administrator');
        $staff = Staff::create('admin.cost@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');

        $order = $this->place();
        $url = OrderResource::getUrl('view', ['record' => $order->id()]);

        $measure = function () use ($url): int {
            $count = 0;
            DB::listen(function () use (&$count): void {
                $count++;
            });
            $this->get($url)->assertOk();

            return $count;
        };

        // Placing the order ran the shipping quote pipeline (stage 4e), which warms scoped memoised reads; forget them so
        // all three measurements start cold, exactly as the later two already do.
        app()->forgetScopedInstances();
        $unedited = $measure();
        $this->changeQuantity($order, 'A', 3);
        app()->forgetScopedInstances();
        $editedOnce = $measure();
        $this->changeQuantity($order, 'A', 4);
        $this->changeQuantity($order, 'B', 1);
        app()->forgetScopedInstances();
        $edited = $measure();

        fwrite(STDERR, "\n[query-count] order view page: never edited {$unedited} queries, after three edits {$edited} queries\n");

        // Edits add no read of their own to the page: the edit transactions are found by ONE
        // events read, and the lines of all of them by ONE lines read. The history's goods cell
        // adds ONE grouped read as soon as ANY event carries a transaction (first edit: +1) and
        // never another one for further edits.
        $this->assertSame($unedited + 1, $editedOnce, 'the first edit adds the one batched goods read');
        $this->assertSame($editedOnce, $edited, 'and two more edits add nothing');
    }

    public function test_a_delivery_only_edit_writes_an_event_with_no_transaction_and_changes_no_lines(): void
    {
        $order = $this->place();

        $this->edit($order, []);

        $this->assertNull(DB::table('order_events')->where('order_id', $order->id())->where('type', 'edited')->value('transaction_id'));
        $this->assertCount(2, $this->lines($order));
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->shownQuantities($order));
    }
}
