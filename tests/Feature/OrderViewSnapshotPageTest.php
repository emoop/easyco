<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\StaffPanelUser;
use App\Services\CheckoutInput;
use App\Services\CheckoutOrchestrator;
use App\Services\OrderAdminReader;
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
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Order\Order;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Contracts\ProductCostRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Pricing\ProductCost;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * §3.13 stage 5 — the admin Order View page's full sale-line snapshot
 * (admin-panel-design.md §14, decisions D1–D6). Every order is placed
 * through the real CheckoutOrchestrator (never a hand-built row); only
 * the states a real checkout cannot produce (a pre-§3.13 legacy row, a
 * corrupt money pair, a non-zero discretionary discount) are simulated by
 * writing the sale-line table directly, and that is called out where it
 * happens. Fixture helpers mirror OrderViewPageTest's own established
 * shapes.
 */
class OrderViewSnapshotPageTest extends TestCase
{
    use RefreshDatabase;

    private static int $counter = 0;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    /**
     * A staff member whose role grants EXACTLY the given permissions —
     * needed for the unit-cost test, because no seeded system role has
     * ORDER_VIEW without COST_VIEW (Administrator and Manager hold both).
     */
    private function actingAsPermissions(array $permissions): StaffPanelUser
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $role = Role::create("Custom {$suffix}", $permissions);
        app(RoleRepository::class)->save($role);

        $email = "custom{$suffix}@example.com";
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $role->name(), $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    /** Both reserved system PriceLists — "Regular Prices" and "Manual Sale". */
    private function seedPricingLists(): void
    {
        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
    }

    private function addPriceItem(string $listName, string $variationId, string $decimalAmount): void
    {
        $list = app(PriceListRepository::class)->findSystemListByName($listName);

        app(PriceListItemRepository::class)->save(new PriceListItem(
            id: null,
            priceListId: $list->id(),
            targetType: PriceListItemTargetType::VARIATION,
            targetId: $variationId,
            // 0% tax so gross() equals the input decimal exactly — the
            // rendered strings below are asserted literally.
            price: Price::inclusiveOfTax(Money::fromDecimal($decimalAmount, 'EUR'), 0),
        ));
    }

    private function setCost(string $variationId, string $decimalAmount): void
    {
        app(ProductCostRepository::class)->save(
            new ProductCost(id: null, priceableId: $variationId, cost: Money::fromDecimal($decimalAmount, 'EUR'))
        );
    }

