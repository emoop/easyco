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
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Enums\MediaType;
use EasyCo\Media\MediaAsset;
use EasyCo\Media\ProductMedia;
use EasyCo\Media\VariationMedia;
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
use Filament\Support\Enums\Alignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
        return $this->itemBlocks($this->itemsArea($html));
    }

    /** The Items section from its heading to the money summary — where the line blocks are, and nothing else. */
    private function itemsArea(string $html): string
    {
        $start = strpos($html, __('orders.sections.lines'));
        $end = ($start === false) ? false : strpos($html, '<table style="margin-inline-start', $start);

        $this->assertNotFalse($start, 'Items section heading not found in the rendered page');
        $this->assertNotFalse($end, 'the money summary (the end of the item blocks) not found in the rendered page');

        return substr($html, $start, $end - $start);
    }

    /**
     * Since the order-view polish each line is ONE BLOCK (a repeatable item), not a table row.
     *
     * @return array<int, string>
     */
    private function itemBlocks(string $area): array
    {
        preg_match_all('#<li class="fi-in-repeatable-item[^"]*">(.*?)</li>#s', $area, $matches);

        return $matches[1];
    }

    /**
     * §14's column pass, carried over to the item blocks — a line shows NO unit cost for any role, in either locale.
     * Asserted so it cannot pass vacuously: the blocks really are there (price, SKU, total), there is no table (no
     * `<th>`) any more, and no block carries a cost wording.
     */
    private function assertNoUnitCostColumn(string $html): void
    {
        $area = $this->itemsArea($html);
        $blocks = $this->itemBlocks($area);

        $this->assertNotEmpty($blocks, 'the item blocks must be there');
        $this->assertSame(0, preg_match_all('#<th\b#', $area), 'the items are blocks, not a table');

        foreach ($blocks as $block) {
            $this->assertStringContainsString(__('orders.line_labels.sku'), $block);
            $this->assertDoesNotMatchRegularExpression('/cost|себестойност/iu', strip_tags($block));
        }
    }

    /**
     * A real READY image attached to a PRODUCT — the same fixture shape
     * SandboxProductPageTest uses, so the media pipeline's own states are
     * produced the way production produces them, never hand-written rows.
     *
     * THE FILE ITSELF IS ALSO WRITTEN, on a faked disk: Filament's ImageEntry
     * checks that the file exists before rendering a URL (confirmed in the
     * installed source), which is exactly why a missing file shows no image
     * rather than a broken one — so a path-only fixture would prove nothing
     * about what the page renders.
     */
    private function attachProductImage(string $productId, string $path): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', $path, 'Product photo');
        $asset->markProcessing();
        $asset->markReady([]);
        app(MediaAssetRepository::class)->save($asset);

        app(ProductMediaRepository::class)->save(new ProductMedia(
            id: null,
            productId: $productId,
            mediaId: (string) $asset->id(),
            sortOrder: 0,
            autoplay: false,
        ));

        $this->writeMediaFile($path);
    }

    /** The same, attached to a VARIATION — what a VARIABLE product's merchant sets per combination. */
    private function attachVariationImage(string $variationId, string $path): void
    {
        $asset = MediaAsset::create(MediaType::IMAGE, 'public', $path, 'Variation photo');
        $asset->markProcessing();
        $asset->markReady([]);
        app(MediaAssetRepository::class)->save($asset);

        app(VariationMediaRepository::class)->save(new VariationMedia(
            id: null,
            variationId: $variationId,
            mediaId: (string) $asset->id(),
            sortOrder: 0,
        ));

        $this->writeMediaFile($path);
    }

    /** The disk the View page's ImageEntry reads through — config, like the cell itself. */
    private function mediaDisk(): string
    {
        return (string) config('services.media.default_disk', 'public');
    }

    private function writeMediaFile(string $path): void
    {
        if (! Storage::disk($this->mediaDisk())->exists($path)) {
            Storage::disk($this->mediaDisk())->put($path, 'fake image bytes');
        }
    }

    /** The product a variation belongs to — the test's own read of the fixture, not a production path. */
    private function productIdOfVariation(string $variationId): string
    {
        return (string) DB::table('catalog_variations')->where('id', $variationId)->value('product_id');
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
        $this->assertStringNotContainsString(__('orders.line_labels.discount'), $rows[1], 'no discount line when there is no discount');
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
     * §14's own column pass — the unit cost column is gone for EVERY role,
     * Administrator included, so COST_VIEW no longer affects this page at
     * all: margin analysis belongs to a future reports screen holding
     * REPORT_VIEW *and* COST_VIEW explicitly. The fixture still records a
     * real cost for the sold variation, so the absence asserted below is
     * the COLUMN's absence, not a missing value — were the column to come
     * back, 4.00 would render.
     */
    public function test_the_lines_table_shows_no_unit_cost_for_any_role_administrator_included(): void
    {
        $this->seedPricingLists();

        $variationId = $this->simpleVariation('10.00');
        $this->setCost($variationId, '4.00');

        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);

        // Administrator holds COST_VIEW — the one role that used to see
        // this column, and the only one that ever could.
        $this->actingAsRole('Administrator');
        $administratorHtml = $this->viewHtml($order);

        $this->assertStringNotContainsString('4.00 €', $administratorHtml, 'no cost value may render on this page');
        $this->assertNoUnitCostColumn($administratorHtml);

        // ORDER_VIEW without COST_VIEW — no seeded system role has that
        // shape, so a custom role supplies exactly it.
        $this->app->forgetScopedInstances();
        $this->actingAsPermissions([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]);

        $restrictedHtml = $this->viewHtml($order);

        $this->assertStringNotContainsString('4.00 €', $restrictedHtml);
        $this->assertNoUnitCostColumn($restrictedHtml);
    }

    /**
     * Each line is one BLOCK: the product name as a link to the product's edit page (a new tab), and under it, muted,
     * "10.00 € × 2", the SKU; on the right the line total. The block has no minimum width and no sideways scroll.
     */
    public function test_each_line_block_links_to_the_product_and_shows_price_times_quantity_the_sku_and_the_total(): void
    {
        $this->seedPricingLists();

        $variationId = $this->simpleVariation('10.00');
        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 2);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);
        $this->actingAsRole('Administrator');
        $html = $this->viewHtml($order);
        $row = $this->linesBodyRows($html)[0];

        $editUrl = \App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $this->productIdOfVariation($variationId)]);
        $this->assertMatchesRegularExpression('#<a class="fi-link" href="'.preg_quote(e($editUrl), '#').'" target="_blank" rel="noopener noreferrer"[^>]*>Simple #', $row, 'the name links to the product edit page, in a new tab');
        $this->assertStringContainsString('10.00 € × 2', $row, 'unit price × quantity');
        $this->assertStringContainsString(__('orders.line_labels.sku').': ', $row);
        $this->assertStringContainsString('20.00 €', $row, 'the line total');

        // Muted secondary level, and a block that wraps on a phone: no minimum width, no overflow container.
        $this->assertStringContainsString('opacity: 0.65', $row);
        $this->assertStringContainsString('flex-wrap: wrap', $row);
        $this->assertStringNotContainsString('min-width: 5', $this->itemsArea($html), 'the items have no minimum width (History keeps its own)');
        $this->assertStringNotContainsString('overflow-x', $this->itemsArea($html));
    }

    /**
     * §14's thumbnail — every line shows what was sold, 36 px TALL and
     * HEIGHT ONLY, in the FIRST cell, before the product name. The width is
     * deliberately never written: with both dimensions set a photo that is
     * not square is cropped into its square box instead of keeping its own
     * shape. The fixture attaches a real READY image to the product itself:
     * a SIMPLE product's UNIVERSAL variation has no media of its own, so
     * this is also OrderAdminReader::imagePathsFor()'s fallback branch.
     */
    public function test_each_line_shows_a_36px_tall_thumbnail_of_its_product_before_the_name(): void
    {
        $this->seedPricingLists();
        Storage::fake($this->mediaDisk());

        $variationId = $this->simpleVariation('10.00');
        $this->attachProductImage($this->productIdOfVariation($variationId), 'products/ordered-product.jpg');

        $cart = $this->guestCart();
        $this->addLine($cart, $variationId, 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);
        $this->actingAsRole('Administrator');
        $row = $this->linesBodyRows($this->viewHtml($order))[0];

        $this->assertStringContainsString('products/ordered-product.jpg', $row);

        // 36 px TALL, HEIGHT ONLY — OrderResource::LINE_THUMBNAIL_HEIGHT_PX.
        // The width is asserted ABSENT on purpose: Filament writes a width
        // the moment ->square()/->imageSize() is used, and that height/width
        // pair is what cropped a non-square photo into a square box.
        preg_match('#<img\b[^>]*>#', $row, $image);

        $this->assertNotEmpty($image, 'the line must render the photo as an <img>');
        $this->assertStringContainsString('height: 36px', $image[0]);
        $this->assertStringNotContainsString('width', $image[0]);

        // The FIRST cell of the line: the thumbnail precedes the product name.
        $this->assertLessThan(
            strpos($row, 'Simple '),
            strpos($row, 'products/ordered-product.jpg'),
            'the thumbnail must come before the product name',
        );
    }

    /**
     * The variation's OWN photo wins over its product's — for a VARIABLE
     * product the merchant attaches the photo of the combination actually
     * bought, which is the more truthful thumbnail of the two.
     */
    public function test_a_variations_own_photo_wins_over_its_products(): void
    {
        $this->seedPricingLists();
        Storage::fake($this->mediaDisk());

        $variable = $this->variableVariation(regular: '80.00', valueLabel: 'M');

        $this->attachProductImage($variable['productId'], 'products/product-photo.jpg');
        $this->attachVariationImage($variable['variationId'], 'products/variation-photo.jpg');

        $cart = $this->guestCart();
        $this->addLine($cart, $variable['variationId'], 1);
        app(CartRepository::class)->save($cart);

        $order = $this->place($cart);
        $this->actingAsRole('Administrator');
        $row = $this->linesBodyRows($this->viewHtml($order))[0];

        $this->assertStringContainsString('products/variation-photo.jpg', $row);
        $this->assertStringNotContainsString('products/product-photo.jpg', $row);
    }

    /**
     * Fail-soft, D8: a line whose product has no usable photo renders NO
     * image at all — not an empty <img> box that reads as a broken picture,
     * and never an error. (The page itself renders fine: viewHtml() asserts
     * a 200.)
     */
    public function test_a_line_with_no_usable_photo_renders_without_a_thumbnail(): void
    {
        $this->seedPricingLists();

        $order = $this->orderWithLines(1);

        $this->actingAsRole('Administrator');
        $row = $this->linesBodyRows($this->viewHtml($order))[0];

        $this->assertStringNotContainsString('<img', $row);
        $this->assertStringContainsString('Simple ', $row, 'the line itself must still render');
    }

    /**
     * D1/D7 — the View page's query count does not grow with the number of
     * sale lines: the reader reads them in one query and never per line,
     * and the table's own column decisions reuse that same memoized read.
     * Both measurements start from a cold scoped container, so the only
     * difference between them is the line count.
     *
     * THE ORDER-LIFECYCLE STAGE THAT ADDED ORDER_EVENTS ADDED EXACTLY ONE QUERY
     * TO THIS PAGE (+1), AND THIS TEST IS UNCHANGED ON PURPOSE: forOrder() now
     * also reads the order's own history — one query for the whole list, however
     * long it is (order-lifecycle-design.md §6.3, §10 stage 3). Both measurements
     * below carry that one query equally, which is precisely what "the count does
     * not grow with what the page renders" means, and the absolute cost is pinned
     * as a real number by OrderAdminReaderEventsTest instead of here — this test
     * never asserted a number to increment.
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
