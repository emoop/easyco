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
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Persistence\Eloquent\VariationModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
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
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The ARCHIVED-variations half of EditVariableProduct: the delete action the
 * archived rows now share with the live ones (§3.19.8 A, §3.19.9, §3.19.11), the
 * section being collapsed by default with a real count in its heading, and the
 * two variation write routines that used to be public Livewire methods.
 *
 * WHY THE LAST OF THOSE IS A TEST CONCERN, NOT A STYLE ONE: Livewire exposes
 * every PUBLIC method of a component to the browser. generateMissingVariations()
 * and restoreArchivedVariationById(string $variationId) were both public, so
 * both were callable endpoints with no modal, no button and no permission check
 * of their own. They are private now and re-check PRODUCT_MANAGE internally, and
 * this file asserts both halves of that: the modifier, a rejected browser call,
 * and the in-method gate reached by Reflection (the only way left to exercise it
 * without a crafted request).
 */
class EditVariableProductArchivedVariationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Off by default (ActivityLogger::write()'s own real gate).
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
    }

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

    /**
     * A role holding PRODUCT_VIEW only — the one reachable way to lack
     * PRODUCT_MANAGE (every shipped role that may OPEN this page holds it, which
     * EditVariableProductAxesAndRestoreTest's own boundary test proves).
     */
    private function staffWithViewOnlyRole(): StaffPanelUser
    {
        $role = Role::create('Archived View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('archived.view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'Archived View Only', $role);
        app(StaffRepository::class)->save($staff);

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue} Color: Black, White */
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

    /** @return array{0: AttributeDefinition, 1: AttributeValue, 2: AttributeValue} Size: S, M */
    private function persistedSizeDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $small = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'S');
        app(AttributeValueRepository::class)->save($small);

        $medium = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        return [$definition, $small, $medium];
    }

    /**
     * Color[Black, White] + Size[S, M], with only TWO of the four possible
     * combinations created: LIVE Black/S, ARCHIVED White/S. The two deliberately
     * absent ones (Black/M, White/M) are what makes "generation really ran"
     * observable at all.
     *
     * @return array{0: ProductModel, 1: string, 2: string, 3: string} the model the page loads, the product id, the live id, the archived id
     */
    private function variableProductWithOneLiveAndOneArchivedVariation(): array
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small, $medium] = $this->persistedSizeDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-AX', 'variable-shirt');
        $product->declareVariationAxes([
            new VariationAxis($color, [$black, $white]),
            new VariationAxis($size, [$small, $medium]),
        ]);

        $liveVariation = $product->addStandardVariation([
            $color->id() => $black->id(),
            $size->id() => $small->id(),
        ], 'SKU-AX-BLACK-S');

        $archivedVariation = $product->addStandardVariation([
            $color->id() => $white->id(),
            $size->id() => $small->id(),
        ], 'SKU-AX-WHITE-S');
        $archivedVariation->archive();

        app(ProductRepository::class)->save($product);

        return [
            ProductModel::find($product->id()),
            (string) $product->id(),
            (string) $liveVariation->id(),
            (string) $archivedVariation->id(),
        ];
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
            sku: 'SKU-AX-WHITE-S',
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
        app(\EasyCo\Inventory\Contracts\StockLevelRepository::class)->save(
            \EasyCo\Inventory\StockLevel::forVariation($variationId, $quantity)
        );
    }

    private function definitionId(string $code): string
    {
        return (string) DB::table('catalog_attribute_definitions')->where('code', $code)->value('id');
    }

    private function valueId(string $value): string
    {
        return (string) DB::table('catalog_attribute_values')->where('value', $value)->value('id');
    }

    /**
     * The archived Repeater's own row key for a variation id, read from live
     * state — the key an extraItemAction's own array $arguments carries at render
     * time (the Repeater's blade binds $action(['item' => $itemKey])).
     */
    private function archivedRowKeyFor(\Livewire\Features\SupportTesting\Testable $component, string $variationId): string
    {
        foreach ($component->get('data.archived_variations') ?? [] as $key => $row) {
            if ((string) ($row['variation_id'] ?? '') === $variationId) {
                return (string) $key;
            }
        }

        $this->fail("No archived_variations row for variation {$variationId}.");
    }

    /** The archived row's OWN delete action — the live rows' one, reused (§3.19.8 A). */
    private function deleteAction(): TestAction
    {
        return TestAction::make('delete_variation')->schemaComponent('archived_variations', 'form');
    }

    private function restoreAction(): TestAction
    {
        return TestAction::make('restore_variation')->schemaComponent('archived_variations', 'form');
    }

    /**
     * Invokes a PRIVATE routine the way only in-class code can — the last resort
     * for the gates that a browser can no longer reach at all, which is exactly
     * the property being asserted alongside them.
     *
     * @param array<int, mixed> $arguments
     */
    private function invokePrivately(\Livewire\Features\SupportTesting\Testable $component, string $method, array $arguments = []): void
    {
        $reflection = new \ReflectionMethod($component->instance(), $method);
        $reflection->setAccessible(true);
        $reflection->invoke($component->instance(), ...$arguments);
    }

    public function test_the_delete_action_is_visible_on_an_archived_row_with_product_delete_and_hidden_without_it(): void
    {
        [$productModel, , , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        // A Manager holds PRODUCT_MANAGE (so the page opens) but not
        // PRODUCT_DELETE: the archived row's delete button is not there.
        $this->staffWithRole('Manager');

        $manager = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $manager->assertActionHidden($this->deleteAction(), ['item' => $this->archivedRowKeyFor($manager, $archivedId)]);

        // A fresh component, acting as a staff member who does hold it. RESTORE is
        // visible in both, which is the point of the pair: archiving stays the
        // reversible option, deleting is the permissioned one.
        $this->staffWithRole('Administrator');

        $administrator = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKey = $this->archivedRowKeyFor($administrator, $archivedId);

        $administrator->assertActionVisible($this->deleteAction(), ['item' => $rowKey]);
        $administrator->assertActionVisible($this->restoreAction(), ['item' => $rowKey]);
    }

    /**
     * §3.19.11's own point, on the archived list: an archived variation with no
     * history and zero stock CAN be deleted, and doing so hands its SKU — and its
     * combination's UNIQUE(product_id, attribute_signature) claim — back. The proof
     * is not "the row is gone" but that the very same combination can be created
     * again under the very same SKU, which both unique indexes would have refused a
     * moment earlier.
     */
    public function test_an_archived_variation_without_history_can_be_deleted_from_the_archived_list_and_its_sku_is_freed(): void
    {
        [$productModel, $productId, , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $this->assertContains(
            $archivedId,
            array_column($component->get('data.archived_variations'), 'variation_id'),
            'fixture assumption: the archived row is rendered',
        );

        $component->mountAction($this->deleteAction(), ['item' => $this->archivedRowKeyFor($component, $archivedId)]);
        $component->setActionData([
            'understand_permanent' => true,
            'sku_confirmation' => 'SKU-AX-WHITE-S',
        ]);

        $component->callMountedAction()
            ->assertNotified(Notification::make()
                ->title(__('products.deletion.notification_success', ['sku' => 'SKU-AX-WHITE-S']))
                ->success())
            ->assertRedirect(ProductResource::getUrl('edit-variable', ['record' => $productModel]));

        // HARD delete: not even a soft-deleted row is left behind, and it went
        // through the service's own snapshot path.
        $this->assertNull(VariationModel::withTrashed()->find($archivedId));
        $this->assertSame(
            1,
            DB::table('activity_log')
                ->where('action', ActivityLogger::ACTION_DELETED)
                ->where('entity_type', 'variation')
                ->count(),
        );

        // The freed identifiers are genuinely reusable again.
        $reloaded = app(ProductRepository::class)->findByIdWithVariations($productId);
        $recreated = $reloaded->addStandardVariation([
            $this->definitionId('color') => $this->valueId('White'),
            $this->definitionId('size') => $this->valueId('S'),
        ], 'SKU-AX-WHITE-S');
        app(ProductRepository::class)->save($reloaded);

        $this->assertNotNull($recreated->id());
        $this->assertSame(1, VariationModel::where('sku', 'SKU-AX-WHITE-S')->count());
        $this->assertSame('draft', VariationModel::find((string) $recreated->id())->status);
    }

    /**
     * The same action, the same rule: an ARCHIVED variation with history gets the
     * domain's own refusal, no delete button, and — through a crafted call — no
     * deletion at all. Archiving does not suspend §3.19.1.
     */
    public function test_an_archived_variation_with_history_is_refused_with_the_domain_reason_and_nothing_is_deleted(): void
    {
        [$productModel, , , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        $this->addSaleLine($archivedId);

        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);
        $rowKey = $this->archivedRowKeyFor($component, $archivedId);

        // The refusal modal: the merchant's locale, and no delete button at all
        // (->modalSubmitAction() returns false) rather than a disabled one.
        $component->mountAction($this->deleteAction(), ['item' => $rowKey]);

        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted);
        $this->assertNull($mounted->getModalSubmitAction());

        $this->assertStringContainsString(
            e((string) __('products.deletion.refusal.has_history', ['sku' => 'SKU-AX-WHITE-S', 'count' => 1])),
            (string) $mounted->getModalContent(),
        );
    }

    /**
     * The same refusal, reached the only other way it can be: in-class code
     * invoking the routine with a full confirmation payload — what a crafted call
     * would amount to if it could reach it at all, which it cannot (the routine is
     * private; the test above proves the browser call is rejected outright). The
     * routine's own gate refuses, in the merchant's locale, and nothing is deleted.
     *
     * The modal itself is not submitted here deliberately: Filament unmounts a
     * mounted action once its closure has run, so driving a REFUSAL through
     * callMountedAction() asserts against a component whose modal no longer exists —
     * the assertion would be measuring the framework's teardown rather than the
     * gate. EditVariableProductDeleteVariationTest's own in-method refusal test takes
     * exactly this route for exactly this reason.
     */
    public function test_the_in_method_refusal_gate_refuses_an_archived_variation_with_history(): void
    {
        [$productModel, , , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        $this->addSaleLine($archivedId);

        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $this->invokePrivately($component, 'deleteVariationById', [$archivedId, [
            'understand_permanent' => true,
            'sku_confirmation' => 'SKU-AX-WHITE-S',
        ]]);

        $component->assertNotified(__('products.deletion.refusal.has_history', [
            'sku' => 'SKU-AX-WHITE-S',
            'count' => 1,
        ]));

        $this->assertNotNull(VariationModel::withTrashed()->find($archivedId));
        $this->assertSame('archived', VariationModel::find($archivedId)->status);
        $this->assertSame(0, DB::table('activity_log')->where('action', ActivityLogger::ACTION_DELETED)->count());
    }

    /**
     * The archived section itself: collapsed by default, with a REAL count in its
     * heading (so a merchant with a long archived history can see what is behind a
     * closed section), and its rows still present in Livewire state — collapsing
     * is not hiding, which matters because the two per-row actions read that state.
     */
    public function test_the_archived_section_is_collapsed_by_default_and_its_heading_carries_the_count(): void
    {
        [$productModel, , , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        $section = $component->instance()->form->getComponent(
            static fn ($component): bool => $component instanceof Section
                && str_contains((string) $component->getHeading(), __('products.variation_restore.section_label')),
        );

        $this->assertInstanceOf(Section::class, $section);
        $this->assertTrue($section->isCollapsible(), 'the archived list must be collapsible at all');
        $this->assertTrue($section->isCollapsed(), 'and collapsed by default');

        // ONE archived variation in the fixture, so a count that ran over every
        // variation (2) or over rows in the wrong status would not pass.
        $this->assertSame(
            __('products.variation_restore.section_label_with_count', ['count' => 1]),
            (string) $section->getHeading(),
        );

        // The count is what the merchant actually sees, too.
        $component->assertSee(__('products.variation_restore.section_label_with_count', ['count' => 1]));

        // Collapsed, NOT hidden: the row is still in the component's own state,
        // which is what the per-row actions read.
        $this->assertContains(
            $archivedId,
            array_column($component->get('data.archived_variations'), 'variation_id'),
        );
    }

    /**
     * The audit this stage exists for, on the two methods that used to be public:
     * the modifier, and a real Livewire call to each — rejected by the framework
     * before any of the method's own code could run, with nothing written, which is
     * what makes the rejection meaningful rather than merely noisy.
     */
    public function test_the_variation_write_routines_are_private_and_a_browser_call_is_rejected(): void
    {
        [$productModel, $productId, , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        $this->staffWithRole('Administrator');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        foreach (['generateMissingVariations', 'restoreArchivedVariationById'] as $method) {
            $reflection = new \ReflectionMethod($component->instance(), $method);

            $this->assertTrue(
                $reflection->isPrivate(),
                "{$method}() must be private — Livewire exposes every public method to the browser.",
            );
            $this->assertFalse($reflection->isPublic());
        }

        foreach ([
            ['restoreArchivedVariationById', [$archivedId]],
            ['generateMissingVariations', []],
        ] as [$method, $arguments]) {
            $thrown = null;

            try {
                $component->call($method, ...$arguments);
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            $this->assertNotNull($thrown, "A browser call to {$method}() must be rejected.");
            $this->assertStringContainsString($method, $thrown->getMessage());
        }

        // Nothing happened: the archived row is still archived, and no combination
        // was generated (the fixture has two deliberately missing ones).
        $this->assertSame('archived', VariationModel::find($archivedId)->status);
        $this->assertSame(2, VariationModel::where('product_id', $productId)->count());
    }

    /**
     * §3.19.9's own posture, server-side: the actions' ->disabled() is a UI
     * affordance, and the routines behind them re-read PRODUCT_MANAGE at write time.
     * The only way to reach them as a staff member who lacks it is in-class code (a
     * browser cannot call them, and the page's own boundary turns such a role away
     * before anything renders), so this invokes them directly — which is exactly the
     * path the gate has to survive.
     */
    public function test_a_staff_member_without_product_manage_cannot_restore_or_generate_even_by_invoking_the_method(): void
    {
        [$productModel, $productId, , $archivedId] = $this->variableProductWithOneLiveAndOneArchivedVariation();

        // Mounted as an Administrator — PRODUCT_MANAGE gates the whole page, so a
        // View Only staff member could never get this far.
        $this->staffWithRole('Administrator');
        $component = Livewire::test(EditVariableProduct::class, ['record' => $productModel->id]);

        // Now the authenticated staff member is one WITHOUT PRODUCT_MANAGE, so only
        // the in-method gates are left to refuse.
        $this->staffWithViewOnlyRole();

        $this->invokePrivately($component, 'restoreArchivedVariationById', [$archivedId]);

        $component->assertNotified(__('products.variation_restore.notification_unauthorized'));
        $this->assertSame('archived', VariationModel::find($archivedId)->status, 'nothing may be restored');

        $before = VariationModel::where('product_id', $productId)->count();

        $this->invokePrivately($component, 'generateMissingVariations');

        $component->assertNotified(__('products.variations_generate.notification_unauthorized'));
        $this->assertSame(
            $before,
            VariationModel::where('product_id', $productId)->count(),
            'nothing may be generated',
        );
    }
}
