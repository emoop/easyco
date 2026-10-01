<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderAddLinePricer;
use App\Services\OrderCurrentLinesResolver;
use App\Services\OrderEditor;
use App\Services\OrderLineProductSearch;
use App\Services\OrderPromotionCodeChange;
use App\Services\VariationDisplayReader;
use DateTimeImmutable;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\SaleLine;
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
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Livewire\Notifications;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * order-editing-design.md §1/E2's first editable fact — "add a product/
 * variation with a quantity" — stage 4b-ii: its picker
 * (App\Services\OrderLineProductSearch), its section in the edit dialog, its
 * ADD entry (App\Services\OrderAddLinePricer), and the trigger's own move
 * into the Items section's header.
 *
 * Fixture: "Alpha Widget" 10.00 x2 and "Beta Widget" 5.00 x3 (35.00), cash on
 * delivery, stock 50 each, placed through the real CheckoutOrchestrator.
 * Every submission below runs the REAL services — the dialog is only the
 * input.
 *
 * THE DIALOG IS DRIVEN EXACTLY AS OrderEditActionTest does: mount the
 * action (through the schema-component context its own button sends),
 * read the seeded state from mountedActions[0]['data'], fillForm(), then
 * callMountedAction().
 */
class OrderEditAddLineTest extends TestCase
{
    use RefreshDatabase;

    private ?PriceList $priceList = null;

    /** @var array<string, string> */
    private array $variations = [];

    /** @var array<string, PriceListItem> variationId => its own price list item */
    private array $priceItems = [];

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

    /**
     * One SIMPLE product with a priced universal variation and stock.
     *
     * @return string the variation id — the priceableId an ADD names.
     */
    private function variation(string $key, string $name, string $price, int $stock = 50, ?string $sku = null): string
    {
        $sku ??= 'SKU-'.strtoupper($key);
        $product = Product::createSimple($name, $sku, 'slug-'.strtolower($key).'-'.strtolower(Str::random(4)));
        // A merchant can only add what is on sale: published exactly as the
        // panel's own Publish action would (ProductResource's list defaults to
        // the ACTIVE status view, and this picker mirrors it).
        $product->publish();
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->price((string) $variationId, $price, $stock);

        $this->variations[$key] = $variationId;

        return $variationId;
    }

    /** A price list item for one variation (plus stock), reusing ONE system list. */
    private function price(string $variationId, string $price, int $stock = 50): void
    {
        if ($this->priceList === null) {
            $this->priceList = PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0);
            app(PriceListRepository::class)->save($this->priceList);
        }

        $item = new PriceListItem(
            null,
            $this->priceList->id(),
            PriceListItemTargetType::VARIATION,
            $variationId,
            Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
        );

