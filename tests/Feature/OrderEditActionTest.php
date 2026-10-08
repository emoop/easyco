<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\Exceptions\PromotionNoLongerValidException;
use App\Services\Exceptions\StaleOrderEditException;
use App\Services\OrderCurrentLinesResolver;
use App\Services\OrderDeliveryChange;
use App\Services\OrderEditor;
use App\Services\OrderPaymentConfirmer;
use App\Services\OrderPromotionCodeChange;
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
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Enums\OrderStatus;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Order\Order;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Promotions\Contracts\PromotionRedemptionRepository;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Promotion;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Livewire\Notifications;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * order-editing-design.md §8 (D7), stage 4b-i — OrderResource::editAction()
 * and its dialog, against real orders placed through the real
 * CheckoutOrchestrator and real services behind every submission.
 *
 * Fixture: "Alpha Widget" 10.00 x2 and "Beta Widget" 5.00 x3 (35.00),
 * cash on delivery, stock 50 each.
 *
 * HOW THE DIALOG IS DRIVEN: a mounted modal's content is not in
 * Livewire::test()'s html (the same finding OrderCancelReturnActionsTest
 * records), so each test mounts the action, reads the seeded form state from
 * mountedActions[0]['data'] (the exact state the form was built with),
 * modifies it with fillForm(), and submits with callMountedAction().
 */
class OrderEditActionTest extends TestCase
{
    use RefreshDatabase;

    private ?PriceList $priceList = null;

    /** @var array<string, string> */
    private array $variations = [];

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(strtolower(str_replace(' ', '.', $roleName)).'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsStaffRole(string $roleName): void
    {
        $this->actingAs($this->staffWithRole($roleName), 'staff');
        session()->forget('password_hash_staff');
    }

    /** @param Permission[] $permissions */
    private function actingAsCustomRole(array $permissions): void
    {
        $role = Role::create('Custom '.count($permissions), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom'.count($permissions).'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

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

    private function promotion(string $code, int $basisPoints = 1000, ?int $minimumSpendMinor = null): Promotion
    {
        $promotion = Promotion::create(
            code: $code,
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: $basisPoints,
            minimumSpend: $minimumSpendMinor !== null ? Money::fromMinorUnits($minimumSpendMinor, 'EUR') : null,
        );
        app(PromotionRepository::class)->save($promotion);

        return $promotion;
    }

    private function place(?string $code = null): Order
    {
        if ($this->variations === []) {
            $this->variation('A', 'Alpha Widget', '10.00');
            $this->variation('B', 'Beta Widget', '5.00');
        }

        $cart = Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
        app(CartLineAdder::class)->addLine($cart, $this->variations['A'], 2, null, null);
        app(CartLineAdder::class)->addLine($cart, $this->variations['B'], 3, null, null);

        if ($code !== null) {
            $cart->applyPromotionCode($code);
            app(CartRepository::class)->save($cart);
        }

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
        ), new DateTimeImmutable('2026-09-29 12:00:00'))->order();
    }

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications;
        $component->mount();

        return $component->notifications->last()?->getBody();
    }

    /**
     * The edit dialog's own trigger, as Filament's button really sends it
     * (stage 4b-ii, D5 moved the action into the "Items" section's own
     * header, so a bare mountAction('edit_order') no longer resolves — the
     * round trip carries the schema-component context
     * Action::getContext() builds, and this is that context).
     */
    private function editActionTarget(): TestAction
    {
        return TestAction::make('edit_order')->schemaComponent(true, 'infolist');
    }

    private function mount(Order $order): Testable
    {
        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $component->mountAction($this->editActionTarget());
        $this->assertNotEmpty($component->instance()->mountedActions, 'edit_order did not mount - check its ->visible() first.');

        return $component;
    }

    /** @return array<string, mixed> the seeded form state */
    private function seeded($component): array
    {
        return $component->instance()->mountedActions[0]['data'];
    }

