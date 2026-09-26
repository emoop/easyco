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
use EasyCo\Catalog\Exceptions\UnsafeAxisRedeclarationException;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "Change axes" action on EditVariableProduct's Axes tab —
 * catalog-domain-design.md §3.19.8 C, §3.19.9 (permissions), §3.17 (the
 * directional guard the action exists to satisfy).
 *
 * DRIVEN THROUGH THE REAL ACTION: `mountAction('change_axes')` mounts the real
 * modal, so the axes inputs, the impact preview, the confirmation fields and the
 * submit path are exercised as the merchant would use them.
 *
 * The SERVICE's own behaviour (plans, permission mode, freed identifiers,
 * unrestorable reporting, atomicity, the concurrency window) is covered in
 * VariationAxisRestructureTest; this file covers the surface: what is rendered,
 * what is refused, and what a crafted call can and cannot do.
 */
class EditVariableProductChangeAxesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Off by default (ActivityLogger::write()'s own real gate) — this file
        // asserts the restructure's own log entries (the same posture
        // ProductActivityLogTest takes for the fields it covers).
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

    /** A custom role holding only PRODUCT_VIEW — the one reachable way to lack PRODUCT_MANAGE (every shipped role that may open this page holds it). */
    private function staffWithViewOnlyRole(): StaffPanelUser
    {
        $role = Role::create('Axes View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('axes.view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'Axes View Only', $role);
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

    /** @return array{0: AttributeDefinition, 1: AttributeValue} Size: S */
    private function persistedSizeDefinition(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $small = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'S');
        app(AttributeValueRepository::class)->save($small);

        return [$definition, $small];
    }

    /**
     * The product the action's own plan splits: Color[Black, White] + Size[S],
     * two LIVE variations — SKU-AX-1 (no history: deletable) and SKU-AX-5
     * (which a caller may give history: archivable).
     *
     * @return array{0: ProductModel, 1: Product}
     */
    private function persistedVariableProduct(): array
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        $product = Product::createVariable('Variable Shirt', 'SKU-AX', 'variable-shirt');
        $product->declareVariationAxes([
            new VariationAxis($color, [$black, $white]),
            new VariationAxis($size, [$small]),
        ]);
        $product->addStandardVariation([$color->id() => $black->id(), $size->id() => $small->id()], 'SKU-AX-1');
        $product->addStandardVariation([$color->id() => $white->id(), $size->id() => $small->id()], 'SKU-AX-5');

        app(ProductRepository::class)->save($product);

        return [ProductModel::find($product->id()), $product];
    }

    /**
     * One axes row in the modal's own shape (->fillForm()'s seed and the
     * Repeater's submitted state are the same array).
     *
     * @return array{attribute_definition_id: string, value_ids: list<string>}
     */
    private function axesRow(string $definitionId, string ...$valueIds): array
    {
        return [
            'attribute_definition_id' => $definitionId,
            'value_ids' => array_values($valueIds),
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
            sku: 'SKU-AX-5',
            regularUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            finalUnitPrice: Money::fromMinorUnits(2500, 'EUR'),
            promotionDiscountShare: Money::zero('EUR'),
            discretionaryDiscount: Money::zero('EUR'),
            netPaidAmount: Money::fromMinorUnits(2500, 'EUR'),
            soldAttributes: [],
        ));

        app(TransactionRepository::class)->save($transaction);
    }

    private function variationIdBySku(string $productId, string $sku): string
    {
        return (string) VariationModel::withTrashed()->where('product_id', $productId)->where('sku', $sku)->value('id');
    }

    /** Every variation row of the product (soft-deleted included) as sku => status. */
    private function variationStatusBySku(string $productId): array
    {
        $statuses = [];

        foreach (VariationModel::withTrashed()->where('product_id', $productId)->orderBy('id')->get(['sku', 'status', 'deleted_at']) as $row) {
            $statuses[(string) $row->sku] = $row->deleted_at !== null ? 'soft-deleted' : (string) $row->status;
        }

        return $statuses;
    }

    /** The product's PERSISTED axes as definition id => sorted value ids. */
    private function persistedAxesOf(string $productId): array
    {
        $axes = [];

        foreach (DB::table('catalog_product_axis_values')->where('product_id', $productId)->get(['attribute_definition_id', 'attribute_value_id']) as $row) {
            $axes[(string) $row->attribute_definition_id][] = (string) $row->attribute_value_id;
        }

        foreach ($axes as $definitionId => $valueIds) {
            sort($valueIds, SORT_STRING);
            $axes[$definitionId] = $valueIds;
        }

        ksort($axes);

        return $axes;
    }

    /** The page, opened on the Axes tab — where the action lives (§3.19.8 C). */
    private function axesTabComponent(ProductModel $record)
    {
        return Livewire::withQueryParams(['tab' => 'axes'])->test(EditVariableProduct::class, ['record' => $record->id]);
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
     * Mounts the REAL "Change axes" action the way the merchant's own click does.
     *
     * The schemaComponent() address is the Section this action is a header action
     * of (see the page's axesTabComponents()): a nested action is not resolvable
     * by name alone, which is exactly why that Section carries a key.
     */
    private function mountChangeAxes($component): void
    {
        $component->mountAction(TestAction::make('change_axes')->schemaComponent('axes_change', 'form'));
    }

    /**
     * The impact the mounted modal is currently rendering, as the merchant sees
     * it — the modal body is one Placeholder inside the action's own schema, so
     * this is its rendered content (the same thing Action::getModalContent()
     * returned for the two delete modals).
     */
    private function previewedImpactHtml($component): string
    {
        $placeholder = $this->mountedActionSchema($component)->getComponent('axes_restructure_impact');

        $this->assertNotNull($placeholder, 'The modal must render the axes-restructure impact.');

        return (string) $placeholder->getContent();
    }

    /** The mounted action's own schema — where its axes inputs, impact and confirmation fields live. */
    private function mountedActionSchema($component): \Filament\Schemas\Schema
    {
        $schema = $component->instance()->getSchema($component->instance()->getMountedActionSchemaName());

        $this->assertNotNull($schema, 'The action must be mounted with a schema.');

        return $schema;
    }

    public function test_the_action_is_absent_without_product_manage(): void
    {
        [$productModel] = $this->persistedVariableProduct();

        $this->staffWithViewOnlyRole();

        // The real, reachable proof for a role that lacks PRODUCT_MANAGE: this
        // page's own documented boundary turns it away before the Axes tab — or
        // anything else — is rendered at all, so there is no action to hide.
        $this->get(ProductResource::getUrl('edit-variable', ['record' => $productModel]))
            ->assertForbidden();

        // A Manager holds PRODUCT_MANAGE (but not PRODUCT_DELETE): the action is
        // there, and the modal is where that difference is stated (§3.19.9).
        $this->staffWithRole('Manager');

        $this->axesTabComponent($productModel)
            ->assertSee(__('products.axes_restructure.button_label'));
    }

    /**
     * §3.19.8 C's own first two steps, rendered in one modal: the axes inputs,
     * then the impact for whatever they currently hold — seeded from the
     * product's REAL axes on mount (an untouched modal is R1's no-op), and
     * recomputed as soon as the merchant edits them.
     */
    public function test_the_modal_previews_the_impact_of_the_axes_entered(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        // One blocker with history, one without: the two lists, both non-empty.
        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);

        // Mounting seeds the modal from the product's own axes: the identical
        // set, which the domain allows as a no-op — and the impact says so.
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_unchanged_axes')),
            $this->previewedImpactHtml($component),
        );

        // The merchant removes the Size axis; the preview follows immediately.
        $component->setActionData(['axes' => [
            [
                'attribute_definition_id' => $color,
                'value_ids' => [$black, $white],
            ],
        ]]);

        $content = $this->previewedImpactHtml($component);

        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_intro', ['current' => 'Color: Black, White; Size: S'])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_new', ['new' => 'Color: Black, White'])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_will_delete', ['count' => 1])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_will_archive', ['count' => 1])),
            $content,
        );
        $this->assertStringContainsString('SKU-AX-1', $content);
        $this->assertStringContainsString('SKU-AX-5', $content);
        $this->assertStringContainsString(e((string) __('products.axes_restructure.impact_delete_note')), $content);
        $this->assertStringContainsString(e((string) __('products.axes_restructure.impact_archive_note')), $content);
        $this->assertStringContainsString(e((string) __('products.axes_restructure.impact_generated')), $content);
        $this->assertStringContainsString(e((string) __('products.axes_restructure.impact_unsaved_note')), $content);
        $this->assertStringNotContainsString(
            e((string) __('products.axes_restructure.impact_no_delete_permission')),
            $content,
            'An Administrator holds PRODUCT_DELETE, so nothing is archived for lack of permission.',
        );

        // §3.19.8 C's confirmation fields are part of the same modal and are
        // SHOWN, because a list is non-empty — the submit button itself is the
        // impact's own answer.
        $mounted = $component->instance()->getMountedAction();

        $this->assertNotNull($mounted->getModalSubmitAction());

        $schema = $this->mountedActionSchema($component);

        $this->assertTrue($schema->getComponent('understand_permanent')->isVisible());
        $this->assertTrue($schema->getComponent('base_sku_confirmation')->isVisible());
    }

    /**
     * §3.19.9's own consequence, stated where it applies: a Manager may change
     * the axes but may not delete variations, so nothing is deleted and the modal
     * says which list is which.
     */
    public function test_without_product_delete_the_impact_says_every_blocker_is_archived(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $this->staffWithRole('Manager');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);
        $component->setActionData(['axes' => [$this->axesRow($color, $black, $white)]]);

        $content = $this->previewedImpactHtml($component);

        $this->assertStringNotContainsString(
            e((string) __('products.axes_restructure.impact_will_delete', ['count' => 1])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_will_archive', ['count' => 2])),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_no_delete_permission')),
            $content,
        );
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_archive_note')),
            $content,
        );
    }

    /**
     * A set the domain will NEVER declare — the same attribute twice — is refused
     * in the merchant's own locale, with no submit button at all, and nothing is
     * written. The domain's own detail sentence is passed through inside that
     * sentence by design (§3.19.8 D's posture: a refusal is shown, not
     * paraphrased into something vague).
     */
    public function test_an_undeclarable_axis_set_is_refused_in_the_merchants_locale_and_has_no_submit_button(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeVariations = $this->variationStatusBySku($productId);

        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.locale', 'bg');
        app()->setLocale('bg');

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);
        $component->setActionData(['axes' => [
            $this->axesRow($color, $black, $white),
            $this->axesRow($color, $black),
        ]]);

        $mounted = $component->instance()->getMountedAction();

        $this->assertNull($mounted->getModalSubmitAction(), 'A set the domain will not declare has no submit button.');

        $content = $this->previewedImpactHtml($component);

        $this->assertStringContainsString('Тези оси не могат да бъдат декларирани', $content);
        $this->assertStringNotContainsString('Those axes cannot be declared', $content);
        $this->assertStringContainsString('more than once', $content, "the domain's own detail is passed through");

        // Submitting it anyway (a crafted call) still refuses, in the same locale.
        $component->callMountedAction()
            ->assertNotified(\Filament\Notifications\Notification::make()
                ->title(__('products.axes_restructure.refusal.invalid_axes', [
                    'product' => 'Variable Shirt',
                    'detail' => 'Attribute definition "'.$color.'" was declared as a variation axis more than once.',
                ]))
                ->danger());

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeVariations, $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->where('field', 'variation_axes')->count());
    }

    /**
     * §3.19.8 C's confirmation is re-checked SERVER-SIDE, not merely rendered: a
     * wrong typed base SKU leaves the product exactly as it was.
     */
    public function test_a_wrong_typed_base_sku_is_rejected_server_side_and_nothing_is_applied(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeVariations = $this->variationStatusBySku($productId);

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);
        $component->setActionData([
            'axes' => [$this->axesRow($color, $black, $white)],
            'understand_permanent' => true,
            'base_sku_confirmation' => 'SKU-NOT-THIS-ONE',
        ]);
        $component->callMountedAction();

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeVariations, $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->where('field', 'variation_axes')->count());
    }

    /**
     * The other half of the same confirmation: the checkbox is what stands for
     * "I understand this cannot be undone", so an unticked one is refused just as
     * firmly — even with the base SKU typed correctly.
     */
    public function test_an_unticked_confirmation_is_rejected_server_side_and_nothing_is_applied(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeVariations = $this->variationStatusBySku($productId);

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);
        $component->setActionData([
            'axes' => [$this->axesRow($color, $black, $white)],
            'understand_permanent' => false,
            'base_sku_confirmation' => 'SKU-AX',
        ]);
        $component->callMountedAction();

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeVariations, $this->variationStatusBySku($productId));
    }

    /**
     * The whole flow, end to end through the real action: the deletion, the
     * archiving, the new axes, the generated DRAFT combinations, the notification
     * carrying the counts ACTUALLY performed, and §3.19.8 C's own page reload.
     *
     * The log record is asserted here too (one 'variation_axes' entry with the
     * real before/after summaries, one entry per archived variation, and the
     * deletion's own snapshot, which CatalogDeletion writes).
     */
    public function test_a_successful_change_from_the_modal_applies_notifies_reloads_and_logs(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);
        $component->setActionData([
            'axes' => [$this->axesRow($color, $black, $white)],
            'understand_permanent' => true,
            'base_sku_confirmation' => 'SKU-AX',
        ]);

        $component->callMountedAction()
            ->assertNotified(Notification::make()
                ->title(__('products.axes_restructure.notification_success_title', ['product' => 'Variable Shirt']))
                ->body(__('products.axes_restructure.notification_success_body', [
                    'deleted' => 1,
                    'archived' => 1,
                    'created' => 2,
                    'restored' => 0,
                ]))
                ->success())
            ->assertRedirect(ProductResource::getUrl('edit-variable', ['record' => $productModel]));

        // Exactly what the notification said: one deleted (its row and its SKU
        // genuinely gone), one archived, and both new combinations as DRAFT
        // variations — the deleted variation's own position reclaimed.
        $this->assertSame(0, VariationModel::withTrashed()->where('sku', 'SKU-AX-1')->count());
        $this->assertSame([
            'SKU-AX-5' => 'archived',
            'SKU-AX-2' => 'draft',
            'SKU-AX-3' => 'draft',
        ], $this->variationStatusBySku($productId));
        $this->assertSame([
            $color => [$black, $white],
        ], $this->persistedAxesOf($productId));

        $axesEntries = DB::table('activity_log')
            ->where('entity_type', 'product')
            ->where('entity_id', $productId)
            ->where('field', 'variation_axes')
            ->get();

        $this->assertCount(1, $axesEntries);
        $this->assertSame('Color: Black, White; Size: S', $axesEntries->first()->old_value);
        $this->assertSame('Color: Black, White', $axesEntries->first()->new_value);

        $statusEntries = DB::table('activity_log')->where('field', 'like', 'variation[%].status')->get();

        $this->assertCount(1, $statusEntries);
        $this->assertSame('draft', $statusEntries->first()->old_value);
        $this->assertSame('archived', $statusEntries->first()->new_value);

        $this->assertSame(
            1,
            DB::table('activity_log')
                ->where('action', ActivityLogger::ACTION_DELETED)
                ->where('entity_type', 'variation')
                ->count(),
        );
    }

    /**
     * D4's other half: when BOTH lists are empty there is nothing irreversible to
     * confirm, so the modal is the impact plus the submit button — no checkbox, no
     * typed SKU. Confirming still writes nothing, because the set really is the
     * one already declared (R1).
     */
    public function test_an_unchanged_axis_set_is_a_simple_confirmation_that_writes_nothing(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeVariations = $this->variationStatusBySku($productId);

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);

        // The modal opened on the product's own axes, so both lists are empty:
        // the confirmation fields are hidden and the impact says it is a no-op.
        // Looked up with withHidden: true, because a hidden component is exactly
        // what is being asserted (the default lookup skips them).
        $schema = $this->mountedActionSchema($component);

        $this->assertFalse($schema->getComponent('understand_permanent', withHidden: true)->isVisible());
        $this->assertFalse($schema->getComponent('base_sku_confirmation', withHidden: true)->isVisible());
        $this->assertStringContainsString(
            e((string) __('products.axes_restructure.impact_unchanged_axes')),
            $this->previewedImpactHtml($component),
        );

        // The plain submit button IS the confirmation — and writes nothing.
        $component->callMountedAction()
            ->assertNotified(__('products.axes_restructure.notification_unchanged'));

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeVariations, $this->variationStatusBySku($productId));
        $this->assertSame(0, DB::table('activity_log')->count());
    }

    /**
     * D7, from three sides: the restructure's own helpers are PRIVATE (a public
     * one would be callable straight from a browser), the action's own entry point
     * takes no ids at all (the product comes from $this->record), and a crafted
     * Livewire call to one of those names is rejected with nothing changed.
     */
    public function test_the_restructure_routines_are_private_and_a_crafted_livewire_call_cannot_reach_them(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $this->staffWithRole('Administrator');

        foreach (['changeAxesAction', 'changeAxesSchema', 'changeAxesModalRows', 'restructureAxes', 'axesRestructureImpactFor', 'axesRows'] as $helper) {
            $method = new \ReflectionMethod(EditVariableProduct::class, $helper);

            $this->assertTrue(
                $method->isPrivate(),
                "{$helper}() must stay private — a public method would be callable from a browser.",
            );
        }

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeVariations = $this->variationStatusBySku($productId);

        $component = $this->axesTabComponent($productModel);

        $thrown = null;

        try {
            // The call a browser would attempt if it guessed the name: it must
            // not exist as a Livewire endpoint at all.
            $component->call('restructureAxes', ['axes' => []], null);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A call to a method Livewire cannot reach must be rejected.');

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame($beforeVariations, $this->variationStatusBySku($productId));
    }

    /**
     * The other half of §3.19.8 C's posture: the normal Save keeps REFUSING an
     * unsafe re-declaration (that is the guard's whole point), and now points the
     * merchant at the action that can carry it out instead of leaving them with a
     * rule and no way forward. A safe change still goes through Save untouched —
     * EditVariableProductAxesAndRestoreTest's own "enabling one more value"
     * test is that half.
     */
    public function test_the_normal_save_still_refuses_an_unsafe_change_and_points_at_the_action(): void
    {
        [$productModel, $product] = $this->persistedVariableProduct();
        $productId = (string) $product->id();

        $color = $this->definitionId('color');
        $black = $this->valueId('Black');
        $white = $this->valueId('White');

        $beforeAxes = $this->persistedAxesOf($productId);

        $this->staffWithRole('Administrator');

        // The Axes tab saved without the Size axis: a change that would strand
        // both live variations.
        $component = $this->axesTabComponent($productModel);
        $component->set('data.axes', [$this->axesRow($color, $black, $white)]);
        $component->call('save');

        $component->assertNotified(Notification::make()
            ->title(UnsafeAxisRedeclarationException::becauseLiveVariationsWouldLoseAnAxis(
                $productId,
                $this->definitionId('size'),
                [
                    $this->variationIdBySku($productId, 'SKU-AX-1'),
                    $this->variationIdBySku($productId, 'SKU-AX-5'),
                ],
            )->getMessage())
            ->body(__('products.axes_restructure.use_action_hint'))
            ->danger());

        // Refused means refused: the axes are exactly as they were.
        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
    }

    /**
     * THE FINGERPRINT RULE AT THE SURFACE (§3.19.8 C): the dialog carries the
     * fingerprint of the plan it is showing, so a variation that appears between
     * the dialog and the submit makes `apply()` refuse — reported in the
     * merchant's own locale, with nothing applied at all: not the deletion, not
     * the archiving, not the axes, and the variation that appeared is untouched.
     */
    public function test_a_change_made_after_the_dialog_was_opened_is_refused_and_nothing_is_applied(): void
    {
        [$color, $black, $white] = $this->persistedColorDefinition();
        [$size, $small] = $this->persistedSizeDefinition();

        // Size carries TWO values, so one combination is deliberately left
        // uncreated: the variation that appears later needs a combination that
        // does not exist yet.
        $medium = new AttributeValue(id: null, attributeDefinitionId: $size->id(), value: 'M');
        app(AttributeValueRepository::class)->save($medium);

        $product = Product::createVariable('Variable Shirt', 'SKU-AX', 'variable-shirt');
        $product->declareVariationAxes([
            new VariationAxis($color, [$black, $white]),
            new VariationAxis($size, [$small, $medium]),
        ]);
        $product->addStandardVariation([$color->id() => $black->id(), $size->id() => $small->id()], 'SKU-AX-1');
        $product->addStandardVariation([$color->id() => $white->id(), $size->id() => $small->id()], 'SKU-AX-5');
        app(ProductRepository::class)->save($product);

        $productModel = ProductModel::find($product->id());
        $productId = (string) $product->id();

        // One deletable blocker, one archivable: the plan the dialog will show.
        $this->addSaleLine($this->variationIdBySku($productId, 'SKU-AX-5'));

        $colorId = (string) $color->id();
        $blackId = (string) $black->id();
        $whiteId = (string) $white->id();

        $this->staffWithRole('Administrator');

        $component = $this->axesTabComponent($productModel);
        $this->mountChangeAxes($component);
        $component->setActionData([
            'axes' => [$this->axesRow($colorId, $blackId, $whiteId)],
            'understand_permanent' => true,
            'base_sku_confirmation' => 'SKU-AX',
        ]);

        // The dialog is showing a plan, and it carries it: this property is what
        // the render of the impact recorded — the submit path must not recompute
        // it, or there would be nothing to compare.
        $this->assertNotNull(
            $component->get('axesPlanFingerprint'),
            'The rendered impact must record the plan it is showing.',
        );

        $beforeAxes = $this->persistedAxesOf($productId);
        $beforeVariations = $this->variationStatusBySku($productId);

        // Between the dialog and the submit: another live variation, one the
        // confirmed plan never saw.
        $fresh = app(ProductRepository::class)->findByIdWithVariations($productId);
        $fresh->addStandardVariation([$color->id() => $white->id(), $size->id() => $medium->id()], 'SKU-AX-9');
        app(ProductRepository::class)->save($fresh);

        $component->callMountedAction()
            ->assertNotified(Notification::make()
                ->title('"Variable Shirt" changed since you opened this dialog — reopen it to see the current impact. Nothing was changed.')
                ->danger());

        $this->assertSame($beforeAxes, $this->persistedAxesOf($productId));
        $this->assertSame(
            $beforeVariations + ['SKU-AX-9' => 'draft'],
            $this->variationStatusBySku($productId),
        );
    }
}
