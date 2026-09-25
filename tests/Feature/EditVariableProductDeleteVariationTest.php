<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use App\Services\ActivityLogger;
use DateTimeImmutable;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\VariationNotDeletableException;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The per-row "delete permanently" action on EditVariableProduct's
 * existing_variations Repeater — catalog-domain-design.md §3.19.8 A,
 * §3.19.9 (permission), §3.19.11 (freed identifiers).
 *
 * DRIVEN THROUGH THE REAL ACTION wherever the infrastructure allows it:
 * Filament v5 can address an action inside a named schema component
 * (TestAction::make(...)->schemaComponent('existing_variations', 'form')),
 * which is what mounts the real modal here — so the refusal modal, the
 * confirmation fields and the submit path are exercised as the merchant
 * would.
 *
 * THE PAGE METHOD IS PRIVATE, AND THIS FILE PROVES IT FROM THREE SIDES: a
 * browser call (`$component->call('deleteVariationById', …)`) is not
 * routable at all; a ReflectionMethod reports it non-public; and its own
 * gates — permission, ownership, confirmation — are reached only through
 * Reflection, which is the only way left to exercise them without a crafted
 * HTTP request. Ownership additionally gets a route that IS browser-
 * reachable: a real action call whose row's `variation_id` (public Livewire
 * state) was rewritten to another product's variation.
 */
