<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\StaffPanelUser;
use App\Services\ActivityLogger;
use App\Settings\Contracts\SiteSettingsRepository;
use DateTimeImmutable;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\OperationalSales\Contracts\TransactionRepository;
use EasyCo\OperationalSales\Enums\Channel;
use EasyCo\OperationalSales\Enums\SaleLineStatus;
use EasyCo\OperationalSales\Persistence\Eloquent\ClientModel;
use EasyCo\OperationalSales\SaleLine;
use EasyCo\OperationalSales\Transaction;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ARCHIVED-product delete action on ViewProduct's header and on the
 * products list row — catalog-domain-design.md §3.19.8 B, §3.19.9
 * (permission), §3.19.11 (freed identifiers).
 *
 * DRIVEN THROUGH THE REAL ACTION: `mountAction('delete_product')` mounts the
 * real modal on the page, so the content, the confirmation fields and the
 * submit path are exercised as the merchant would use them.
 *
 * THE RECORD IS NEVER PASSED IN, AND THAT IS THE POINT: the action reads the
 * page's own record, and no page or Resource defines a public method taking a
 * product id — proven here from two sides, a Reflection check and a real
 * Livewire call.
 */
class ViewProductDeleteProductTest extends TestCase
{
    use RefreshDatabase;

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

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue} */
    private function persistedColorDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        return [$definition, $black, $white];
    }

    /**
     * A VARIABLE product, saved, then archived (or not) — the two states G-D3
     * distinguishes.
     *
     * @return array{0: ProductModel, 1: string, 2: string} the model the page loads, and the Black/White variation ids
     */
    private function variableProduct(bool $archived = true, string $baseSku = 'SKU-VAR', string $slug = 'variable-shirt'): array
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', $baseSku, $slug);
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $product->addStandardVariation([$definition->id() => $black->id()], $baseSku.'-BLACK');
        $product->addStandardVariation([$definition->id() => $white->id()], $baseSku.'-WHITE');

        if ($archived) {
            $product->archive();
        }

        app(ProductRepository::class)->save($product);

        $productModel = ProductModel::find($product->id());

        $variationIds = [];
        foreach ($product->variations() as $variation) {
            $variationIds[$variation->sku()] = (string) $variation->id();
        }

        return [$productModel, $variationIds[$baseSku.'-BLACK'], $variationIds[$baseSku.'-WHITE']];
    }

    private function addSaleLine(string $variationId): void
    {
        $clientId = (string) ClientModel::create(['name' => 'Test Client'])->id;

        $transaction = new Transaction(id: null, channel: Channel::POS);
        $transaction->addSaleLine(SaleLine::create(
            transactionId: '',
            clientId: $clientId,
            priceableId: $variationId,
            status: SaleLineStatus::COMPLETED,
            quantity: 1,
            amount: Money::fromMinorUnits(2500, 'EUR'),
            profit: Money::fromMinorUnits(400, 'EUR'),
            recordedAt: new DateTimeImmutable('2026-08-25 10:00:00'),
            effectiveAt: new DateTimeImmutable('2026-08-20 09:00:00'),
            productName: 'Variable Shirt',
            sku: 'SKU-VAR-BLACK',
            regularUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(2500, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);
    }

    private function addStockLevel(string $variationId, int $quantity): void
    {
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, $quantity));
    }

    /** One product media pivot (and the untouched catalog_media asset behind it), for the modal's count split. */
    private function addProductMedia(string $productId): void
    {
        $mediaId = (int) DB::table('catalog_media')->insertGetId([
            'type' => 'image',
            'disk' => 'public',
            'path' => 'products/product.jpg',
            'alt_text' => 'A shirt',
            'processing_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('catalog_product_media')->insert([
            'product_id' => $productId,
            'media_id' => $mediaId,
            'sort_order' => 0,
        ]);
    }

    /** How many catalog rows the product still has — 0 only when every variation row AND the product row are gone. */
    private function productCatalogRows(string $productId): int
    {
        return VariationModel::withTrashed()->where('product_id', $productId)->count()
            + DB::table('catalog_products')->where('id', $productId)->count();
    }

    public function test_the_delete_action_is_visible_only_with_product_delete_on_an_archived_product(): void
    {
        [$archived] = $this->variableProduct(archived: true);

        // A Manager holds PRODUCT_MANAGE but not PRODUCT_DELETE — §3.19.9.
        $this->staffWithRole('Manager');

        Livewire::test(ViewProduct::class, ['record' => $archived->id])
            ->assertActionHidden('delete_product');

        // A fresh component, acting as a staff member who does hold it.
        $this->staffWithRole('Administrator');

        Livewire::test(ViewProduct::class, ['record' => $archived->id])
            ->assertActionVisible('delete_product')
            // The two slots are mutually exclusive: an archived product is
            // never told to archive first.
            ->assertActionHidden('archive_first');
    }

    public function test_a_non_archived_product_hides_the_delete_action_and_shows_the_two_step_rule_instead(): void
    {
        [$draft] = $this->variableProduct(archived: false);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $draft->id])
            ->assertActionHidden('delete_product')
            ->assertActionVisible('archive_first');

        $component->mountAction('archive_first');

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);

        // ONE line explaining the two-step rule, naming the product — not a
        // dead button with no reason (§3.19.8 B).
        $this->assertSame(__('products.deletion.archive_first_heading'), (string) $mounted->getModalHeading());
        $this->assertStringContainsString('Archive "Variable Shirt" first', (string) $mounted->getModalDescription());

        // ...and a real way to take the first step: the product's own Edit
        // page, which is where Status is set to Archived.
        $component->callMountedAction()
            ->assertRedirect(ProductResource::getUrl('edit-variable', ['record' => $draft->id]));
    }

    public function test_the_refusal_modal_for_a_blocked_product_shows_the_reason_and_has_no_delete_button(): void
    {
        [$product, $blackId] = $this->variableProduct();
        $this->addSaleLine($blackId);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $product->id]);
        $component->mountAction('delete_product');

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        // "no delete button" is a statement about the action, not about a
        // string a button label might contain.
        $this->assertNull($mounted->getModalSubmitAction());

        $content = (string) $mounted->getModalContent();

        $this->assertStringContainsString(
            e((string) __('products.deletion.product_refusal.blocked_by_variations', [
                'product' => 'Variable Shirt',
                'variations' => __('products.deletion.blocked_variation.has_history', ['sku' => 'SKU-VAR-BLACK', 'count' => 1]),
            ])),
            $content,
        );
        $this->assertStringContainsString(e((string) __('products.deletion.product_archive_instead')), $content);

        // Every variation is still listed with its OWN verdict: the blocked one
        // and the perfectly deletable one.
        $this->assertStringContainsString(e((string) __('products.deletion.product_impact_will_archive')), $content);
        $this->assertStringContainsString(e((string) __('products.deletion.product_impact_will_delete')), $content);
    }

    /**
     * The same refusal, in Bulgarian — §3.19.8 D's wording is the merchant's
     * language. The locale is set both ways on purpose: the setting is the
     * merchant's real configuration (ApplyStoreLocale's source), and
     * app()->setLocale() guarantees it for this request regardless of
     * middleware.
     */
    public function test_the_refusal_modal_renders_in_bulgarian(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        app()->setLocale('bg');

        [$product, $blackId] = $this->variableProduct();
        $this->addStockLevel($blackId, 2);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $product->id]);
        $component->mountAction('delete_product');

        $content = (string) $component->instance()->getMountedAction()->getModalContent();

        $this->assertStringContainsString('не може да бъде изтрит', $content);
        $this->assertStringContainsString('бройки наличност', $content);
        $this->assertStringNotContainsString('cannot be deleted', $content);
        $this->assertStringNotContainsString('in stock', $content);
    }

    public function test_the_confirmation_modal_lists_the_variations_the_identifiers_and_the_counts(): void
    {
        [$product] = $this->variableProduct();
        $this->addProductMedia((string) $product->id);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $product->id]);
        $component->mountAction('delete_product');

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        $this->assertNotNull($mounted->getModalSubmitAction());

        $content = (string) $mounted->getModalContent();

        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_intro', ['product' => 'Variable Shirt', 'sku' => 'SKU-VAR'])),
            $content,
        );
        // §3.19.11: the identifiers that become reusable are stated.
        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_identifiers', ['base_sku' => 'SKU-VAR', 'slug' => 'variable-shirt'])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_variations', ['count' => 2])),
            $content,
        );
        // Each variation with its own verdict and its own combination.
        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_variation', [
                'sku' => 'SKU-VAR-BLACK',
                'attributes' => 'Color: Black',
                'verdict' => __('products.deletion.product_impact_will_delete'),
            ])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_variation', [
                'sku' => 'SKU-VAR-WHITE',
                'attributes' => 'Color: White',
                'verdict' => __('products.deletion.product_impact_will_delete'),
            ])),
            $content,
        );
        // D6: the counts are real, split by scope, and the FILES are kept.
        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_price_list_items', ['total' => 0, 'variation' => 0, 'product' => 0])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.deletion.product_impact_media', ['total' => 1, 'variation' => 0, 'product' => 1])),
            $content,
        );
        $this->assertStringContainsString('files themselves are kept', $content);
        $this->assertStringContainsString(e((string) __('products.deletion.product_after_delete_note')), $content);
    }

    public function test_a_wrong_typed_base_sku_is_rejected_server_side_and_nothing_is_deleted(): void
    {
        [$product] = $this->variableProduct();
        $productId = (string) $product->id;
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $productId]);
        $component->mountAction('delete_product');
        $component->setActionData(['understand_permanent' => true, 'base_sku_confirmation' => 'SKU-VAR-NOT-THIS']);
        $component->callMountedAction();

        $this->assertSame(3, $this->productCatalogRows($productId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_an_unticked_confirmation_is_rejected_server_side_and_nothing_is_deleted(): void
    {
        [$product] = $this->variableProduct();
        $productId = (string) $product->id;
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $productId]);
        $component->mountAction('delete_product');
        $component->setActionData(['understand_permanent' => false, 'base_sku_confirmation' => 'SKU-VAR']);
        $component->callMountedAction();

        $this->assertSame(3, $this->productCatalogRows($productId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_a_successful_delete_from_the_view_page_notifies_and_redirects_to_the_list(): void
    {
        [$product] = $this->variableProduct();
        $productId = (string) $product->id;
        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $productId]);
        $component->mountAction('delete_product');
        $component->setActionData(['understand_permanent' => true, 'base_sku_confirmation' => 'SKU-VAR']);

        $component->callMountedAction()
            ->assertNotified(__('products.deletion.product_notification_success', ['name' => 'Variable Shirt']))
            // §3.19.8 B: back to the list — the record no longer exists, so
            // its own View page cannot be re-rendered.
            ->assertRedirect(ProductResource::getUrl('index'));

        $this->assertSame(0, $this->productCatalogRows($productId));
        $this->assertSame(1, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_a_successful_delete_from_the_list_row_action(): void
    {
        [$product] = $this->variableProduct();
        $productId = (string) $product->id;
        $this->staffWithRole('Administrator');

        $record = ProductModel::find($productId);

        // The row is only ever reachable through the Archived status
        // view — which is exactly where the action belongs.
        $component = Livewire::test(ListProducts::class)
            ->set('statusView', 'archived');

        $component->assertTableActionVisible('delete_product', $record);

        $component->callTableAction('delete_product', $record, [
            'understand_permanent' => true,
            'base_sku_confirmation' => 'SKU-VAR',
        ])
            ->assertNotified(__('products.deletion.product_notification_success', ['name' => 'Variable Shirt']))
            ->assertRedirect(ProductResource::getUrl('index'));

        $this->assertSame(0, $this->productCatalogRows($productId));
    }

    /**
     * §3.19.8 B's "no public Livewire method that accepts a product id",
     * proven from three sides: no such method exists on either page, the
     * Resource's own helpers are private, and a real browser call is rejected
     * by the framework with nothing deleted.
     */
    public function test_the_delete_routine_is_not_a_public_livewire_method_and_a_browser_cannot_call_it(): void
    {
        [$product] = $this->variableProduct();
        $productId = (string) $product->id;
        $this->staffWithRole('Administrator');

        foreach (['deleteProduct', 'deleteProductById', 'deleteProductRecord', 'delete'] as $forbidden) {
            $this->assertFalse(method_exists(ViewProduct::class, $forbidden), "ViewProduct::{$forbidden}() must not exist.");
            $this->assertFalse(method_exists(ListProducts::class, $forbidden), "ListProducts::{$forbidden}() must not exist.");
        }

        // The helpers the action itself calls are private, and the only public
        // entry point is the Action object Filament binds the record to.
        $this->assertTrue((new \ReflectionMethod(ProductResource::class, 'deleteProductRecord'))->isPrivate());
        $this->assertTrue((new \ReflectionMethod(ProductResource::class, 'productDeletionIsConfirmed'))->isPrivate());
        $this->assertTrue((new \ReflectionMethod(ProductResource::class, 'productDeletionImpactView'))->isPrivate());

        $component = Livewire::test(ViewProduct::class, ['record' => $productId]);

        $thrown = null;

        try {
            $component->call('deleteProductById', $productId, [
                'understand_permanent' => true,
                'base_sku_confirmation' => 'SKU-VAR',
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A browser call to a method that does not exist must be rejected.');
        $this->assertSame(3, $this->productCatalogRows($productId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * The action works on the record FILAMENT gave it and on nothing else: two
     * archived products, and the delete from one page leaves the other's rows
     * exactly where they were.
     */
    public function test_deleting_from_one_products_page_leaves_another_archived_product_untouched(): void
    {
        [$product] = $this->variableProduct();

        $other = Product::createSimple('Other Hat', 'SKU-OTHER', 'other-hat');
        $other->archive();
        app(ProductRepository::class)->save($other);
        $otherId = (string) $other->id();

        $this->staffWithRole('Administrator');

        $component = Livewire::test(ViewProduct::class, ['record' => $product->id]);
        $component->mountAction('delete_product');
        $component->setActionData(['understand_permanent' => true, 'base_sku_confirmation' => 'SKU-VAR']);
        $component->callMountedAction();

        $this->assertSame(0, $this->productCatalogRows((string) $product->id));
        $this->assertSame(2, $this->productCatalogRows($otherId));
        $this->assertSame(
            ProductStatus::ARCHIVED->value,
            DB::table('catalog_products')->where('id', $otherId)->value('status'),
        );
    }
}
