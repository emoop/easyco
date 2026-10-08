<?php

namespace Tests\Feature;

use App\Services\Exceptions\ShippingClassInvalidException;
use App\Services\Exceptions\ShippingClassNotFoundException;
use App\Services\QuoteDestination;
use App\Services\ShippingClassAssigner;
use App\Services\ShippingQuoteService;
use EasyCo\Address\Enums\AddressDeliveryType;
use EasyCo\Cart\Cart;
use EasyCo\Cart\CartLineAdder;
use EasyCo\Cart\Contracts\CartRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Contracts\VariationRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5b (shipping-domain-design.md §3.2, §12.3.4): assigning or clearing the shipping class of a product or a
 * variation. The class lives on the VARIATION (as its code); a SIMPLE product writes it to its single variation; a
 * VARIABLE product has no variation of its own, so it is refused. Each change is one audit entry and one hook after
 * the commit; setting what is already set writes nothing; the quote picks the assigned class up.
 */
class ShippingClassAssignerTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private static int $counter = 0;

    private ?PriceList $priceList = null;

    private function assigner(): ShippingClassAssigner
    {
        return app(ShippingClassAssigner::class);
    }

    /** @return array{0: string, 1: string} the product id and its single variation's id */
    private function simpleProduct(?string $class = null, ?string $price = null): array
    {
        self::$counter++;
        $n = self::$counter;

        $product = Product::createSimple("Assign Product {$n}", "AP-{$n}", "assign-product-{$n}");
        $product->variations()[0]->setShippingClass($class);
        app(ProductRepository::class)->save($product);
        $variationId = (string) $product->variations()[0]->id();

        if ($price !== null) {
            $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));

            app(PriceListItemRepository::class)->save(new PriceListItem(
                null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId,
                Price::exclusiveOfTax(Money::fromDecimal($price, 'EUR'), 0),
            ));
            app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));
        }

        return [(string) $product->id(), $variationId];
    }

    private function classId(string $code, ?string $name = null): string
    {
        $this->shippingClass($code, $name ?? ucfirst($code));

        return (string) app(ShippingClassRepository::class)->findByCode($code)->id();
    }

    private function storedClass(string $variationId): ?string
    {
        return DB::table('catalog_variations')->where('id', $variationId)->value('shipping_class');
    }

    private function productAudit(string $productId): array
    {
        return DB::table('activity_log')->where('entity_type', 'product')->where('entity_id', $productId)->where('field', 'like', '%shipping_class%')->orderBy('id')->get()->all();
    }

    private function hookNames(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->hookCalls);
    }

    // =====================================================================================================
    // A product (its single variation)
    // =====================================================================================================

    public function test_a_class_is_assigned_to_a_simple_product_through_its_single_variation(): void
    {
        $this->activityLogOn();
        $heavy = $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct();
        $baseline = $this->spyOnClassHooks();

        $this->assertTrue($this->assigner()->setForProduct($productId, $heavy));

        $this->assertSame('heavy', $this->storedClass($variationId), 'stored as the class CODE on the variation');

        $rows = $this->productAudit($productId);
        $this->assertCount(1, $rows, 'exactly one audit entry');
        $this->assertSame('product', $rows[0]->entity_type);
        $this->assertSame('shipping_class', $rows[0]->field);
        $this->assertNull($rows[0]->old_value);
        $this->assertSame('heavy', $rows[0]->new_value);

        $this->assertSame(['shipping.class.assigned'], $this->hookNames());
        $this->assertSame($baseline, $this->hookCalls[0][2], 'after the commit');
        $this->assertSame(['product', $productId, null, 'heavy'], $this->hookCalls[0][1]);
    }

    public function test_the_class_is_changed_and_then_cleared_one_audit_entry_and_one_hook_each(): void
    {
        $this->activityLogOn();
        $heavy = $this->classId('heavy');
        $light = $this->classId('light');
        [$productId, $variationId] = $this->simpleProduct('heavy');
        $this->spyOnClassHooks();

        $this->assertTrue($this->assigner()->setForProduct($productId, $light));
        $this->assertSame('light', $this->storedClass($variationId));

        $this->assertTrue($this->assigner()->setForProduct($productId, null));
        $this->assertNull($this->storedClass($variationId));

        $rows = $this->productAudit($productId);
        $this->assertCount(2, $rows);
        $this->assertSame(['heavy', 'light'], [$rows[0]->old_value, $rows[0]->new_value]);
        $this->assertSame(['light', null], [$rows[1]->old_value, $rows[1]->new_value]);
        $this->assertSame(['shipping.class.assigned', 'shipping.class.assigned'], $this->hookNames());
        $this->assertSame(['product', $productId, 'light', null], $this->hookCalls[1][1]);
        $this->assertNotSame($heavy, $light);
    }

    public function test_setting_what_is_already_set_or_clearing_what_is_empty_writes_nothing(): void
    {
        $this->activityLogOn();
        $heavy = $this->classId('heavy');
        [$productId] = $this->simpleProduct('heavy');
        [$emptyProductId] = $this->simpleProduct();
        $this->spyOnClassHooks();

        $this->assertFalse($this->assigner()->setForProduct($productId, $heavy));
        $this->assertFalse($this->assigner()->setForProduct($emptyProductId, null));
        $this->assertFalse($this->assigner()->setForProduct($emptyProductId, ''));

        $this->assertSame([], $this->productAudit($productId));
        $this->assertSame([], $this->productAudit($emptyProductId));
        $this->assertSame([], $this->hookCalls);
    }

    public function test_an_unknown_class_is_a_translated_refusal_and_nothing_changes(): void
    {
        $this->activityLogOn();
        [$productId, $variationId] = $this->simpleProduct('heavy');
        $this->spyOnClassHooks();

        try {
            $this->assigner()->setForProduct($productId, '999999');
            $this->fail('an unknown class must be refused');
        } catch (ShippingClassInvalidException $exception) {
            $this->assertSame(__('shipping.classes.errors.unknown_class'), $exception->messageFor('shipping_class'));
        }

        $this->assertSame('heavy', $this->storedClass($variationId));
        $this->assertSame([], $this->productAudit($productId));
        $this->assertSame([], $this->hookCalls);

        App::setLocale('bg');
        try {
            $this->assigner()->setForProduct($productId, 'not-an-id');
        } catch (ShippingClassInvalidException $exception) {
            $this->assertSame('Този клас за доставка не съществува.', $exception->messageFor('shipping_class'));
        }
    }

    public function test_a_variable_product_has_no_variation_of_its_own_and_is_refused(): void
    {
        $heavy = $this->classId('heavy');
        $product = Product::createVariable('Shirt', 'SH-1', 'shirt');
        app(ProductRepository::class)->save($product);
        $this->spyOnClassHooks();

        try {
            $this->assigner()->setForProduct((string) $product->id(), $heavy);
            $this->fail('a variable product must be refused');
        } catch (ShippingClassInvalidException $exception) {
            $this->assertSame(__('shipping.classes.errors.product_has_variations'), $exception->messageFor('shipping_class'));
        }

        $this->assertSame([], $this->hookCalls);
    }

    public function test_an_unknown_product_or_variation_is_a_translated_refusal(): void
    {
        $heavy = $this->classId('heavy');

        foreach ([fn () => $this->assigner()->setForProduct('99999999', $heavy), fn () => $this->assigner()->setForVariation('99999999', $heavy)] as $call) {
            try {
                $call();
                $this->fail('a missing target must be refused');
            } catch (ShippingClassNotFoundException $exception) {
                $this->assertSame(__('shipping.classes.target_not_found'), $exception->getMessage());
            }
        }
    }

    // =====================================================================================================
    // A variation
    // =====================================================================================================

    public function test_a_class_is_assigned_to_and_cleared_from_a_variation_with_its_own_audit_field_and_hook(): void
    {
        $this->activityLogOn();
        $heavy = $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct();
        $this->spyOnClassHooks();

        $this->assertTrue($this->assigner()->setForVariation($variationId, $heavy));
        $this->assertSame('heavy', $this->storedClass($variationId));

        $rows = DB::table('activity_log')->where('entity_type', 'product')->where('entity_id', $productId)->where('field', "variation[{$variationId}].shipping_class")->get();
        $this->assertCount(1, $rows);
        $this->assertSame([null, 'heavy'], [$rows[0]->old_value, $rows[0]->new_value]);
        $this->assertSame(['variation', $variationId, null, 'heavy'], $this->hookCalls[0][1]);

        $this->assertFalse($this->assigner()->setForVariation($variationId, $heavy), 'no-op');
        $this->assertTrue($this->assigner()->setForVariation($variationId, null));
        $this->assertNull($this->storedClass($variationId));
        $this->assertCount(2, $this->hookCalls);
    }

    public function test_the_class_is_read_from_the_variation_alone_there_is_no_product_level_class(): void
    {
        // Resolution order, as the code has it: ShippingQuoteService reads Variation::shippingClass() and nothing else.
        // A product-level value cannot exist (no column, no field) so a variation can never be "overridden" by one.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('catalog_products', 'shipping_class'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('catalog_variations', 'shipping_class'));

        $heavy = $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct();

        $this->assigner()->setForProduct($productId, $heavy);

        $this->assertSame('heavy', app(VariationRepository::class)->findById($variationId)->shippingClass());
        $this->assertSame('heavy', app(ProductRepository::class)->findByIdWithVariations($productId)->universalVariation()->shippingClass());
    }

    public function test_the_assigner_changes_no_other_field_of_the_product(): void
    {
        $heavy = $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct();
        $before = (array) DB::table('catalog_variations')->where('id', $variationId)->first();
        $productBefore = (array) DB::table('catalog_products')->where('id', $productId)->first();

        $this->assigner()->setForProduct($productId, $heavy);

        $after = (array) DB::table('catalog_variations')->where('id', $variationId)->first();
        unset($before['updated_at'], $after['updated_at']);
        $changed = array_keys(array_diff_assoc($after, $before));
        $this->assertSame(['shipping_class'], $changed);

        $productAfter = (array) DB::table('catalog_products')->where('id', $productId)->first();
        unset($productBefore['updated_at'], $productAfter['updated_at']);
        $this->assertSame($productBefore, $productAfter);
    }

    // =====================================================================================================
    // End to end: the quote picks the assigned class up
    // =====================================================================================================

    public function test_a_per_class_quote_uses_the_class_assigned_through_the_assigner(): void
    {
        $heavy = $this->classId('heavy');
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->perClassMethod($zone, 'Per class', ['heavy' => 900], base: 400);

        [, $variationId] = $this->simpleProduct(null, '10.00');
        $quote = fn () => app(ShippingQuoteService::class)->quote($this->cartWith($variationId), null, new QuoteDestination(AddressDeliveryType::STREET_ADDRESS, 'BG', 'Sofia'));

        $this->assertSame(['Per class' => 400], $this->amounts($quote()), 'no class: the base amount');

        $this->assigner()->setForVariation($variationId, $heavy);
        $this->assertSame(['Per class' => 900], $this->amounts($quote()), 'HEAVY assigned: the class amount');

        $this->assigner()->setForVariation($variationId, null);
        $this->assertSame(['Per class' => 400], $this->amounts($quote()), 'cleared: the base amount again');
    }

    private function cartWith(string $variationId): Cart
    {
        $cart = Cart::forGuest((string) Str::uuid(), new \DateTimeImmutable('+10 days'));
        app(CartRepository::class)->save($cart);
        app(CartLineAdder::class)->addLine($cart, $variationId, 1, null, null);

        return app(CartRepository::class)->findById($cart->id());
    }

    /** @return array<string, mixed> */
    private function amounts($result): array
    {
        $out = [];

        foreach ($result->methods as $method) {
            $out[$method->name] = $method->amountMinor ?? $method->unavailableReason;
        }

        return $out;
    }
}