    /**
     * Sets the named fixture lines' fields in the seeded `lines` state and
     * submits: $edits is productName => ['quantity' => ?, 'discount' => ?].
     *
     * @param  array<string, array<string, mixed>>  $edits
     */
    private function submitLines($component, array $edits, array $extra = []): void
    {
        $lines = $this->seeded($component)['lines'];

        foreach ($lines as $key => $row) {
            foreach ($edits as $name => $fields) {
                if (str_starts_with($row['product'], $name)) {
                    $lines[$key] = array_merge($row, $fields);
                }
            }
        }

        $component->fillForm(array_merge(['lines' => $lines], $extra))->callMountedAction();
    }

    private function row(string $orderId): object
    {
        return DB::table('orders')->where('id', $orderId)->first();
    }

    /** @return array<string, int> product name => quantity, for the order's current lines. */
    private function currentQuantities(Order $placed): array
    {
        $order = app(OrderRepository::class)->findById($placed->id());
        $out = [];

        foreach (app(OrderCurrentLinesResolver::class)->resolve($order) as $line) {
            $out[$line->productName()] = $line->quantity();
        }

        ksort($out);

        return $out;
    }

    // --- T2: visibility -----------------------------------------------------------

    /** @return array<string, array{string, string, bool}> */
    public static function visibilityMatrix(): array
    {
        $cases = [];

        foreach (['placed', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'] as $status) {
            foreach (['no payment', 'pending', 'settled'] as $payment) {
                $cases["{$status} / {$payment}"] = [$status, $payment, in_array($status, ['placed', 'confirmed'], true) && $payment !== 'settled'];
            }
        }

        return $cases;
    }

    #[DataProvider('visibilityMatrix')]
    public function test_the_edit_action_is_offered_only_before_shipping_and_before_money_settles(string $status, string $payment, bool $visible): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $paymentId = DB::table('payments')->where('order_id', $order->id())->value('id');

        if ($payment === 'no payment') {
            DB::table('payments')->where('order_id', $order->id())->delete();
        } elseif ($payment === 'settled') {
            app(OrderPaymentConfirmer::class)->confirm((string) $paymentId, new DateTimeImmutable('2026-09-30 10:00:00'));
        }

        DB::table('orders')->where('id', $order->id())->update(['status' => $status]);

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);

        if ($visible) {
            // Filament's own assertion, resolved through the exact context
            // its button sends (see editActionTarget()).
            $component->assertActionVisible($this->editActionTarget());
            // ... AND the rendered page really carries the trigger, in the
            // Items section's own header. Both halves matter: the first is
            // the framework's own answer, the second is the merchant's.
            $component->assertSee('edit_order', escape: false);

            return;
        }