    /** A SIMPLE product's UNIVERSAL variation, priced and stocked. */
    private function simpleVariation(string $decimalAmount): string
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $product = Product::createSimple("Simple {$suffix}", "SKU-S{$suffix}", "simple-{$suffix}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->addPriceItem('Regular Prices', $variationId, $decimalAmount);
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 10));

        return $variationId;
    }

    /**
     * A VARIABLE product with one SELECT axis, one ACTIVE standard
     * variation, a price, and (optionally) a "Manual Sale" price so the
     * sold regular != final.
     *
     * @return array{variationId: string, productId: string, definitionName: string, value: string}
     */
    private function variableVariation(string $regular, ?string $sale = null, string $valueLabel = 'M'): array
    {
        self::$counter++;
        $suffix = (string) self::$counter;

        $definition = new AttributeDefinition(id: null, code: "size-{$suffix}", name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $valueLabel);
        app(AttributeValueRepository::class)->save($value);

        $product = Product::createVariable("Variable {$suffix}", "SKU-V{$suffix}", "variable-{$suffix}");
        $product->declareVariationAxes([new VariationAxis($definition, [$value])]);
        $variation = $product->addStandardVariation([$definition->id() => $value->id()], "SKU-V{$suffix}-0");
        $variation->activate();
        app(ProductRepository::class)->save($product);

        $this->addPriceItem('Regular Prices', $variation->id(), $regular);

        if ($sale !== null) {
            $this->addPriceItem('Manual Sale', $variation->id(), $sale);
        }

        app(StockLevelRepository::class)->save(StockLevel::forVariation($variation->id(), 10));

        return [
            'variationId' => $variation->id(),
            'productId' => $product->id(),
            'definitionName' => $definition->name(),
            'value' => $valueLabel,
        ];
    }

    private function guestCart(): Cart
    {
        return Cart::forGuest((string) Str::uuid(), new DateTimeImmutable('+10 days'));
    }

    private function addLine(Cart $cart, string $variationId, int $quantity = 1): void
    {
        app(CartLineAdder::class)->addLine($cart, $variationId, $quantity, null, null);
    }

    private function place(Cart $cart): Order
    {
        $input = new CheckoutInput(
            cartId: $cart->id(),
            guestCartToken: app(CartRepository::class)->findById($cart->id())?->sessionToken(),
            email: 'guest@example.com',
            recipientName: 'Guest Buyer',
            phone: '+359888000000',
            paymentMethod: 'cash_on_delivery',
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            country: 'BG',
            city: 'Sofia',
            addressLine1: 'Vitosha Blvd 1',
        );

        return app(CheckoutOrchestrator::class)
            ->place($input, new DateTimeImmutable('2026-09-25 10:00:00'))
            ->order();
    }

    /** One order with $lineCount single-unit SIMPLE lines, each its own product. */
    private function orderWithLines(int $lineCount): Order
    {
        $cart = $this->guestCart();

        for ($i = 0; $i < $lineCount; $i++) {
            $this->addLine($cart, $this->simpleVariation('10.00'), 1);
        }

        app(CartRepository::class)->save($cart);

        return $this->place($cart);
    }

    private function viewHtml(Order $order): string
    {
        return $this->get(OrderResource::getUrl('view', ['record' => $order->id()]))->assertOk()->getContent();
    }

    /**
     * The rendered Lines table's own `<tr>` bodies, one entry per line —
     * found by slicing the section between the Items heading and the end
     * of its table body. Locale-safe (no section-name string matching) and
     * scoped to the row the assertion is actually about.
     *
     * @return array<int, string>
     */
    private function linesBodyRows(string $html): array
    {
        $sectionStart = strpos($html, __('orders.sections.lines'));
        $bodyStart = ($sectionStart === false) ? false : strpos($html, '<tbody>', $sectionStart);
        $bodyEnd = ($bodyStart === false) ? false : strpos($html, '</tbody>', $bodyStart);

        $this->assertNotFalse($sectionStart, 'Items section heading not found in the rendered page');
        $this->assertNotFalse($bodyStart, 'Items table body not found in the rendered page');
        $this->assertNotFalse($bodyEnd, 'Items table body end not found in the rendered page');

        $body = substr($html, $bodyStart, $bodyEnd - $bodyStart);
        preg_match_all('#<tr>(.*?)</tr>#s', $body, $matches);

        return $matches[1];
    }

    /**
     * D4 — a VARIABLE line with a Manual Sale (regular 80.00, final 60.00)
     * and a promotion code scoped to its own product, next to an
     * out-of-scope SIMPLE line: the struck regular price, the ordered
     * sold attributes, the share on the applicable line, zero on the
     * other, and each line's own net all render.
     */
    public function test_a_variable_line_shows_the_struck_regular_price_attributes_share_and_net(): void
    {
        $this->seedPricingLists();

        $variable = $this->variableVariation(regular: '80.00', sale: '60.00', valueLabel: 'M');
        $otherVariationId = $this->simpleVariation('10.00');

        $promotion = Promotion::create(code: 'SCOPED10', discountType: PromotionDiscountType::PERCENTAGE, percentageBasisPoints: 1000);
        app(PromotionRepository::class)->save($promotion);
        app(PromotionScopeRepository::class)->attach(new PromotionScope(
            id: null,
            promotionId: $promotion->id(),
            scopeType: PromotionScopeType::PRODUCT,
            scopeReferenceId: $variable['productId'],
            mode: PromotionScopeMode::INCLUDE,
        ));

        $cart = $this->guestCart();
        $this->addLine($cart, $variable['variationId'], 1);
        $this->addLine($cart, $otherVariationId, 1);
        $cart->applyPromotionCode('SCOPED10');
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);
        $this->actingAsRole('Administrator');

        $rows = $this->linesBodyRows($this->viewHtml($order));
        $this->assertCount(2, $rows);

        // Line 1: VARIABLE, Manual Sale, in scope. Regular 80.00 struck,
        // final 60.00; attribute; share 10% of 60.00 = 6.00; net 54.00.
        $this->assertStringContainsString('<s>80.00 €</s> 60.00 €', $rows[0]);
        $this->assertStringContainsString('Size: M', $rows[0]);
        $this->assertStringContainsString('6.00 €', $rows[0]);
        $this->assertStringContainsString('54.00 €', $rows[0]);

        // Line 2: SIMPLE, out of scope — no struck price, zero share, net 10.00.
        $this->assertStringNotContainsString('<s>', $rows[1]);
        $this->assertStringContainsString('0.00 €', $rows[1]);
        $this->assertStringContainsString('10.00 €', $rows[1]);
    }

    /** D4 — a SIMPLE line with regular == final: no struck price, no attributes. */
    public function test_a_simple_line_with_no_discount_shows_no_struck_price_and_no_attributes(): void
    {
        $this->seedPricingLists();

        $cart = $this->guestCart();
        $this->addLine($cart, $this->simpleVariation('10.00'), 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);
        $this->actingAsRole('Administrator');

        $rows = $this->linesBodyRows($this->viewHtml($order));
        $this->assertCount(1, $rows);

        $this->assertStringContainsString('10.00 €', $rows[0]);
        $this->assertStringNotContainsString('<s>', $rows[0]);
        $this->assertStringNotContainsString('Size:', $rows[0]);
    }

    /**
     * D2's own snapshot-immutability rule for the NEW fields: after the
     * order, rename the sold attribute value and re-price the variation —
     * the View page still shows the sold label and the sold prices.
     */
    public function test_the_view_page_still_shows_the_sold_attributes_and_prices_after_the_product_changes(): void
    {
        $this->seedPricingLists();

        $variable = $this->variableVariation(regular: '80.00', sale: '60.00', valueLabel: 'Sold Label');

        $cart = $this->guestCart();
        $this->addLine($cart, $variable['variationId'], 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);

        // Rename the sold attribute value, through the real repository.
        $valueId = DB::table('catalog_variation_attribute_values')
            ->where('variation_id', $variable['variationId'])
            ->value('attribute_value_id');

        $this->assertNotNull($valueId, 'fixture assumption: the sold variation has an attribute value');

        $attributeValue = app(AttributeValueRepository::class)->findById((string) $valueId);
        $attributeValue->rename('Renamed Label');
        app(AttributeValueRepository::class)->save($attributeValue);

        // Re-price the variation through the same Price List the order used.
        $this->addPriceItem('Manual Sale', $variable['variationId'], '999.00');

        $this->actingAsRole('Administrator');
        $rows = $this->linesBodyRows($this->viewHtml($order));

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('Sold Label', $rows[0]);
        $this->assertStringNotContainsString('Renamed Label', $rows[0]);
        $this->assertStringContainsString('<s>80.00 €</s> 60.00 €', $rows[0]);
        $this->assertStringNotContainsString('999.00', $rows[0]);
    }

    /**
     * D5 — a line written before §3.13's snapshot migration (every
     * snapshot column NULL) keeps the derived amount/quantity unit price,
     * shows '—' for the promotion share and the net (never an order-level
     * guess), and carries the "Recorded before full snapshot" note. The
     * legacy shape cannot be produced by a real checkout any more, so it
     * is written directly, exactly as OrderAdminReaderTest's own
     * null-product-name case does.
     */
    public function test_a_legacy_line_shows_the_derived_unit_price_dashes_and_a_note(): void
    {
        $this->seedPricingLists();

        $cart = $this->guestCart();
        $this->addLine($cart, $this->simpleVariation('10.00'), 2);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);

        DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->update([
                'regular_unit_price_minor' => null,
                'regular_unit_price_currency' => null,
                'final_unit_price_minor' => null,
                'final_unit_price_currency' => null,
                'promotion_discount_share_minor' => null,
                'promotion_discount_share_currency' => null,
                'discretionary_discount_minor' => null,
                'discretionary_discount_currency' => null,
                'net_paid_amount_minor' => null,
                'net_paid_amount_currency' => null,
                'unit_cost_minor' => null,
                'unit_cost_currency' => null,
                'sold_attributes' => null,
            ]);

        $this->actingAsRole('Administrator');
        $rows = $this->linesBodyRows($this->viewHtml($order));

        $this->assertCount(1, $rows);
        // Determined purely from amount (20.00) / quantity (2).
        $this->assertStringContainsString('10.00 €', $rows[0]);
        $this->assertStringNotContainsString('<s>', $rows[0]);
        $this->assertStringContainsString(__('orders.legacy_line_note'), $rows[0]);
        $this->assertStringContainsString(__('orders.not_available'), $rows[0]);
    }

    /**
     * D2 — a half-populated money pair is corruption, not legacy: the View
     * request must fail loudly with the mapper's own rule rather than
     * silently rendering '—'. The exception may be wrapped by Blade's
     * view engine, so only its message chain is asserted.
     */
    public function test_a_half_populated_money_pair_fails_loudly_instead_of_showing_a_dash(): void
    {
        $this->seedPricingLists();

        $cart = $this->guestCart();
        $this->addLine($cart, $this->simpleVariation('10.00'), 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);

        DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->update(['net_paid_amount_currency' => null]);

        $this->actingAsRole('Administrator');
        $this->withoutExceptionHandling();

        $caught = null;

        try {
            $this->viewHtml($order);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'a corrupt money pair must fail the View request, not render a dash');
        $this->assertStringContainsString('netPaidAmount', $caught->getMessage());
        $this->assertStringContainsString('half-populated', $caught->getMessage());
    }

    /**
     * D4 — the discretionary (register) discount column is shown only when
     * at least one line on the order actually carries a non-zero value.
     * Web checkout always writes zero, so the "shown" case is simulated by
     * writing a non-zero value onto its sale line directly.
     */
    public function test_the_discretionary_discount_column_appears_only_when_a_line_has_one(): void
    {
        $this->seedPricingLists();

        $orderWithout = $this->orderWithLines(1);
        $orderWith = $this->orderWithLines(1);

        DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $orderWith->transactionId())
            ->update(['discretionary_discount_minor' => 250, 'discretionary_discount_currency' => 'EUR']);

        $this->actingAsRole('Administrator');

        $this->assertStringNotContainsString(
            __('orders.fields.discretionary_discount'),
            $this->viewHtml($orderWithout),
        );

        $htmlWith = $this->viewHtml($orderWith);
        $this->assertStringContainsString(__('orders.fields.discretionary_discount'), $htmlWith);
        $this->assertStringContainsString('2.50 €', $this->linesBodyRows($htmlWith)[0]);
    }

    /**
     * D6 — the unit cost column exists only for staff holding
     * Permission::COST_VIEW, and a known cost renders in it; without the
     * permission neither the header nor the value appears.
     */
    public function test_the_unit_cost_column_requires_cost_view(): void
    {
        $this->seedPricingLists();

        $variationId = $this->simpleVariation('10.00');
        $this->setCost($variationId, '4.00');

        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);

        // Administrator holds COST_VIEW.
        $this->actingAsRole('Administrator');
        $htmlWithCost = $this->viewHtml($order);
        $this->assertStringContainsString(__('orders.fields.unit_cost'), $htmlWithCost);
        $this->assertStringContainsString('4.00 €', $this->linesBodyRows($htmlWithCost)[0]);

        // ORDER_VIEW without COST_VIEW — no seeded system role has that
        // shape, so a custom role supplies exactly it.
        $this->app->forgetScopedInstances();
        $this->actingAsPermissions([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]);

        $htmlWithoutCost = $this->viewHtml($order);
        $this->assertStringNotContainsString(__('orders.fields.unit_cost'), $htmlWithoutCost);
        $this->assertStringNotContainsString('4.00 €', $htmlWithoutCost);
    }

    /**
     * D1/D7 — the View page's query count does not grow with the number of
     * sale lines: the reader reads them in one query and never per line,
     * and the table's own column decisions reuse that same memoized read.
     * Both measurements start from a cold scoped container, so the only
     * difference between them is the line count.
     */
    public function test_view_page_query_count_is_identical_for_two_and_ten_lines(): void
    {
        $this->seedPricingLists();

        $twoLineOrder = $this->orderWithLines(2);
        $tenLineOrder = $this->orderWithLines(10);

        $this->actingAsRole('Administrator');

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->app->forgetScopedInstances();
        $this->viewHtml($twoLineOrder);
        $queriesForTwo = $count;

        $count = 0;
        $this->app->forgetScopedInstances();
        $this->viewHtml($tenLineOrder);
        $queriesForTen = $count;

        DB::flushQueryLog();

        fwrite(STDERR, "\n[query-count] order view page, 2 lines: {$queriesForTwo} queries, 10 lines: {$queriesForTen} queries\n");

        $this->assertSame($queriesForTwo, $queriesForTen, 'order view page query count must not grow with the number of lines');
    }

    /**
     * The sold-attributes snapshot gets the SAME legacy-vs-corrupt split as
     * the money pairs (§3.13 stage 5 D2): on a NON-legacy line (this one was
     * written by the real checkout) NULL means the snapshot is missing, which
     * no legitimate write path produces — the View request must fail loudly
     * and name the sale line, never silently render the line with no
     * attributes at all. The legacy counterpart is the legacy test above,
     * where NULL is correct and yields an empty attribute list.
     */
    public function test_a_non_legacy_line_with_null_sold_attributes_fails_loudly(): void
    {
        $this->assertMalformedSoldAttributesFails(['sold_attributes' => null]);
    }

    /** Same rule for a snapshot that is stored JSON but not a list at all. */
    public function test_a_non_legacy_line_with_sold_attributes_that_are_not_a_list_fails_loudly(): void
    {
        // A JSON object, not a list. NOTE: the literal "invalid JSON" input
        // cannot reach this code path on this schema — operational_sales_
        // sale_lines.sold_attributes is a real MySQL `json` column, which
        // REJECTS invalid JSON at write time (MySQL error 3140, verified),
        // so `update(['sold_attributes' => '{not valid json'])` throws before
        // any View request. The decoder still guards the invalid-JSON case
        // for columns/drivers that do not validate; this reachable shape
        // exercises the very same "malformed, fail loud" branch.
        $this->assertMalformedSoldAttributesFails(['sold_attributes' => '{"not":"a list"}']);
    }

    /**
     * The literal invalid-JSON input cannot occur on this schema (see the
     * "not a list" test above), so this exercises the reader's own decoding
     * rule directly — proving that branch is real and not silently
     * permissive — while confirming the two shapes that must still be
     * accepted: a legacy line's NULL (no attributes were ever recorded) and
     * a genuine JSON list.
     */
    public function test_the_sold_attributes_decoder_rejects_malformed_non_legacy_snapshots(): void
    {
        $decode = new \ReflectionMethod(OrderAdminReader::class, 'decodeSoldAttributes');

        foreach (['{not valid json', '"just a string"', '{"not":"a list"}', ''] as $raw) {
            try {
                $decode->invoke(null, $raw, false, 'sale-line-7');
                $this->fail("Expected the decoder to reject [{$raw}].");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('sale-line-7', $exception->getMessage());
                $this->assertStringContainsString('sold_attributes', $exception->getMessage());
            }
        }

        $this->assertSame([], $decode->invoke(null, null, true, 'sale-line-7'));

        $snapshot = [[
            'definitionId' => '1',
            'definitionCode' => 'size',
            'definitionName' => 'Size',
            'valueId' => '2',
            'value' => 'M',
        ]];

        $this->assertSame([], $decode->invoke(null, '[]', false, 'sale-line-7'));
        $this->assertSame($snapshot, $decode->invoke(null, json_encode($snapshot), false, 'sale-line-7'));
    }

    /**
     * Writes $update onto a real checkout's sale line (raw SQL — the only
     * way such a state can exist) and asserts the View request surfaces an
     * exception naming the sale line, rather than rendering '—'.
     *
     * @param  array<string, mixed>  $update
     */
    private function assertMalformedSoldAttributesFails(array $update): void
    {
        $this->seedPricingLists();

        $cart = $this->guestCart();
        $this->addLine($cart, $this->simpleVariation('10.00'), 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);

        $saleLineId = (string) DB::table('operational_sales_sale_lines')
            ->where('transaction_id', $order->transactionId())
            ->value('id');

        DB::table('operational_sales_sale_lines')
            ->where('id', $saleLineId)
            ->update($update);

        $this->actingAsRole('Administrator');
        $this->withoutExceptionHandling();

        $caught = null;

        try {
            $this->viewHtml($order);
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'a malformed sold_attributes snapshot on a non-legacy line must fail the View request');
        $this->assertStringContainsString($saleLineId, $caught->getMessage());
        $this->assertStringContainsString('sold_attributes', $caught->getMessage());
    }
}