        app(PriceListItemRepository::class)->save($item);
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $stock));

        $this->priceItems[$variationId] = $item;
    }

    /**
     * A VARIABLE product with two priced variations, so the picker's own
     * variation-level results can be told apart — the fixture D1's choice
     * (b) exists for.
     *
     * @return array<string, string> `base` => the parent's own base_sku (what
     *                              a label leads with now), then sku => variationId
     */
    private function variableProduct(string $name = 'T-Shirt', string $barcodeForBlack = '1234567890'): array
    {
        // The definition code is UNIQUE, and the batching test below calls this
        // helper twice in one test — so the code carries the same kind of random
        // suffix the base_sku and the slug already do, and the axis is still a
        // plainly-named SELECT either way.
        $definition = new AttributeDefinition(id: null, code: 'color-'.strtolower(Str::random(6)), name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $baseSku = 'SKU-'.strtoupper(Str::random(8));
        $product = Product::createVariable($name, $baseSku, 't-shirt-'.strtolower(Str::random(6)));
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);

        $blackVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'TSHIRT-BLACK');
        $blackVariation->activate();
        $blackVariation->setBarcode($barcodeForBlack);

        $whiteVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'TSHIRT-WHITE');
        $whiteVariation->activate();

        $product->publish();

        app(ProductRepository::class)->save($product);

        $this->price((string) $blackVariation->id(), '20.00');
        $this->price((string) $whiteVariation->id(), '21.00');

        return [
            'base' => $baseSku,
            'TSHIRT-BLACK' => (string) $blackVariation->id(),
            'TSHIRT-WHITE' => (string) $whiteVariation->id(),
        ];
    }

    private function place(): Order
    {
        if ($this->variations === []) {
            $this->variation('A', 'Alpha Widget', '10.00');
            $this->variation('B', 'Beta Widget', '5.00');
        }

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
        ), new DateTimeImmutable('2026-09-29 12:00:00'))->order();
    }

    /**
     * The trigger, as the rendered button really sends it — verified against
     * the real page HTML: `wire:click="mountAction('edit_order', {}, {
     * schemaComponent: 'infolist' })"`.
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

    /** The mounted dialog's own schema — the form the merchant is looking at. */
    private function dialogSchema($component): Schema
    {
        return $component->instance()->getSchema('mountedActionSchema0');
    }

    /**
     * Picks a variation the way the BROWSER does — one Livewire state change,
     * which is also why it (and not fillForm()) drives the cases that read
     * the price preview: see pickVariation()'s own note below.
     */
    private function pickVariation($component, string $variationId, int $quantity = 1): void
    {
        // `set()` mirrors the Select's own `$wire.set(...)` round trip, so the
        // dialog's schema is rebuilt with the pick already in place and the
        // price preview (a TextEntry whose state is computed while the schema
        // is BUILT — Filament's own TextEntry::state()) reflects it. Filament's
        // fillForm() builds the schema in order to fill it, so its first fill
        // is one render behind — a test-harness artefact, never something a
        // merchant can hit.
        $component->set('mountedActions.0.data.add.variation_id', $variationId);
        $component->set('mountedActions.0.data.add.quantity', $quantity);
    }

    /**
     * The ids of a results()/label() map as strings.
     *
     * @param  array<int|string, string>  $results
     * @return array<int, string>
     */
    private function ids(array $results): array
    {
        return array_map(static fn (int|string $id): string => (string) $id, array_keys($results));
    }

    private function lastNotificationBody(): ?string
    {
        $component = new Notifications;
        $component->mount();

        return $component->notifications->last()?->getBody();
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

    /** @return array<string, SaleLine> product name => the order's current line */
    private function currentLines(Order $placed): array
    {
        $out = [];

        foreach (app(OrderCurrentLinesResolver::class)->resolve(app(OrderRepository::class)->findById($placed->id())) as $line) {
            $out[$line->productName()] = $line;
        }

        return $out;
    }

    private function stock(string $variationId): int
    {
        return (int) DB::table('stock_levels')->where('variation_id', $variationId)->value('quantity');
    }

    private function editEvents(Order $order): int
    {
        return DB::table('order_events')->where('order_id', $order->id())->where('type', 'edited')->count();
    }

    /**
     * How many CURRENT lines the order has — the count a name-keyed map like
     * currentQuantities() cannot show, and exactly what a merge must not raise.
     */
    private function currentLineCount(Order $placed): int
    {
        return count(app(OrderCurrentLinesResolver::class)->resolve(app(OrderRepository::class)->findById($placed->id())));
    }

    /**
     * Units per variation, summed across every current line — the shape that
     * survives an order carrying the SAME variation on more than one line.
     *
     * @return array<string, int> variationId => total units (order-insensitive in assertions)
     */
    private function unitsPerVariation(Order $placed): array
    {
        $out = [];

        foreach (app(OrderCurrentLinesResolver::class)->resolve(app(OrderRepository::class)->findById($placed->id())) as $line) {
            $variationId = (string) $line->priceableId();
            $out[$variationId] = ($out[$variationId] ?? 0) + $line->quantity();
        }

        return $out;
    }

    /**
     * A SECOND current line of a variation the order already sells, written
     * through the real edit service (OrderEditor -> OrderLineEditor -> the live
     * pricer) and never through the dialog — because the dialog no longer
     * produces that state: folding an add into the line it belongs to is
     * exactly what this refinement changed. It is the state D2's own remark
     * describes ("add the same variation twice with different quantities"),
     * kept here as pre-existing data an order may already carry, and therefore
     * precisely the shape mergeTargetFor()'s "exactly one match" rule has to
     * keep falling back from.
     */
    private function addASecondLineOfTheSameVariation(Order $placed, string $variationId, int $quantity = 1): void
    {
        $order = app(OrderRepository::class)->findById($placed->id());

        app(OrderEditor::class)->apply(
            orderId: (string) $order->id(),
            expectedRevision: $order->editRevision(),
            lineChanges: [app(OrderAddLinePricer::class)->pricedChange($variationId, $quantity, $order->currency())],
            delivery: null,
            promotionCode: OrderPromotionCodeChange::unchanged(),
            editedBy: null,
            editedByName: null,
            reason: null,
            occurredAt: new DateTimeImmutable('2026-09-30 09:00:00'),
        );
    }

    /** The price preview exactly as the merchant reads it (§6 T3). */
    private function previewHtml($component): string
    {
        return (string) $this->dialogSchema($component)->getComponent('add.price')->getContent();
    }

    /**
     * A VARIABLE product with TWO axes (Colour, Size) and ONE priced variation,
     * for the label's own multi-axis and ordering cases.
     *
     * $sizeDefinitionCreatedFirst flips the two definitions' CREATION order
     * while leaving the merchant's declaration order (Colour, then Size) alone
     * — the one arrangement that tells the two candidate orders apart.
     */
    private function twoAxisProduct(string $baseSku, bool $sizeDefinitionCreatedFirst = false): string
    {
        $colour = new AttributeDefinition(id: null, code: 'colour', name: 'Colour', type: AttributeType::SELECT);
        $size = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);

        foreach ($sizeDefinitionCreatedFirst ? [$size, $colour] : [$colour, $size] as $definition) {
            app(AttributeDefinitionRepository::class)->save($definition);
        }

        $black = new AttributeValue(id: null, attributeDefinitionId: $colour->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $medium = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $product = Product::createVariable('Two Axis Tee', $baseSku, 'two-axis-'.strtolower(Str::random(6)));
        $product->declareVariationAxes([new VariationAxis($colour, [$black]), new VariationAxis($size, [$medium])]);

        $variation = $product->addStandardVariation(
            [$colour->id() => $black->id(), $size->id() => $medium->id()],
            $baseSku.'-BLACK-M',
        );
        $variation->activate();
        $product->publish();
        app(ProductRepository::class)->save($product);

        $this->price((string) $variation->id(), '30.00');

        return (string) $variation->id();
    }

    // --- T1: the search -----------------------------------------------------------------

    public function test_the_search_matches_a_product_name_a_sku_and_a_barcode_case_insensitively(): void
    {
        $alpha = $this->variation('A', 'Alpha Widget', '10.00');
        $shirt = $this->variableProduct('T-Shirt', '1234567890');
        $search = app(OrderLineProductSearch::class);

        $this->assertSame([$alpha], $this->ids($search->results('alpha')), 'a name fragment, lower case');
        $this->assertSame(['Alpha Widget (SKU-A)'], array_values($search->results('ALPHA')), 'the label a result carries: product name, then the parent\'s base_sku in brackets');
        $this->assertSame([$alpha], $this->ids($search->results('SKU-A')), 'a sku fragment, verbatim case');
        $this->assertSame([$alpha], $this->ids($search->results('sku-a')), 'a sku fragment, another case — the collation does the work, as ProductResource\'s own search relies on');
        $this->assertSame([$shirt['TSHIRT-BLACK']], $this->ids($search->results('1234567890')), 'a barcode fragment — a variation-level fact no product-level search can see');
        $this->assertSame([], $search->results('   '), 'a blank search is no search at all');
        $this->assertSame(
            ['Alpha Widget (SKU-A)'],
            array_values($search->results('widget')),
            'a name fragment matches the SIMPLE product\'s own universal variation',
        );
    }

    public function test_the_search_returns_one_row_per_sellable_variation_of_a_variable_product(): void
    {
        $shirt = $this->variableProduct('T-Shirt');

        $results = app(OrderLineProductSearch::class)->results('t-shirt');

        $this->assertSame(
            [$shirt['TSHIRT-BLACK'], $shirt['TSHIRT-WHITE']],
            $this->ids($results),
            'both of the product\'s variations, each with its own key — the priceableId an ADD names',
        );
        $this->assertSame(
            ['T-Shirt ('.$shirt['base'].' — Black)', 'T-Shirt ('.$shirt['base'].' — White)'],
            array_values($results),
            'one label per variation: product name, the PARENT\'s base_sku, then after a dash the value of the axis the variation varies on',
        );
    }

    /**
     * A result's label is the product name, then in brackets the PARENT's
     * base_sku and, after a spaced em dash, the variation's axis values in the
     * same order the receipt uses.
     */
    public function test_the_label_reads_the_product_name_the_parent_base_sku_then_the_axis_values(): void
    {
        $search = app(OrderLineProductSearch::class);
        $universal = $this->variation('A', 'Alpha Widget', '10.00');
        $tee = $this->twoAxisProduct('TEE-1001');

        $this->assertSame('Alpha Widget (SKU-A)', $search->label($universal), 'a universal variation has no axis values: no dash, and never a bare empty pair of brackets');

        $this->assertSame('Two Axis Tee (TEE-1001 — Black, M)', $search->label($tee), 'the product name, the parent\'s base_sku, then every axis value the variation varies on');
        $this->assertSame(
            [$tee => 'Two Axis Tee (TEE-1001 — Black, M)'],
            $search->results('TEE-1001'),
            'and the option the picker shows is that same string to the letter — label() is what the redisplayer uses, results() what the list does',
        );
    }

    /**
     * The ORDER of the axis values is not the merchant's declaration order —
     * it is attribute_definition_id (creation) order, the one deterministic
     * order this stack has and the one sold_attributes is written in, so a
     * label and the line it names can never disagree.
     */
    public function test_the_labels_axis_values_follow_the_one_order_the_receipt_uses(): void
    {
        $search = app(OrderLineProductSearch::class);

        // Declared to the merchant as Colour then Size, but the SIZE definition
        // was created first — so the ids put Size first.
        $tee = $this->twoAxisProduct('TEE-2002', sizeDefinitionCreatedFirst: true);

        $this->assertSame('Two Axis Tee (TEE-2002 — M, Black)', $search->label($tee));
        $this->assertSame(
            array_column(app(VariationDisplayReader::class)->soldAttributesFor([$tee])[$tee], 'value'),
            ['M', 'Black'],
            'the values the checkout snapshot stores on the line, in that order — the label names the same thing in the same order',
        );
    }

    /**
     * Tier B's invariant refuses an EMPTY sku on a line, so a parent with no
     * base_sku still has to render: the variation's own sku leads instead.
     */
    public function test_the_label_falls_back_to_the_variations_own_sku_when_the_parent_has_no_base_sku(): void
    {
        $search = app(OrderLineProductSearch::class);
        $shirt = $this->variableProduct('T-Shirt');

        DB::table('catalog_products')
            ->where('id', DB::table('catalog_variations')->where('id', $shirt['TSHIRT-BLACK'])->value('product_id'))
            ->update(['base_sku' => '']);

        $this->assertSame('T-Shirt (TSHIRT-BLACK — Black)', $search->label($shirt['TSHIRT-BLACK']), 'the variation\'s own sku stands in for the missing base_sku');
        $this->assertSame('T-Shirt (TSHIRT-WHITE — White)', $search->label($shirt['TSHIRT-WHITE']));
    }

    public function test_the_search_excludes_archived_and_draft_products_and_unbuyable_variations(): void
    {
        $search = app(OrderLineProductSearch::class);

        $draft = Product::createSimple('Draft Widget', 'SKU-DRAFT', 'draft-widget');
        app(ProductRepository::class)->save($draft);   // its own default: draft

        $archived = Product::createSimple('Archived Widget', 'SKU-ARCH', 'archived-widget');
        $archived->publish();
        $archived->archive();
        app(ProductRepository::class)->save($archived);

        $hidden = Product::createSimple('Hidden Widget', 'SKU-HIDDEN', 'hidden-widget');
        $hidden->publish();
        $hidden->setCatalogVisibility(CatalogVisibility::HIDDEN);
        app(ProductRepository::class)->save($hidden);

        $unbuyable = Product::createSimple('Unbuyable Widget', 'SKU-UNBUYABLE', 'unbuyable-widget');
        $unbuyable->publish();
        $unbuyableVariation = $unbuyable->variations()[0];
        $unbuyableVariation->setPurchasable(false);
        app(ProductRepository::class)->save($unbuyable);

        $retired = Product::createSimple('Retired Widget', 'SKU-RETIRED', 'retired-widget');
        $retired->publish();
        $retiredVariation = $retired->variations()[0];
        $retiredVariation->archive();
        app(ProductRepository::class)->save($retired);

        $activeId = $this->variation('A', 'Active Widget', '10.00');

        $this->assertSame([], $search->results('Draft'), 'a draft product — ProductResource\'s own default status view excludes it too');
        $this->assertSame([], $search->results('Archived'), 'an archived product');
        $this->assertSame([], $search->results('Unbuyable'), 'a variation the merchant marked not purchasable');
        $this->assertSame([], $search->results('Retired'), 'an archived variation, whatever its product says');
        $this->assertSame([$activeId], $this->ids($search->results('Active')));

        $this->assertSame(
            [(string) $hidden->variations()[0]->id()],
            $this->ids($search->results('Hidden')),
            'a hidden-but-ACTIVE product is deliberately INCLUDED: ProductResource\'s list does not filter catalog_visibility either, '
            .'and a phone order may legitimately contain one',
        );
    }

    public function test_the_search_is_capped(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->variation('M'.$i, 'Widget '.$i, '1.00', sku: 'SKU-M'.$i);
        }

        $results = app(OrderLineProductSearch::class)->results('Widget');

        $this->assertCount(OrderLineProductSearch::RESULT_LIMIT, $results);
        $this->assertCount(20, $results);
    }

    // --- T2: the section, the picker and the price preview --------------------------------

    public function test_the_add_section_renders_with_a_seeded_quantity_and_redisplays_a_pick(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);

        $this->assertSame(
            ['variation_id' => null, 'quantity' => 1],
            $this->seeded($component)['add'],
            'the section starts with nothing picked and a submittable quantity',
        );

        // A variation the order does NOT sell: this test pins the section's own
        // rendering and the plain, live-price preview. A pick of a variation the
        // order already sells previews THAT LINE's own snapshot price plus the
        // merge hint instead — the merge cases are T4's and T5's.
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');

        $this->pickVariation($component, $gamma, 2);

        $schema = $this->dialogSchema($component);
        $picker = $schema->getComponent('add.variation_id');

        $this->assertInstanceOf(Select::class, $picker);
        $this->assertSame('Gamma Widget (SKU-G)', $picker->getOptionLabel(), 'a picked value redisplays its label, never a bare id');
        $this->assertSame(2, $schema->getComponent('add.quantity')->getState());
        $this->assertSame(
            __('orders.fields.unit_price').': 7.50 €',
            (string) $schema->getComponent('add.price')->getContent(),
            'and its live unit price, through the same markup rule the line table uses',
        );

        // ... and the picker's OWN search callback really is wired to the
        // service (not just that the service works): the component's own
        // getSearchResults() is the very call Filament's Select makes when
        // the merchant types.
        $results = $picker->getSearchResults('alpha');

        $this->assertSame([$this->variations['A']], $this->ids($results), 'the picker searches the real catalogue');
        $this->assertSame(['Alpha Widget (SKU-A)'], array_values($results));
    }

    public function test_a_validation_failure_elsewhere_on_the_form_keeps_the_pick_its_label_and_its_price(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);
        // Again a variation the order does NOT sell: what this test pins is that
        // a failed submission keeps the pick, its label and its price. A pick of
        // something the order already sells previews the merge instead (T5).
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');
        $this->pickVariation($component, $gamma, 2);

        $delivery = $this->seeded($component)['delivery'];
        $delivery['city'] = '';   // required for a street address

        $component->fillForm(['delivery' => $delivery])->callMountedAction();

        $this->assertNotEmpty($component->instance()->mountedActions, 'a validation failure leaves the dialog open');
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(0, $this->editEvents($order));

        $schema = $this->dialogSchema($component);

        $this->assertSame('Gamma Widget (SKU-G)', $schema->getComponent('add.variation_id')->getOptionLabel());
        $this->assertSame(2, $schema->getComponent('add.quantity')->getState());
        $this->assertSame(__('orders.fields.unit_price').': 7.50 €', (string) $schema->getComponent('add.price')->getContent());
    }

    // --- T3: the submission ---------------------------------------------------------------

    public function test_adding_a_product_writes_a_real_line_at_the_resolved_price_and_takes_stock(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $gamma, 'quantity' => 2]])->callMountedAction();

        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3, 'Gamma Widget' => 2], $this->currentQuantities($order));

        $line = $this->currentLines($order)['Gamma Widget'];

        $this->assertSame($gamma, $line->priceableId(), 'the line names the VARIATION, as every SALE line does');
        $this->assertSame(2, $line->quantity());
        $this->assertSame('7.50', $line->regularUnitPrice()->decimalValue());
        $this->assertSame('7.50', $line->finalUnitPrice()->decimalValue());
        $this->assertSame('0.00', $line->discretionaryDiscount()->decimalValue(), 'an add carries no manual discount (E2 keeps that a separate edit)');
        $this->assertSame('15.00', $line->netPaidAmount()->decimalValue());
        $this->assertSame('SKU-G', $line->sku());
        $this->assertSame([], $line->soldAttributes(), 'a SIMPLE product\'s universal variation has no attributes');

        $row = $this->row($order->id());

        $this->assertSame(1, (int) $row->edit_revision, 'one edit revision');
        $this->assertSame(5000, (int) $row->subtotal_minor, '35.00 + 15.00');
        $this->assertSame(5000, (int) $row->total_minor);
        $this->assertSame(1, $this->editEvents($order), 'one EDITED event');
        $this->assertSame(48, $this->stock($gamma), 'the added line took its own stock');
        $component->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));
    }

    public function test_a_new_line_and_an_existing_lines_change_land_in_one_edit(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];

        foreach ($lines as $key => $line) {
            if (str_starts_with($line['product'], 'Alpha')) {
                $lines[$key]['quantity'] = 1;
            }
        }

        $component->fillForm(['lines' => $lines, 'add' => ['variation_id' => $gamma, 'quantity' => 3]])->callMountedAction();

        $this->assertSame(['Alpha Widget' => 1, 'Beta Widget' => 3, 'Gamma Widget' => 3], $this->currentQuantities($order));

        $row = $this->row($order->id());

        $this->assertSame(1, (int) $row->edit_revision, 'ONE edit revision for both changes');
        $this->assertSame(1, $this->editEvents($order), 'ONE event');
        $this->assertSame(4750, (int) $row->subtotal_minor, '10.00 + 15.00 + 22.50');
        $this->assertSame(47, $this->stock($gamma));
    }

    public function test_adding_more_than_the_stock_allows_refuses_cleanly_and_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $scarce = $this->variation('S', 'Scarce Widget', '9.00', stock: 1);

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $scarce, 'quantity' => 2]])->callMountedAction();

        $this->assertSame(
            'There is not enough stock to add this item in the quantity asked for. Nothing was saved — check the stock and try again.',
            $this->lastNotificationBody(),
        );
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order), 'nothing was added');
        $this->assertSame(1, $this->stock($scarce), 'and no stock was taken');
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(0, $this->editEvents($order));
    }

    public function test_a_pick_with_no_usable_quantity_is_refused_by_the_form_before_anything_is_written(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $this->variations['A'], 'quantity' => 0]])->callMountedAction();

        // The field's own minValue(1) refuses this before the submission is
        // ever mapped (D4's "refuse client-side, before submission"): the
        // dialog stays open on a validation error and NO notification is
        // sent, because the action's own closure is never reached.
        $this->assertNotEmpty($component->instance()->mountedActions, 'the dialog stays open');
        $this->assertNotEmpty($component->instance()->getErrorBag()->all(), 'and says why');

        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(0, $this->editEvents($order));
    }

    public function test_an_add_for_a_product_that_stopped_being_sellable_is_refused_with_its_own_sentence(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $doomed = $this->variation('D', 'Doomed Widget', '4.00');

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $doomed, 'quantity' => 1]]);

        // Someone archives the product while the dialog is open.
        $product = app(ProductRepository::class)->findByIdWithVariations(
            (string) app(VariationRepository::class)->findById($doomed)->productId(),
        );
        $product->archive();
        app(ProductRepository::class)->save($product);

        $component->callMountedAction();

        $this->assertSame(
            'The selected product cannot be added to this order: it is archived, it is not purchasable, or it no longer exists. Nothing was saved.',
            $this->lastNotificationBody(),
        );
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
    }

    public function test_an_add_for_a_product_with_no_price_is_refused_and_the_preview_says_so_first(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $product = Product::createSimple('Unpriced Widget', 'SKU-UNPRICED', 'unpriced-widget');
        $product->publish();
        app(ProductRepository::class)->save($product);
        $unpriced = (string) $product->variations()[0]->id();
        app(StockLevelRepository::class)->save(StockLevel::forVariation($unpriced, 10));

        $component = $this->mount($order);
        $this->pickVariation($component, $unpriced);

        $this->assertSame(
            'No price is configured for this product.',
            (string) $this->dialogSchema($component)->getComponent('add.price')->getContent(),
            'the merchant learns it before submitting, not after',
        );

        $component->callMountedAction();

        $this->assertSame(
            'The selected product has no price configured in this order\'s currency, so it cannot be added. Nothing was saved.',
            $this->lastNotificationBody(),
        );
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(10, $this->stock($unpriced), 'no stock was taken');
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
    }

    public function test_the_price_is_resolved_again_at_submit_time_not_reused_from_the_preview(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');

        $component = $this->mount($order);
        $this->pickVariation($component, $gamma);

        $this->assertSame(
            __('orders.fields.unit_price').': 7.50 €',
            (string) $this->dialogSchema($component)->getComponent('add.price')->getContent(),
        );

        // The price list changes while the dialog is open: the displayed
        // amount is a preview, the write is priced at submit time.
        $item = $this->priceItems[$gamma];
        $item->updatePrice(Price::exclusiveOfTax(Money::fromDecimal('8.25', 'EUR'), 0));
        app(PriceListItemRepository::class)->save($item);

        $component->callMountedAction();

        $line = $this->currentLines($order)['Gamma Widget'];

        $this->assertSame('8.25', $line->regularUnitPrice()->decimalValue());
        $this->assertSame('8.25', $line->finalUnitPrice()->decimalValue());
        $this->assertSame(4325, (int) $this->row($order->id())->subtotal_minor, '35.00 + 8.25 at the NEW price');
    }

    // --- T4: a pick the order already sells merges into that line (stage 4b-ii refinement) --

    /**
     * The refinement itself: a pick of something the order ALREADY sells is not
     * a second line — it is more units of the line that exists, counted at that
     * line's own §3.13 snapshot price.
     */
    public function test_adding_a_variation_the_order_already_sells_merges_into_that_line_at_its_own_snapshot_price(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        // The price list moves AFTER the order was placed: a plain add would be
        // priced live at 12.00 and write a second line; a merge must do neither.
        $item = $this->priceItems[$this->variations['A']];
        $item->updatePrice(Price::exclusiveOfTax(Money::fromDecimal('12.00', 'EUR'), 0));
        app(PriceListItemRepository::class)->save($item);

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $this->variations['A'], 'quantity' => 1]])->callMountedAction();

        $this->assertSame(['Alpha Widget' => 3, 'Beta Widget' => 3], $this->currentQuantities($order), 'the added unit joined the line the order already sells');
        $this->assertSame(2, $this->currentLineCount($order), 'and no third line was written');

        $alpha = $this->currentLines($order)['Alpha Widget'];

        $this->assertSame($this->variations['A'], $alpha->priceableId(), 'still the same variation on the same line');
        $this->assertSame(3, $alpha->quantity());
        $this->assertSame('10.00', $alpha->regularUnitPrice()->decimalValue(), 'the LINE\'s own snapshot price — not the 12.00 the price list now holds');
        $this->assertSame('10.00', $alpha->finalUnitPrice()->decimalValue());
        $this->assertSame('0.00', $alpha->discretionaryDiscount()->decimalValue(), 'a merge adds units, it does not invent a discount');
        $this->assertSame('30.00', $alpha->netPaidAmount()->decimalValue());
        $this->assertSame('SKU-A', $alpha->sku(), 'and the line keeps its own snapshot sku');

        $row = $this->row($order->id());

        $this->assertSame(1, (int) $row->edit_revision, 'ONE edit revision');
        $this->assertSame(1, $this->editEvents($order), 'ONE EDITED event');
        $this->assertSame(4500, (int) $row->subtotal_minor, '3 x 10.00 + 3 x 5.00 — the merged unit is 10.00, not 12.00');
        $this->assertSame(47, $this->stock($this->variations['A']), 'ONE unit taken: the line\'s own 2 came back, then all 3 went out');
        $component->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id()]));
    }

    /**
     * The row the merchant is also editing and the merge are ONE intent: the
     * line is written once, at the net quantity, with ONE reversal — never a
     * change and a separate add fighting over the same line.
     */
    public function test_a_merge_onto_a_row_the_merchant_also_changed_becomes_one_single_write(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];

        foreach ($lines as $key => $line) {
            if (str_starts_with($line['product'], 'Alpha')) {
                $lines[$key]['quantity'] = 1;
            }
        }

        // Reduced from 2 to 1 AND the same variation added back — 1 + 2 = 3.
        $component->fillForm(['lines' => $lines, 'add' => ['variation_id' => $this->variations['A'], 'quantity' => 2]])->callMountedAction();

        $this->assertSame(['Alpha Widget' => 3, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(2, $this->currentLineCount($order), 'still one Alpha line, not two');

        $row = $this->row($order->id());

        $this->assertSame(1, (int) $row->edit_revision);
        $this->assertSame(1, $this->editEvents($order));
        $this->assertSame(4500, (int) $row->subtotal_minor);
        $this->assertSame(47, $this->stock($this->variations['A']), '2 put back, 3 written');
        $this->assertSame(
            1,
            DB::table('operational_sales_sale_lines')->where('type', 'edit_reversal')->count(),
            'ONE reversal for the line — the row edit and the add were folded before anything was written',
        );
    }

    /**
     * Edge (a), first half: a row the merchant emptied and a pick that puts
     * units back on it is not a removal, it is the quantity the merchant asked
     * for. The line survives — the add is counted ON the emptied row.
     */
    public function test_a_merge_onto_a_row_the_merchant_emptied_keeps_the_line_at_the_quantity_asked_for(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];

        foreach ($lines as $key => $line) {
            if (str_starts_with($line['product'], 'Alpha')) {
                $lines[$key]['quantity'] = 0;
            }
        }

        $component->fillForm(['lines' => $lines, 'add' => ['variation_id' => $this->variations['A'], 'quantity' => 1]])->callMountedAction();

        $this->assertSame(['Alpha Widget' => 1, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(2, $this->currentLineCount($order), 'the line survived: the merchant asked for ONE of these, not for the line to disappear');

        $alpha = $this->currentLines($order)['Alpha Widget'];

        $this->assertSame('10.00', $alpha->finalUnitPrice()->decimalValue(), 'and it is still the line\'s own price');

        $row = $this->row($order->id());

        $this->assertSame(1, (int) $row->edit_revision);
        $this->assertSame(1, $this->editEvents($order));
        $this->assertSame(2500, (int) $row->subtotal_minor, '10.00 + 15.00');
        $this->assertSame(49, $this->stock($this->variations['A']), '2 put back, 1 written');
    }

    /**
     * Edge (a), second half: what the row gave up and what the pick puts back
     * cancel out exactly — so there is nothing to write, and the dialog says so
     * rather than writing a no-op edit.
     */
    public function test_a_merge_that_only_puts_back_what_the_row_took_away_is_nothing_at_all(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];

        foreach ($lines as $key => $line) {
            if (str_starts_with($line['product'], 'Alpha')) {
                $lines[$key]['quantity'] = 1;
            }
        }

        // 1 left + 1 added = the 2 units the order already sells. Not an edit.
        $component->fillForm(['lines' => $lines, 'add' => ['variation_id' => $this->variations['A'], 'quantity' => 1]])->callMountedAction();

        $this->assertSame(__('orders.actions.edit_nothing_to_change'), $this->lastNotificationBody());
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order), 'nothing moved');
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(0, $this->editEvents($order));
        $this->assertSame(48, $this->stock($this->variations['A']), 'and no stock was taken or put back');
    }

    /**
     * Edge (c): a merge goes through the SAME stock bath as any other line
     * write, so a quantity the catalogue cannot cover refuses with the existing
     * sentence and writes NOTHING — including the reversal that a naive
     * implementation would leave behind.
     */
    public function test_merging_more_than_the_stock_allows_refuses_cleanly_and_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place(); // Alpha: 50 in stock, 2 of them sold

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $this->variations['A'], 'quantity' => 49]])->callMountedAction();

        // 2 + 49 = 51 units against the 50 the catalogue holds.
        $this->assertSame(__('orders.actions.edit_add_insufficient_stock_body'), $this->lastNotificationBody());
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order), 'the order is untouched');
        $this->assertSame(48, $this->stock($this->variations['A']), 'the reversal did not survive the refusal');
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(0, $this->editEvents($order));
    }

    /**
     * Edge (d): a manual discount the line already carries survives the merge —
     * the merged write is a change_quantity, and change_quantity carries the
     * origin's own discount forward rather than silently dropping it.
     */
    public function test_a_merge_onto_a_discounted_line_keeps_the_lines_own_discount(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();

        // A first edit puts a manual discount on Alpha through the same dialog a
        // merchant uses, so the merge below lands on a genuinely discounted line.
        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];

        foreach ($lines as $key => $line) {
            if (str_starts_with($line['product'], 'Alpha')) {
                $lines[$key]['discount'] = '1.50';
            }
        }

        $component->fillForm(['lines' => $lines])->callMountedAction();

        $this->assertSame('1.50', $this->currentLines($order)['Alpha Widget']->discretionaryDiscount()->decimalValue());

        // THE SECOND MOUNT IS A SECOND REQUEST. The edit above replaced the
        // Alpha line, so its row id moved on — while the admin reader that seeds
        // the dialog is memoized per PROCESS, not per page view (see
        // OrderAdminReaderTest's own use of this same call). Without this, the
        // second mount would seed the dialog with the retired row id and the
        // mapping step would refuse it exactly as it would refuse a genuinely
        // stale page — a harness artefact no merchant can reach, because a
        // merchant's next dialog is a fresh request.
        app()->forgetScopedInstances();

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $this->variations['A'], 'quantity' => 1]])->callMountedAction();

        $alpha = $this->currentLines($order)['Alpha Widget'];

        $this->assertSame(3, $alpha->quantity());
        $this->assertSame('10.00', $alpha->finalUnitPrice()->decimalValue());
        $this->assertSame('1.50', $alpha->discretionaryDiscount()->decimalValue(), 'the manual discount survived the merge');
        $this->assertSame('28.50', $alpha->netPaidAmount()->decimalValue(), '3 x 10.00 - 1.50');

        $row = $this->row($order->id());

        $this->assertSame(2, (int) $row->edit_revision, 'two edits: the discount, then the merge');
        // The ledger's own three-way rule (OrderEditor's docblock): subtotal is
        // the lines' amount BEFORE any discount, discount is the manual
        // discount the line still carries, and total is the two subtracted.
        $this->assertSame(4500, (int) $row->subtotal_minor, '3 x 10.00 + 3 x 5.00');
        $this->assertSame(150, (int) $row->discount_minor, 'the 1.50 the merged line still carries');
        $this->assertSame(4350, (int) $row->total_minor, '45.00 - 1.50');
    }

    /**
     * Edge (d'): the row the merge lands on is ALSO discounted in the SAME
     * submission. The row's own change is a "discount" entry, which carries no
     * quantity — folding must skip it, never read it as "this row asked for 0
     * units", or the merchant would lose the discount AND have the added units
     * land on a quantity no row ever named. Both changes are one write, because
     * change_quantity plus discount is the one legal pair on a single line.
     */
    public function test_a_merge_onto_a_row_the_merchant_also_discounted_writes_both(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place(); // Alpha: 2 of 50 sold, Beta: 3

        $component = $this->mount($order);
        $lines = $this->seeded($component)['lines'];

        // The row's quantity is untouched; all it asks for is a discount — so
        // the only entry the row contributes is the "discount" one.
        foreach ($lines as $key => $line) {
            if (str_starts_with($line['product'], 'Alpha')) {
                $lines[$key]['discount'] = '1.00';
            }
        }

        $component->fillForm([
            'lines' => $lines,
            'add' => ['variation_id' => $this->variations['A'], 'quantity' => 1],
        ])->callMountedAction();

        $alpha = $this->currentLines($order)['Alpha Widget'];

        $this->assertSame(3, $alpha->quantity(), '2 + 1 units, counted at the line\'s own snapshot price');
        $this->assertSame('10.00', $alpha->finalUnitPrice()->decimalValue(), 'the price list was never consulted again');
        $this->assertSame('1.00', $alpha->discretionaryDiscount()->decimalValue(), 'the discount the merchant typed was written');
        $this->assertSame('29.00', $alpha->netPaidAmount()->decimalValue(), '3 x 10.00 - 1.00');

        $row = $this->row($order->id());

        $this->assertSame(1, (int) $row->edit_revision, 'one edit revision');
        $this->assertSame(1, $this->editEvents($order), 'one EDITED event');
        $this->assertSame(4500, (int) $row->subtotal_minor, '3 x 10.00 + 3 x 5.00');
        $this->assertSame(100, (int) $row->discount_minor, 'the 1.00 the row now carries');
        $this->assertSame(4400, (int) $row->total_minor, '45.00 - 1.00');
        $this->assertSame(47, $this->stock($this->variations['A']), '48 after the order, less the one the merge took');
        $this->assertSame(['Alpha Widget' => 3, 'Beta Widget' => 3], $this->currentQuantities($order));
    }

    /**
     * Edge (b): an order that already carries the SAME variation on more than
     * one line (pre-existing data, see addASecondLineOfTheSameVariation()) has
     * no single line to merge into — so the add falls back to exactly what it
     * always did: a new line, with no line quietly preferred.
     */
    public function test_a_variation_the_order_already_sells_twice_falls_back_to_a_new_line(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $this->addASecondLineOfTheSameVariation($order, $this->variations['A'], 1);

        $this->assertSame(3, $this->currentLineCount($order), 'the fixture: two Alpha lines and one Beta');

        $component = $this->mount($order);
        $component->fillForm(['add' => ['variation_id' => $this->variations['A'], 'quantity' => 2]])->callMountedAction();

        $this->assertSame(4, $this->currentLineCount($order), 'two candidates and no way to choose between them: the pick becomes its own line');
        $this->assertEquals(
            [$this->variations['A'] => 5, $this->variations['B'] => 3],
            $this->unitsPerVariation($order),
            '2 + 1 units the fixture already carried, plus the 2 the pick added as its own line — and no line was quietly preferred over the other',
        );
        $this->assertSame(45, $this->stock($this->variations['A']), '50 - 2 sold - 1 sold again - 2 added');
    }

    // --- T5: the price preview of a pick that will merge --------------------------------

    /**
     * The preview may never promise something the write does not do. For a pick
     * of a variation the order already sells, the write merges at the LINE's own
     * snapshot price — so the preview must show that price, and say where the
     * units are going, instead of the live price of a line that will not exist.
     */
    public function test_the_preview_of_a_pick_the_order_already_sells_shows_the_lines_own_price_and_says_it_will_merge(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');

        $component = $this->mount($order);
        $this->pickVariation($component, $this->variations['A'], 2);

        $this->assertSame(
            __('orders.fields.unit_price').': 10.00 €<br>'.e(__('orders.actions.edit_add_merge_hint')),
            $this->previewHtml($component),
            'a pick of something the order already sells shows THAT LINE\'s own 10.00 and says where the units go',
        );

        // The price list moves while the dialog is open. A merge is priced from
        // the line's own §3.13 snapshot, so the preview must NOT follow it — the
        // same "a preview may never disagree with the write" rule the existing
        // live-price test pins from the other side.
        $item = $this->priceItems[$this->variations['A']];
        $item->updatePrice(Price::exclusiveOfTax(Money::fromDecimal('12.00', 'EUR'), 0));
        app(PriceListItemRepository::class)->save($item);

        $this->pickVariation($component, $this->variations['A'], 3);

        $this->assertSame(
            __('orders.fields.unit_price').': 10.00 €<br>'.e(__('orders.actions.edit_add_merge_hint')),
            $this->previewHtml($component),
            'still the line\'s own 10.00, never the 12.00 the price list now holds',
        );

        // A pick the order does NOT sell keeps the old behaviour exactly: the
        // live price, and not a word about merging.
        $this->pickVariation($component, $gamma, 2);

        $this->assertSame(
            __('orders.fields.unit_price').': 7.50 €',
            $this->previewHtml($component),
            'nothing to merge into: the live price, and no merge hint',
        );

        // ... and the merge the preview promised is the write that lands.
        $this->pickVariation($component, $this->variations['A'], 3);
        $component->callMountedAction();

        $alpha = $this->currentLines($order)['Alpha Widget'];

        $this->assertSame(5, $alpha->quantity(), '2 + 3, exactly what the hint said');
        $this->assertSame('10.00', $alpha->finalUnitPrice()->decimalValue());
        $this->assertSame(2, $this->currentLineCount($order), 'no Gamma line, no second Alpha line');
        $this->assertSame(6500, (int) $this->row($order->id())->subtotal_minor, '5 x 10.00 + 3 x 5.00');
        $this->assertSame(45, $this->stock($this->variations['A']));
    }

    /**
     * Edge (b) in the preview: with the variation on two lines already, the
     * submission falls back to a NEW line — so the preview shows the live price
     * and says nothing about merging, keeping preview and write in step.
     */
    public function test_the_preview_of_a_variation_the_order_sells_twice_shows_the_live_price_with_no_merge_hint(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $this->addASecondLineOfTheSameVariation($order, $this->variations['A'], 1);

        $item = $this->priceItems[$this->variations['A']];
        $item->updatePrice(Price::exclusiveOfTax(Money::fromDecimal('12.00', 'EUR'), 0));
        app(PriceListItemRepository::class)->save($item);

        $component = $this->mount($order);
        $this->pickVariation($component, $this->variations['A'], 2);

        $this->assertSame(
            __('orders.fields.unit_price').': 12.00 €',
            $this->previewHtml($component),
            'two candidate lines and no way to choose: the preview promises the NEW line the submission will write',
        );
    }

    /**
     * The axis values of a WHOLE result set cost ONE query, whatever the row
     * count and whatever mix of variable and universal variations is in it:
     * labelsFrom() reads them in a batch (attributesForMany()), never one
     * query per row — and a live-typing list returns up to RESULT_LIMIT rows.
     */
    public function test_the_labels_axis_values_cost_one_query_for_the_whole_result_set(): void
    {
        $this->variableProduct('T-Shirt');
        $search = app(OrderLineProductSearch::class);

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $small = $search->results('T-Shirt');
        $smallCost = $count;

        // Two more rows of variable product and one universal variation — the
        // same term now matches three times as many rows.
        $this->variableProduct('T-Shirt Tall', '1234567891');
        $this->variation('P', 'T-Shirt Pin', '1.00');

        $count = 0;
        $big = $search->results('T-Shirt');
        $bigCost = $count;

        $this->assertCount(2, $small, 'two variations of one variable product');
        $this->assertCount(5, $big, 'four variations of two variable products, plus one universal one');

        $this->assertSame(2, $smallCost, 'ONE match query + ONE batched axis-value read');
        $this->assertSame(
            $smallCost,
            $bigCost,
            'and the same TWO for three times the rows: the axis values are read once for the set, not once per row (a universal row adds none at all)',
        );

        $count = 0;
        $search->label($this->variations['P']);

        $this->assertSame(2, $count, 'a single label: ONE variation read + ONE axis-value read (none at all for a universal variation, which is the common case)');
    }

    // --- T6: the query cost ---------------------------------------------------------------

    public function test_the_query_cost_of_a_search_a_label_and_an_add_submission_is_reported(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');
        $this->variableProduct('T-Shirt');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $search = app(OrderLineProductSearch::class);
        $search->results('widget');
        $searchCost = $count;

        $count = 0;
        $search->label($this->variations['A']);
        $labelCost = $count;

        $count = 0;
        $component = $this->mount($order);
        $mountCost = $count;

        $count = 0;
        $this->pickVariation($component, $gamma, 2);
        $pickCost = $count;

        $count = 0;
        $component->callMountedAction();
        $submitCost = $count;

        fwrite(STDERR, "\n[query-count] add-a-line: search 'widget' {$searchCost} queries (1 match + 1 BATCHED axis-value read covering every row returned, however many), one label {$labelCost} (variation + its own axis values), dialog mount {$mountCost}, pick + price preview (2 state changes) {$pickCost}, submit (add 1 line, payment reissued, incl. redirect-side work) {$submitCost}\n");

        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3, 'Gamma Widget' => 2], $this->currentQuantities($order));
    }

    // --- T7: the per-row remove / restore control -----------------------------------------

    /** The row key (the repeater item uuid) of the dialog row whose product starts with $prefix. */
    private function rowKey(Testable $component, string $prefix): string
    {
        foreach ($this->seeded($component)['lines'] as $key => $line) {
            if (str_starts_with($line['product'], $prefix)) {
                return (string) $key;
            }
        }

        $this->fail("no dialog row for {$prefix}");
    }

    private function rowAction(string $name, string $key): TestAction
    {
        return TestAction::make($name)->schemaComponent("lines.{$key}.lineControls", 'mountedActionSchema0');
    }

    public function test_remove_sets_the_rows_quantity_to_zero_and_keeps_the_row_in_the_form(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $alpha = $this->rowKey($component, 'Alpha');
        $before = $this->seeded($component)['lines'];

        $component->callAction($this->rowAction('removeLine', $alpha));

        $lines = $this->seeded($component)['lines'];

        $this->assertArrayHasKey($alpha, $lines, 'the row is still in the form: a missing row would read as "unchanged"');
        $this->assertSame(0, $lines[$alpha]['quantity']);
        $this->assertSame($before[$alpha]['line_id'], $lines[$alpha]['line_id'], 'and it still names the same stored line');
        $this->assertSame(3, $lines[$this->rowKey($component, 'Beta')]['quantity'], 'the other row is untouched');
        $this->assertNotEmpty($component->instance()->mountedActions, 'and the edit dialog is still open: removing is not saving');
    }

    public function test_submitting_after_a_remove_writes_what_typing_zero_writes(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $stockBefore = $this->stock($this->variations['A']);
        $component = $this->mount($order);

        $component->callAction($this->rowAction('removeLine', $this->rowKey($component, 'Alpha')));
        $component->callMountedAction();

        $this->assertSame(['Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame($stockBefore + 2, $this->stock($this->variations['A']), 'the removed line stock is restored');
        $this->assertSame(1, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(1, $this->editEvents($order));
    }

    public function test_restore_puts_the_current_quantity_back_and_saving_then_writes_nothing(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $alpha = $this->rowKey($component, 'Alpha');

        $component->callAction($this->rowAction('removeLine', $alpha));
        $this->assertSame(0, $this->seeded($component)['lines'][$alpha]['quantity']);
        $component->callAction($this->rowAction('restoreLine', $alpha));

        $this->assertSame(2, $this->seeded($component)['lines'][$alpha]['quantity'], 'back to current_quantity');

        $component->callMountedAction();

        $this->assertSame(__('orders.actions.edit_nothing_to_change'), $this->lastNotificationBody());
        $this->assertSame(['Alpha Widget' => 2, 'Beta Widget' => 3], $this->currentQuantities($order));
        $this->assertSame(0, (int) $this->row($order->id())->edit_revision);
        $this->assertSame(0, $this->editEvents($order));
    }

    public function test_the_last_remaining_line_cannot_be_removed_and_the_guard_follows_the_live_state(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $alpha = $this->rowKey($component, 'Alpha');
        $beta = $this->rowKey($component, 'Beta');

        $component->assertActionEnabled($this->rowAction('removeLine', $alpha));
        $component->assertActionEnabled($this->rowAction('removeLine', $beta));

        $component->callAction($this->rowAction('removeLine', $alpha));

        $component->assertActionDisabled($this->rowAction('removeLine', $beta));

        $component->callAction($this->rowAction('restoreLine', $alpha));

        $component->assertActionEnabled($this->rowAction('removeLine', $beta), 'restoring the other row frees this one again');
    }

    public function test_a_one_line_order_has_its_remove_control_disabled_from_the_start(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $component->callAction($this->rowAction('removeLine', $this->rowKey($component, 'Alpha')));
        $component->callMountedAction();

        // OrderAdminReader memoises a view per instance (one request in production); a test reopening the dialog is a new request.
        app()->forgetInstance(\App\Services\OrderAdminReader::class);

        $reopened = $this->mount($order);
        $beta = $this->rowKey($reopened, 'Beta');

        $this->assertCount(1, $this->seeded($reopened)['lines']);
        $reopened->assertActionDisabled($this->rowAction('removeLine', $beta));
    }

    public function test_the_add_section_does_not_count_towards_the_last_line_guard(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $first = $this->mount($order);
        $first->callAction($this->rowAction('removeLine', $this->rowKey($first, 'Alpha')));
        $first->callMountedAction();

        // OrderAdminReader memoises a view per instance (one request in production); a test reopening the dialog is a new request.
        app()->forgetInstance(\App\Services\OrderAdminReader::class);

        $component = $this->mount($order);
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');
        $this->pickVariation($component, $gamma, 2);

        $component->assertActionDisabled($this->rowAction('removeLine', $this->rowKey($component, 'Beta')));
    }

    public function test_the_last_line_tooltip_sentence_exists_in_both_languages(): void
    {
        foreach (['en', 'bg'] as $locale) {
            foreach (['edit_remove_last_line', 'edit_remove_line', 'edit_restore_line'] as $key) {
                $this->assertNotSame("orders.actions.{$key}", __("orders.actions.{$key}", [], $locale), "{$key} is missing in {$locale}");
            }
        }
    }

    public function test_a_remove_combined_with_an_add_ends_with_the_new_item_and_without_the_removed_one(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $gamma = $this->variation('G', 'Gamma Widget', '7.50');
        $component = $this->mount($order);

        $component->callAction($this->rowAction('removeLine', $this->rowKey($component, 'Alpha')));
        $this->pickVariation($component, $gamma, 2);
        $component->callMountedAction();

        $this->assertSame(['Beta Widget' => 3, 'Gamma Widget' => 2], $this->currentQuantities($order));
        $this->assertSame(1, $this->editEvents($order));
    }

    /** The rendered HTML of one dialog row's control cell. */
    private function controlsHtml(Testable $component, string $key): string
    {
        return $this->dialogSchema($component)->getComponent("lines.{$key}.lineControls")->toHtml();
    }

    public function test_the_controls_are_bare_icons_whose_label_survives_as_the_accessible_name(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $alpha = $this->rowKey($component, 'Alpha');

        $html = $this->controlsHtml($component, $alpha);

        $this->assertStringContainsString('aria-label="'.__('orders.actions.edit_remove_line').'"', $html, 'the label is the accessible name');
        $this->assertStringContainsString('fi-icon-btn', $html, 'an icon button');
        $this->assertSame('', trim(preg_replace('/\s+/', ' ', strip_tags($html))), 'and no text renders next to the icon');

        $component->callAction($this->rowAction('removeLine', $alpha));
        $restored = $this->controlsHtml($component, $alpha);

        $this->assertStringContainsString('aria-label="'.__('orders.actions.edit_restore_line').'"', $restored);
        $this->assertSame('', trim(preg_replace('/\s+/', ' ', strip_tags($restored))));
    }

    public function test_the_tooltips_are_the_label_when_enabled_the_last_line_sentence_when_disabled_and_the_restore_label(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $alpha = $this->rowKey($component, 'Alpha');
        $beta = $this->rowKey($component, 'Beta');

        $this->assertStringContainsString("content: '".__('orders.actions.edit_remove_line')."'", $this->controlsHtml($component, $alpha), 'remove, enabled');

        $component->callAction($this->rowAction('removeLine', $alpha));

        $this->assertStringContainsString("content: '".__('orders.actions.edit_restore_line')."'", $this->controlsHtml($component, $alpha), 'restore');

        $disabled = $this->controlsHtml($component, $beta);

        $this->assertStringContainsString("content: '".__('orders.actions.edit_remove_last_line')."'", $disabled, 'remove, disabled by the last-line guard');
        $this->assertStringNotContainsString("content: '".__('orders.actions.edit_remove_line')."'", $disabled);
    }

    /**
     * Where the tooltip is bound, checked in the rendered markup: on the
     * button itself, and Filament drops the HTML `disabled` attribute whenever
     * a tooltip exists (button/index.blade.php: 'disabled' => $disabled &&
     * blank($tooltip)), marking it aria-disabled instead — so the hover is not
     * swallowed. The server still refuses the click (see the test above).
     */
    public function test_a_disabled_remove_keeps_its_tooltip_hoverable(): void
    {
        $this->actingAsStaffRole('Administrator');
        $order = $this->place();
        $component = $this->mount($order);
        $component->callAction($this->rowAction('removeLine', $this->rowKey($component, 'Alpha')));

        $html = $this->controlsHtml($component, $this->rowKey($component, 'Beta'));

        $this->assertStringContainsString('x-tooltip=', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertSame(0, preg_match('/\sdisabled[\s>=]/', $html), 'no native disabled attribute: a disabled button swallows hover in some browsers');
    }
}