        // THE HIDDEN HALF IS ASSERTED ON THE RENDERED PAGE, and that is not a
        // shortcut: a hidden schema-component action is NOT RESOLVABLE BY NAME
        // at all — verified against the installed v5.8.1 (Filament\Schemas\Concerns\
        // HasComponents::getAction() walks getComponents(), which filters hidden
        // components out, so getAction('edit_order', …) returns null and
        // assertActionHidden() itself throws ActionNotResolvableException).
        // What the merchant's browser receives is what this asserts: the
        // section is still painted, with no Edit button in it.
        $component->assertDontSee('edit_order', escape: false);
    }

    public function test_the_edit_action_needs_order_manage(): void
    {
        $order = $this->place();

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);
        Livewire::test(ViewOrder::class, ['record' => $order->id()])->assertDontSee('edit_order', escape: false);

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);
        Livewire::test(ViewOrder::class, ['record' => $order->id()])
            ->assertActionVisible($this->editActionTarget())
            ->assertSee('edit_order', escape: false);
    }

    /**
     * D5 (stage 4b-ii) — the edit trigger is no longer one of the page's own
     * header actions, and it IS one of the Items section's. This is the
     * relocation stated as two facts about the two places Filament could
     * paint it, with every other action in the page row untouched.
     */
    public function test_edit_is_a_section_header_action_not_a_page_header_action(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        // UI pass 1: the header is the "Add note" button and ONE "Actions" menu; orderActionList() is the flat list.
        $pageRows = array_map(
            static fn ($action): string => $action->getName(),
            OrderResource::orderActionList(),
        );

        $this->assertNotContains('edit_order', $pageRows);
        $this->assertSame(
            // The add-note button, then the menu's three sections: the order flow, the payment, returns and cancellation.
            ['add_note', 'confirm', 'ship', 'deliver', 'mark_as_received', 'accept_mismatch', 'correct_receipt', 'record_return', 'refund_money_only', 'cancel'],
            $pageRows,
        );

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);
        $pageHeaderNames = [];

        foreach ($component->instance()->getCachedHeaderActions() as $headerAction) {
            foreach ($headerAction instanceof \Filament\Actions\ActionGroup ? $headerAction->getFlatActions() : [$headerAction] as $action) {
                $pageHeaderNames[] = $action->getName();
            }
        }

        $this->assertNotContains('edit_order', $pageHeaderNames, 'Edit must not be a PAGE header action any more.');

        $items = $this->itemsSection($component->instance());

        $this->assertNotNull($items, 'The Items section must still exist on the View page.');
        $this->assertSame(
            ['edit_order'],
            array_map(static fn ($action): string => $action->getName(), $items->getHeaderActions()),
            'Edit must be the Items section\'s own header action.',
        );
    }

    /**
     * The infolist's own "Items" Section — found by the heading it renders, never by a guessed key. Since the
     * two-column layout it sits inside the grid's main-column group, so the search descends into containers.
     */
    private function itemsSection(mixed $livewire): ?Section
    {
        $walk = function (array $components) use (&$walk): ?Section {
            foreach ($components as $component) {
                if ($component instanceof Section && $component->getHeading() === __('orders.sections.lines')) {
                    return $component;
                }

                if (method_exists($component, 'getDefaultChildComponents')) {
                    $found = $walk($component->getDefaultChildComponents());

                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            return null;
        };

        return $walk($livewire->getSchema('infolist')->getComponents());
    }

    /** @return array<int, string> the line table's header labels. */
    private function lineTableHeaders(Order $order): array
    {
        $schema = (new ReflectionMethod(OrderResource::class, 'buildEditFormSchema'))->invoke(null, OrderModel::findOrFail($order->id()));

        foreach ($schema as $component) {
            foreach (method_exists($component, 'getDefaultChildComponents') ? $component->getDefaultChildComponents() : [] as $child) {
                if ($child instanceof Repeater) {
                    return array_map(fn ($column) => (string) $column->getLabel(), $child->getTableColumns());
                }
            }
        }

        $this->fail('no line table found');
    }

    public function test_the_discount_column_exists_only_for_order_discount(): void
    {
        $order = $this->place();
        $discountLabel = __('orders.actions.edit_discount');

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);
        $this->assertNotContains($discountLabel, $this->lineTableHeaders($order), 'the action is visible, the discount cell is absent');

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::ORDER_DISCOUNT]);
        $this->assertContains($discountLabel, $this->lineTableHeaders($order));
    }

    // --- T3: the line table ---------------------------------------------------------

    public function test_the_table_seeds_one_row_per_current_line_carrying_the_real_line_id(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $data = $this->seeded($this->mount($order));

        $realIds = DB::table('operational_sales_sale_lines')->where('transaction_id', $order->transactionId())->where('type', 'sale')->orderBy('id')->pluck('id')->map(fn ($i) => (string) $i)->all();

        $rows = array_values($data['lines']);
        $this->assertSame($realIds, array_column($rows, 'line_id'), 'the hidden field carries each line\'s real id, not the Repeater item key');
        $this->assertSame([2, 3], array_column($rows, 'quantity'));
        $this->assertSame(['2', '3'], array_column($rows, 'current_quantity'));
        $this->assertStringStartsWith('Alpha Widget', $rows[0]['product']);
        $this->assertSame('0.00', $rows[0]['discount']);
        $this->assertSame(0, $data['edit_revision']);
        $this->assertSame('Sofia', $data['delivery']['city']);
        $this->assertSame('street_address', $data['delivery']['delivery_type']);
    }

    public function test_after_an_edit_the_table_lists_the_replacement_lines_not_the_reversed_ones(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $original = $this->seeded($this->mount($order))['lines'];
        $originalAlphaId = array_values($original)[0]['line_id'];

        $this->submitLines($this->mount($order), ['Alpha' => ['quantity' => 1]]);

        // OrderAdminReader is bound scoped() and memoizes per instance; one test process is
        // one "request", so a fresh page view needs a fresh scope (production redirects).
        app()->forgetScopedInstances();
        $after = $this->seeded($this->mount($order));
        $rows = array_values($after['lines']);

        $this->assertCount(2, $rows);
        $this->assertNotContains($originalAlphaId, array_column($rows, 'line_id'));
        $this->assertSame(1, $after['edit_revision']);
        // Current lines are listed by id, so a replaced line moves behind the untouched one.
        $this->assertSame([3, 1], array_column($rows, 'quantity'), 'each row shows its CURRENT quantity, the ceiling of its own input');
    }

    public function test_a_row_quantity_cannot_be_raised_above_the_current_quantity(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Alpha' => ['quantity' => 5]]);

        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
    }

    // --- T4: submissions ------------------------------------------------------------

    public function test_reducing_a_quantity(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);
        $this->submitLines($component, ['Alpha' => ['quantity' => 1]]);
        $component->assertNotified(__('orders.actions.edit_done', ['id' => $order->id()]));

        $this->assertSame(['Alpha Widget' => 1, 'Beta Widget' => 3], $this->currentQuantities($order));
        $row = $this->row($order->id());
        $this->assertSame(2500, (int) $row->total_minor);
        $this->assertSame(1, (int) $row->edit_revision);
        $this->assertSame(2500, (int) DB::table('payments')->where('order_id', $order->id())->whereNull('voided_at')->value('amount_minor'));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $order->id())->where('type', 'edited')->count());
        $this->assertNotNull(DB::table('order_events')->where('order_id', $order->id())->where('type', 'edited')->value('staff_name'), 'the editing staff member is recorded');
    }

    public function test_reducing_a_line_to_zero_removes_it(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Beta' => ['quantity' => 0]]);

        $this->assertSame(['Alpha Widget' => 2], $this->currentQuantities($order));
        $this->assertSame(2000, (int) $this->row($order->id())->total_minor);
        $this->assertSame(50, app(StockLevelRepository::class)->findByVariationId($this->variations['B'])->quantity());
    }

    public function test_changing_only_a_discretionary_discount(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Beta' => ['discount' => '3.00']]);

        $row = $this->row($order->id());
        $this->assertSame(300, (int) $row->discount_minor);
        $this->assertSame(3200, (int) $row->total_minor);
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
    }

    public function test_quantity_and_discount_changed_together_on_one_row_become_one_replacement_line(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Beta' => ['quantity' => 2, 'discount' => '1.00']]);

        $row = $this->row($order->id());
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 2], $this->currentQuantities($order));
        $this->assertSame(100, (int) $row->discount_minor);
        $this->assertSame(2000 + 1000 - 100, (int) $row->total_minor);
        $this->assertSame(1, DB::table('operational_sales_sale_lines')->where('type', 'edit_reversal')->count(), 'one reversal for the row, not two');
    }

    public function test_a_discount_is_ignored_without_order_discount(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE]);
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Beta' => ['discount' => '3.00']]);

        $this->assertSame(0, (int) $this->row($order->id())->discount_minor);
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(__('orders.actions.edit_nothing_to_change'), $this->lastNotificationBody());
    }

    /**
     * INPUT HARDENING (third pass, 3a): the discount cell carries a rule of its own
     * (OrderResource::discountRules()) whose ceiling is MoneyInput's — at most nine
     * integer digits and no more decimals than the currency uses — and this is what
     * a merchant sees when they type past it: a FIELD error on the cell, with the
     * order untouched. Before that rule the cell accepted a 19-digit amount and
     * OrderEditFormMapper::lineChanges() handed the text to Money::fromDecimal(),
     * whose own `(int) $digits` cast saturated SILENTLY to PHP_INT_MAX minor units
     * of discount — a number nobody typed, saved onto a real order. The cell
     * refuses the shape now, and the mapper refuses it independently, because a
     * form value is only ever a request (see OrderEditFormMapperTest).
     */
    public function test_a_discount_the_app_cannot_read_is_a_field_error_and_the_order_is_untouched(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        // Nine integer digits fit; the tenth does not, and neither does the
        // float-cast shape a numeric box's own state cast can produce.
        foreach (['1000000000.00', '1.0E+19'] as $unreadable) {
            $component = $this->mount($order);
            $this->submitLines($component, ['Beta' => ['discount' => $unreadable]]);

            $component->assertHasFormErrors();
            $this->assertContains(
                __('orders.actions.edit_invalid_discount'),
                collect($component->instance()->getErrorBag()->all())->flatten()->all(),
                "{$unreadable} must be refused on the discount cell itself.",
            );

            $row = $this->row($order->id());
            $this->assertSame(0, (int) $row->discount_minor);
            $this->assertSame(0, (int) $row->edit_revision);
        }

        // The same cell with an amount the app reads still goes through: the refusal
        // above is the amount's shape, not the field and not the permission.
        $this->submitLines($this->mount($order), ['Beta' => ['discount' => '3.00']]);

        $this->assertSame(300, (int) $this->row($order->id())->discount_minor);
    }

    public function test_changing_delivery_only(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $delivery = $this->seeded($component)['delivery'];
        $delivery['city'] = 'Plovdiv';
        $delivery['carrier_code'] = 'econt';

        $component->fillForm(['delivery' => $delivery])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('Plovdiv', $row->city);
        $this->assertSame('econt', $row->carrier_code);
        $this->assertSame(1, (int) $row->edit_revision);
        $this->assertSame(3500, (int) $row->total_minor);
        $this->assertNull(DB::table('order_events')->where('order_id', $order->id())->where('type', 'edited')->value('transaction_id'));
        $this->assertCount(1, DB::table('payments')->where('order_id', $order->id())->get(), 'no money change, no payment change');
    }

    public function test_switching_to_a_pickup_point(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);

        $component->fillForm(['delivery' => [
            'delivery_type' => 'pickup_point',
            'recipient_name' => 'Guest Buyer',
            'phone' => '+359888000000',
            'country' => 'GR',
            'carrier_code' => 'speedy',
            'pickup_point_reference' => 'office-7',
            'settlement' => 'Athens',
        ]])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('pickup_point', $row->delivery_type);
        $this->assertNull($row->city);
        $this->assertSame('GR', $row->country, 'a pickup point keeps a country of its own (owner decision D1)');
        $this->assertSame('office-7', $row->pickup_point_reference);
    }

    public function test_the_edit_form_refuses_a_country_that_is_not_in_the_list(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $delivery = $this->seeded($component)['delivery'];
        $delivery['country'] = 'ZZ';

        $component->fillForm(['delivery' => $delivery])->callMountedAction()->assertHasFormErrors();

        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
    }

    public function test_setting_a_brand_new_promotion_code(): void
    {
        $this->actingAsStaffRole('Administrator');
        $promotion = $this->promotion('ten', 1000);
        $order = $this->place();

        $this->mount($order)->fillForm(['promotion_code' => 'TEN'])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertSame('ten', $row->applied_promotion_code);
        $this->assertSame(350, (int) $row->discount_minor);
        $this->assertSame(1, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
    }

    public function test_removing_the_current_code_with_the_toggle(): void
    {
        $this->actingAsStaffRole('Administrator');
        $promotion = $this->promotion('ten', 1000);
        $order = $this->place('ten');
        $this->assertSame('ten', $this->seeded($this->mount($order))['promotion_code']);

        $this->mount($order)->fillForm(['remove_promotion_code' => true])->callMountedAction();

        $row = $this->row($order->id());
        $this->assertNull($row->applied_promotion_code);
        $this->assertSame(0, (int) $row->discount_minor);
        $this->assertSame(0, app(PromotionRedemptionRepository::class)->countForPromotion($promotion->id()));
    }

    public function test_a_no_op_submission_is_refused_before_the_service_and_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $events = DB::table('order_events')->count();

        $this->mount($order)->fillForm(['reason' => 'just looking'])->callMountedAction();

        $this->assertSame(__('orders.actions.edit_nothing_to_change'), $this->lastNotificationBody());
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame($events, DB::table('order_events')->count());
    }

    public function test_the_reason_is_recorded_on_the_edited_event(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Alpha' => ['quantity' => 1]], ['reason' => 'customer phoned']);

        $this->assertSame('customer phoned', DB::table('order_events')->where('order_id', $order->id())->where('type', 'edited')->value('reason'));
    }

    // --- T5: refusals, each against the real notification text ------------------------

    public function test_a_stale_revision_is_refused_with_the_start_again_message(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);

        // Another operator edits the order after this dialog opened.
        app(OrderEditor::class)->apply($order->id(), 0, [], new OrderDeliveryChange(
            OrderDeliveryType::STREET_ADDRESS, 'Guest Buyer', '+359888000000', 'BG', 'Varna', null, 'Sea Garden 1',
        ), OrderPromotionCodeChange::unchanged(), null, null, null, new DateTimeImmutable('2026-09-30 11:00:00'));

        $this->submitLines($component, ['Alpha' => ['quantity' => 1]]);

        $this->assertSame(__('orders.actions.edit_stale_body', ['id' => $order->id(), 'expected' => 0, 'actual' => 1]), $this->lastNotificationBody());
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order), 'the stale submission wrote nothing');
        $this->assertSame(1, (int) $this->row($order->id())->edit_revision);
    }

    public function test_a_payment_settled_between_opening_and_submitting_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);

        app(OrderPaymentConfirmer::class)->confirm((string) DB::table('payments')->where('order_id', $order->id())->value('id'), new DateTimeImmutable('2026-09-30 11:00:00'));

        $this->submitLines($component, ['Alpha' => ['quantity' => 1]]);

        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        // Either Filament declines the now-hidden action (nothing shown), or the service's own refusal is read
        // as the settled-payment text. Which one happens depends on whether the page's memoized read is stale,
        // so the wording itself is pinned directly in test_each_service_refusal_has_its_own_translated_text().
        $body = $this->lastNotificationBody();
        $this->assertTrue($body === null || $body === __('orders.actions.edit_not_editable_payment_body', ['id' => $order->id()]), (string) $body);
    }

    public function test_the_status_refusal_names_the_status(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        DB::table('orders')->where('id', $order->id())->update(['status' => 'shipped']);

        $this->submitLines($component, ['Alpha' => ['quantity' => 1]]);

        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $body = $this->lastNotificationBody();
        $this->assertTrue($body === null || $body === __('orders.actions.edit_not_editable_status_body', ['id' => $order->id(), 'status' => __('orders.status_options.shipped')]), (string) $body);
    }

    public function test_a_kept_code_that_the_edit_invalidates_is_refused_with_its_reason(): void
    {
        $this->actingAsStaffRole('Administrator');
        $this->promotion('min30', 1000, minimumSpendMinor: 3000);
        $order = $this->place('min30');

        $this->submitLines($this->mount($order), ['Beta' => ['quantity' => 0]]);

        $this->assertSame(
            __('orders.actions.edit_promotion_invalid_body', ['code' => 'min30', 'reason' => __('orders.promotion_refusal_reasons.minimum_spend_not_met')]),
            $this->lastNotificationBody(),
        );
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));

        // The same edit with the removal toggle succeeds.
        $this->submitLines($this->mount($order), ['Beta' => ['quantity' => 0]], ['remove_promotion_code' => true]);
        $this->assertSame(['Alpha Widget' => 2], $this->currentQuantities($order));
        $this->assertNull($this->row($order->id())->applied_promotion_code);
    }

    public function test_an_unknown_new_code_is_refused_with_its_reason(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->mount($order)->fillForm(['promotion_code' => 'NOPE'])->callMountedAction();

        $this->assertSame(
            __('orders.actions.edit_promotion_invalid_body', ['code' => 'NOPE', 'reason' => __('orders.promotion_refusal_reasons.not_found')]),
            $this->lastNotificationBody(),
        );
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
    }

    public function test_an_expired_new_code_is_refused_with_its_reason(): void
    {
        $this->actingAsStaffRole('Administrator');
        $promotion = Promotion::create(
            code: 'old',
            discountType: PromotionDiscountType::PERCENTAGE,
            percentageBasisPoints: 1000,
            validUntil: new DateTimeImmutable('2020-01-01'),
        );
        app(PromotionRepository::class)->save($promotion);
        $order = $this->place();

        $this->mount($order)->fillForm(['promotion_code' => 'old'])->callMountedAction();

        $this->assertSame(
            __('orders.actions.edit_promotion_invalid_body', ['code' => 'old', 'reason' => __('orders.promotion_refusal_reasons.expired')]),
            $this->lastNotificationBody(),
        );
    }

    /**
     * runOrderAction()'s three new branches, driven directly with each exception the
     * service can raise, so the wording is pinned whichever way Filament's own
     * visibility re-check races the submission.
     */
    public function test_each_service_refusal_has_its_own_translated_text(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $record = OrderModel::findOrFail($order->id());
        $run = new ReflectionMethod(OrderResource::class, 'runOrderAction');
        $livewire = new class
        {
            public function redirect(string $url): void {}
        };

        $cases = [
            [new StaleOrderEditException($order->id(), 3, 5),
                __('orders.actions.edit_stale_body', ['id' => $order->id(), 'expected' => 3, 'actual' => 5])],
            [OrderNotEditableException::becausePaymentSettled(OrderStatus::PLACED),
                __('orders.actions.edit_not_editable_payment_body', ['id' => $order->id()])],
            [OrderNotEditableException::because(OrderStatus::DELIVERED),
                __('orders.actions.edit_not_editable_status_body', ['id' => $order->id(), 'status' => __('orders.status_options.delivered')])],
            [new PromotionNoLongerValidException('summer', 'usage_limit_reached'),
                __('orders.actions.edit_promotion_invalid_body', ['code' => 'summer', 'reason' => __('orders.promotion_refusal_reasons.usage_limit_reached')])],
            [new PromotionNoLongerValidException('summer', 'some_future_reason'),
                __('orders.actions.edit_promotion_invalid_body', ['code' => 'summer', 'reason' => 'some_future_reason'])],
        ];

        foreach ($cases as [$exception, $expected]) {
            $run->invoke(null, $record, $livewire, function () use ($exception): void {
                throw $exception;
            }, 'ok');

            $this->assertSame($expected, $this->lastNotificationBody());
        }
    }

    public function test_every_refusal_text_exists_in_both_languages(): void
    {
        $keys = ['edit', 'edit_heading', 'edit_description', 'edit_done', 'edit_lines_hint', 'edit_current_quantity', 'edit_new_quantity', 'edit_discount',
            'edit_promotion_code', 'edit_promotion_code_hint', 'edit_remove_promotion_code', 'edit_nothing_to_change', 'edit_stale_body',
            'edit_not_editable_status_body', 'edit_not_editable_payment_body', 'edit_promotion_invalid_body',
            // Add a product (stage 4b-ii), including the merge hint the
            // same-variation refinement added.
            'edit_add_heading', 'edit_add_hint', 'edit_add_product_placeholder', 'edit_add_quantity', 'edit_add_no_price', 'edit_add_unavailable',
            'edit_add_unavailable_body', 'edit_add_no_price_body', 'edit_add_insufficient_stock_body', 'edit_add_merge_hint'];
        $reasons = ['not_found', 'inactive', 'not_yet_active', 'expired', 'minimum_spend_not_met', 'maximum_spend_exceeded', 'new_customers_only',
            'account_scope_mismatch', 'usage_limit_reached', 'usage_limit_per_customer_reached', 'no_matching_lines'];

        foreach (['en', 'bg'] as $locale) {
            foreach ($keys as $key) {
                $this->assertTrue(Lang::has("orders.actions.{$key}", $locale, false), "{$locale}: orders.actions.{$key}");
            }
            foreach ($reasons as $reason) {
                $this->assertTrue(Lang::has("orders.promotion_refusal_reasons.{$reason}", $locale, false), "{$locale}: {$reason}");
            }
        }
    }

    // --- T7: query counts --------------------------------------------------------------

    public function test_the_query_cost_of_opening_and_submitting_the_dialog_is_reported(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = Livewire::test(ViewOrder::class, ['record' => $order->id()]);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $component->mountAction($this->editActionTarget());
        $mountCost = $count;

        $count = 0;
        $lines = $this->seeded($component)['lines'];
        foreach ($lines as $key => $row) {
            if (str_starts_with($row['product'], 'Alpha')) {
                $lines[$key]['quantity'] = 1;
            }
        }
        $component->fillForm(['lines' => $lines]);
        $fillCost = $count;

        $count = 0;
        $component->callMountedAction();
        $submitCost = $count;

        fwrite(STDERR, "\n[query-count] order edit dialog (2-line order): mount {$mountCost} queries, form fill {$fillCost}, submit (1 line reduced, payment reissued, incl. redirect-side work) {$submitCost}\n");

        $this->assertSame(['Alpha Widget' => 1, 'Beta Widget' => 3], $this->currentQuantities($order));
    }

    /**
     * The remove/restore control is the LAST cell of a row, and the table's
     * columns and the row's cells stay the same length — with the discount
     * column and without it.
     */
    public function test_the_row_control_is_the_last_cell_with_and_without_the_discount_permission(): void
    {
        foreach ([
            'with ORDER_DISCOUNT' => [[Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::ORDER_DISCOUNT], ['lines.%s.discount', 5]],
            'without ORDER_DISCOUNT' => [[Permission::ORDER_VIEW, Permission::ORDER_MANAGE], [null, 4]],
        ] as $case => [$permissions, [$discountKey, $columnCount]]) {
            $this->actingAsCustomRole($permissions);
            $order = $this->place();
            $component = $this->mount($order);

            $repeater = $component->instance()->getSchema('mountedActionSchema0')->getComponent('lines');
            $columns = $repeater->getTableColumns();
            $rowKey = array_key_first($this->seeded($component)['lines']);
            $cells = array_values(array_filter(
                $repeater->getChildSchemas()[$rowKey]->getComponents(),
                static fn ($cell): bool => ! $cell instanceof \Filament\Forms\Components\Hidden,
            ));

            $this->assertCount($columnCount, $columns, $case);
            $this->assertCount($columnCount, $cells, "{$case}: one cell per column");
            $this->assertInstanceOf(\Filament\Schemas\Components\Actions::class, end($cells), "{$case}: the control is the last cell");
            $this->assertSame('lineControls', substr((string) end($cells)->getKey(), -12));
            $this->assertSame(
                $discountKey !== null,
                str_ends_with((string) $cells[count($cells) - 2]->getKey(), '.discount'),
                "{$case}: the discount cell, when present, sits immediately before the control",
            );
        }
    }

    /**
     * An EDITED event carries the edit's own transaction, exactly as a return
     * does — so the history's goods cell serves both. An edit's transaction
     * holds what it took off AND what it put on, and the reader marks which.
     */
    public function test_an_edited_event_carries_a_transaction_whose_goods_are_split_into_removed_and_added(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $this->submitLines($this->mount($order), ['Beta' => ['quantity' => 1]]);

        app()->forgetScopedInstances();
        $edited = collect(app(\App\Services\OrderAdminReader::class)->forOrder($order->id())->events)->firstWhere('type', 'edited');

        $this->assertNotNull($edited->transactionId, 'an edit writes a transaction onto its event');
        $kinds = array_map(static fn (array $line): string => $line['kind'].':'.$line['quantity'], $edited->movedLines);
        sort($kinds);

        $this->assertSame(['added:1', 'removed:3'], $kinds, 'Beta 3 -> 1: three units taken off, one put back on');
        $this->assertSame(['Beta Widget'], array_values(array_unique(array_map(static fn (array $line): string => (string) $line['name'], $edited->movedLines))), 'and both name the product');
    }
}