class EditVariableProductDeleteVariationTest extends TestCase
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

    /** @return array{0: ProductModel, 1: string, 2: string} the model the page loads, and the Black/White variation ids */
    private function variableProductWithTwoVariations(): array
    {
        [$definition, $black, $white] = $this->persistedColorDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        app(ProductRepository::class)->save($product);

        $productModel = ProductModel::find($product->id());

        $variationIds = [];
        foreach ($product->variations() as $variation) {
            $variationIds[$variation->sku()] = (string) $variation->id();
        }

        return [$productModel, $variationIds['SKU-VAR-BLACK'], $variationIds['SKU-VAR-WHITE']];
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

    /**
     * The Repeater row key the action's own array $arguments['item'] carries
     * at render time (the Repeater's blade binds $action(['item' =>
     * $itemKey])) — read from the page's own live state rather than
     * guessed.
     */
    private function rowKeyFor(\Livewire\Features\SupportTesting\Testable $component, string $variationId): string
    {
        foreach ($component->get('data.existing_variations') ?? [] as $key => $row) {
            if ((string) ($row['variation_id'] ?? '') === $variationId) {
                return (string) $key;
            }
        }

        $this->fail("No existing_variations row for variation {$variationId}.");
    }

    private function deleteAction(): TestAction
    {
        return TestAction::make('delete_variation')->schemaComponent('existing_variations', 'form');
    }

    public function test_the_delete_action_is_visible_with_product_delete_and_hidden_without_it(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();

        $this->staffWithRole('Manager');

        $manager = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $manager->assertActionHidden($this->deleteAction(), ['item' => $this->rowKeyFor($manager, $blackId)]);

        // A fresh component, acting as a staff member who does hold it.
        $this->staffWithRole('Administrator');

        $administrator = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $administrator->assertActionVisible($this->deleteAction(), ['item' => $this->rowKeyFor($administrator, $blackId)]);
    }

    /**
     * §3.19.8 A/D: the refusal shows the DOMAIN's own sentence — the very
     * message VariationNotDeletableException carries — and offers archiving
     * instead. Asserted against the REAL mounted modal, and against the
     * mounted action's own footer state rather than a button label, because
     * "no delete button" is a statement about the action, not about a
     * string the row's trigger button also happens to contain.
     */
    public function test_the_refusal_modal_for_a_variation_with_history_shows_the_reason_and_has_no_delete_button(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->addSaleLine($blackId);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        $this->assertNull($mounted->getModalSubmitAction());

        // e(): the modal body is Blade-escaped, so a sentence containing
        // quotes arrives as &quot; — asserting the escaped form keeps the
        // comparison exact instead of loosening it to a quote-free fragment.
        $content = (string) $mounted->getModalContent();

        // RENDERED, NOT COPIED: the merchant sees the app-layer translation
        // of the reason. NOTE there is deliberately NO
        // assertStringNotContainsString() on the exception's own message
        // here: the English translation is word-for-word the domain's
        // sentence, so in English the two are indistinguishable by design.
        // The Bulgarian test below is where the distinction is observable.
        $this->assertStringContainsString(
            e((string) __('products.deletion.refusal.has_history', ['sku' => 'SKU-VAR-BLACK', 'count' => 1])),
            $content
        );
        $this->assertStringContainsString(e((string) __('products.deletion.archive_instead')), $content);
    }

    public function test_the_refusal_modal_for_non_zero_stock_shows_the_reason_and_has_no_delete_button(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->addStockLevel($blackId, 4);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        $this->assertNull($mounted->getModalSubmitAction());

        $content = (string) $mounted->getModalContent();

        $this->assertStringContainsString(
            e((string) __('products.deletion.refusal.has_stock', ['sku' => 'SKU-VAR-BLACK', 'count' => 4])),
            $content
        );
        $this->assertStringContainsString(e((string) __('products.deletion.archive_instead')), $content);
    }

    /**
     * The same two refusals, in Bulgarian — catalog-domain-design.md
     * §3.19.8 D's wording is the merchant's language, not the developer's.
     * The locale is set both ways on purpose: the setting is the merchant's
     * real configuration (ApplyStoreLocale's source), and app()->setLocale()
     * guarantees it for this request regardless of middleware.
     */
    public function test_the_refusal_modal_for_a_variation_with_history_renders_in_bulgarian(): void
    {
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.locale', 'bg');
        app()->setLocale('bg');

        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->addSaleLine($blackId);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        $this->assertNull($mounted->getModalSubmitAction());

        $content = (string) $mounted->getModalContent();

        $this->assertStringContainsString(
            e((string) __('products.deletion.refusal.has_history', ['sku' => 'SKU-VAR-BLACK', 'count' => 1])),
            $content
        );

        // The English sentence is nowhere in the merchant's modal...
        $this->assertStringNotContainsString('sale line(s)', $content);
        $this->assertStringContainsString('реда в продажби', $content);

        // ...while the exception itself stays English for the logs, in the
        // very same request.
        $this->assertSame('bg', app()->getLocale());
        $this->assertStringContainsString(
            'sale line(s)',
            VariationNotDeletableException::becauseItHasHistory('SKU-VAR-BLACK', 1)->getMessage()
        );
    }

    public function test_the_refusal_modal_for_non_zero_stock_renders_in_bulgarian(): void
    {
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.locale', 'bg');
        app()->setLocale('bg');

        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->addStockLevel($blackId, 4);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $content = (string) $component->instance()->getMountedAction()->getModalContent();

        $this->assertStringContainsString(
            e((string) __('products.deletion.refusal.has_stock', ['sku' => 'SKU-VAR-BLACK', 'count' => 4])),
            $content
        );

        $this->assertStringNotContainsString('in stock', $content);
        $this->assertStringContainsString('бройки наличност', $content);
    }

    /** The deletable modal: the impact the service reported, plus both confirmation fields and a real submit button. */
    public function test_the_confirmation_modal_lists_the_impact_and_offers_a_delete_button(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        $this->assertNotNull($mounted->getModalSubmitAction());

        $content = (string) $mounted->getModalContent();

        $this->assertStringContainsString(
            e((string) __('products.deletion.impact_intro', ['product' => 'Variable Shirt', 'sku' => 'SKU-VAR-BLACK'])),
            $content
        );
        $this->assertStringContainsString(
            e((string) __('products.deletion.impact_attributes', ['attributes' => 'Color: Black'])),
            $content
        );
        $this->assertStringContainsString(e((string) __('products.deletion.impact_stock', ['quantity' => 0])), $content);
        $this->assertStringContainsString(e((string) __('products.deletion.unsaved_edits_warning')), $content);
    }

    public function test_a_wrong_typed_sku_is_rejected_by_the_action_and_nothing_is_deleted(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $component->setActionData(['understand_permanent' => true, 'sku_confirmation' => 'SKU-VAR-NOT-BLACK']);
        $component->callMountedAction();

        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_an_unticked_confirmation_is_rejected_by_the_action_and_nothing_is_deleted(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $component->setActionData(['understand_permanent' => false, 'sku_confirmation' => 'SKU-VAR-BLACK']);
        $component->callMountedAction();

        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
    }

    public function test_a_successful_delete_through_the_action_notifies_redirects_and_removes_the_rows(): void
    {
        [$productModel, $blackId, $whiteId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $component->mountAction($this->deleteAction(), ['item' => $this->rowKeyFor($component, $blackId)]);

        $component->setActionData(['understand_permanent' => true, 'sku_confirmation' => 'SKU-VAR-BLACK']);

        $component->callMountedAction()
            ->assertNotified(__('products.deletion.notification_success', ['sku' => 'SKU-VAR-BLACK']))
            // §3.19.8: the page is RELOADED from the database, which is what
            // discards the unsaved edits the modal warned about.
            ->assertRedirect(ProductResource::getUrl('edit-variable', ['record' => $productModel]));

        $this->assertNull(VariationModel::withTrashed()->find($blackId));
        $this->assertNotNull(VariationModel::find($whiteId));

        $this->assertSame(1, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * FIX 1, first half: the method is not a Livewire method at all.
     *
     * Two independent proofs, because "not public" and "not callable over
     * the wire" are different claims: Reflection reports the modifier, and a
     * real Livewire call is rejected by the framework before any of the
     * method's own code could run — with nothing deleted, which is what
     * makes the rejection meaningful rather than merely noisy.
     */
    public function test_the_delete_method_is_not_a_public_livewire_method_and_a_browser_cannot_call_it(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $method = new \ReflectionMethod($component->instance(), 'deleteVariationById');

        $this->assertTrue($method->isPrivate(), 'A public method would be callable from the browser with arbitrary arguments.');
        $this->assertFalse($method->isPublic());

        $thrown = null;

        try {
            $component->call('deleteVariationById', $blackId, [
                'understand_permanent' => true,
                'sku_confirmation' => 'SKU-VAR-BLACK',
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A browser call to a private method must be rejected.');
        $this->assertStringContainsString('deleteVariationById', $thrown->getMessage());
        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * FIX 1, second half, browser-reachable route: a row's own
     * `variation_id` is public Livewire state, so a crafted request can
     * rewrite it to ANOTHER product's variation and mount the action on that
     * row. The modal must stay empty (no data from the other product) and
     * the delete must be refused even with PRODUCT_DELETE held — otherwise
     * the permission on product A's page would delete product B's data.
     */
    public function test_a_variation_of_another_product_cannot_be_deleted_even_with_the_permission(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        [, $otherVariationId] = $this->otherVariableProduct();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKey = $this->rowKeyFor($component, $blackId);

        $component->set("data.existing_variations.{$rowKey}.variation_id", $otherVariationId);

        $component->mountAction($this->deleteAction(), ['item' => $rowKey]);

        $mounted = $component->instance()->getMountedAction();

        // Nothing about the other product's variation is rendered: no
        // impact, no confirmation fields, no delete button. (callMountedAction()
        // is deliberately NOT used here — with the impact suppressed the
        // action has no schema at all, and Filament's mounted-action schema
        // property does not exist in that state.)
        $this->assertNotNull($mounted);
        $this->assertNull($mounted->getModalContent());
        $this->assertNull($mounted->getModalSubmitAction());

        // The gate itself, invoked with a flawless-looking confirmation: the
        // ownership check runs before any of it matters, so the answer is
        // still no.
        $this->invokeDeleteVariationById($component, $otherVariationId, [
            'understand_permanent' => true,
            'sku_confirmation' => 'SKU-OTHER-M',
        ]);

        $component->assertNotified(__('products.deletion.not_on_this_product'));

        $this->assertNotNull(VariationModel::withTrashed()->find($otherVariationId));
        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * §3.19.9's own point: visibility is not authorization. The private
     * method's gates are invoked directly here — the only way left to reach
     * them without a forged HTTP request — so each one is proven to exist on
     * its own rather than only through the UI paths that happen to exercise
     * it.
     */
    public function test_the_in_method_permission_gate_refuses_even_when_invoked_directly(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Manager');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $this->invokeDeleteVariationById($component, $blackId, [
            'understand_permanent' => true,
            'sku_confirmation' => 'SKU-VAR-BLACK',
        ]);

        $component->assertNotified(__('products.deletion.notification_unauthorized'));

        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_a_crafted_delete_call_with_a_wrong_confirmation_is_refused(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $this->invokeDeleteVariationById($component, $blackId, [
            'understand_permanent' => true,
            'sku_confirmation' => 'WRONG',
        ]);

        $component->assertNotified(__('products.deletion.notification_not_confirmed'));

        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    public function test_the_in_method_refusal_gate_refuses_a_variation_with_history(): void
    {
        [$productModel, $blackId] = $this->variableProductWithTwoVariations();
        $this->addSaleLine($blackId);
        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $this->invokeDeleteVariationById($component, $blackId, [
            'understand_permanent' => true,
            'sku_confirmation' => 'SKU-VAR-BLACK',
        ]);

        // The notification carries the LOCALISED refusal, not the domain's
        // English sentence — the same rendering the modal uses.
        $component->assertNotified(
            __('products.deletion.refusal.has_history', ['sku' => 'SKU-VAR-BLACK', 'count' => 1])
        );

        $this->assertNotNull(VariationModel::withTrashed()->find($blackId));
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * The private method, invoked the way only PHP itself can from outside
     * its class — the same documented technique EditVariableProductTest
     * already uses for getSaveFormAction(). Not a UI path: a deliberate way
     * to prove the guards exist behind the modal.
     *
     * @param array<string, mixed> $confirmation
     */
    private function invokeDeleteVariationById(
        \Livewire\Features\SupportTesting\Testable $component,
        string $variationId,
        array $confirmation,
    ): void {
        $method = new \ReflectionMethod($component->instance(), 'deleteVariationById');
        $method->setAccessible(true);
        $method->invoke($component->instance(), $variationId, $confirmation);
    }

    /**
     * A DIFFERENT product with its own variation — its own axis definition,
     * because attribute_definition.code is DB-unique. Used to prove the
     * ownership gate: this page edits the other product, so this variation
     * is never deletable from here.
     *
     * @return array{0: ProductModel, 1: string}
     */
    private function otherVariableProduct(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $product = Product::createVariable('Other Shirt', 'SKU-OTHER', 'other-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$medium])]);
        $variation = $product->addStandardVariation([$definition->id() => $medium->id()], 'SKU-OTHER-M');
        app(ProductRepository::class)->save($product);

        return [ProductModel::find($product->id()), (string) $variation->id()];
    }
}
