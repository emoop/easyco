<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * BUG-1 — "Allowed memory size of 536870912 bytes exhausted" on the Axes tab of an EXISTING variable product that
 * already has live variations. The cause was an endless recursion in EditVariableProduct::changeAxesModalRows()
 * (see its comment): any action mounted ON TOP of the «Change axes» modal — the modal's own Repeater row actions
 * (add / delete a row) — made the page resolve its mounted actions, which evaluated the modal's visibility closures,
 * which resolved the mounted actions again.
 *
 * A regression of the fix cannot end as an ordinary failed assertion: the recursion exhausts PHP's memory and kills the
 * test process with the owner's exact message («Allowed memory size of 536870912 bytes exhausted»). That is the signal
 * (PHP's call-stack limit is not settable at runtime on this build, so it cannot be turned into a catchable Error).
 *
 * The file also pins the owner's goal — a variable product with 3 sizes x 2 colours — through both ways that exist
 * today (the creation wizard, and the «Change axes» flow on a product that already has live variations).
 */
class EditVariableProductAxesNeverFatalTest extends TestCase
{
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveProductsAShippingClass();
    }

    private function adminLogin(): void
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create('admin@example.com', app(PasswordHasher::class)->hash('password123'), 'Administrator', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
    }

    /** @return array{0: AttributeDefinition, 1: list<AttributeValue>} */
    private function definition(string $code, string $name, array $values): array
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: $name, type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        $saved = [];

        foreach ($values as $value) {
            $attributeValue = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $value);
            app(AttributeValueRepository::class)->save($attributeValue);
            $saved[] = $attributeValue;
        }

        return [$definition, $saved];
    }

    /**
     * Size[S, M] with two LIVE variations (the dev product 20025's shape), a third Size value L and a second attribute Colour[Red, Blue] left unused.
     *
     * @return array{id: int, size: AttributeDefinition, sizes: list<AttributeValue>, colour: AttributeDefinition, colours: list<AttributeValue>}
     */
    private function productWithLiveSizes(): array
    {
        [$size, $sizes] = $this->definition('size', 'Size', ['S', 'M', 'L']);
        [$colour, $colours] = $this->definition('colour', 'Colour', ['Red', 'Blue']);

        $product = Product::createVariable('Dress', 'SKU-D', 'dress');
        $product->declareVariationAxes([new VariationAxis($size, [$sizes[0], $sizes[1]])]);

        foreach ([[$sizes[0], 'S'], [$sizes[1], 'M']] as [$value, $suffix]) {
            $product->addStandardVariation([$size->id() => $value->id()], 'SKU-D-'.$suffix)->activate();
        }

        app(ProductRepository::class)->save($product);

        return ['id' => (int) $product->id(), 'size' => $size, 'sizes' => $sizes, 'colour' => $colour, 'colours' => $colours];
    }

    /** @param list<AttributeValue> $values */
    private function axisRow(AttributeDefinition $definition, array $values): array
    {
        return [
            'attribute_definition_id' => (string) $definition->id(),
            'value_ids' => array_map(fn (AttributeValue $value): string => (string) $value->id(), $values),
        ];
    }

    private function modalOpenOnAxesTab(int $productId)
    {
        $component = Livewire::withQueryParams(['tab' => 'axes'])->test(EditVariableProduct::class, ['record' => $productId]);
        $component->mountAction(TestAction::make('change_axes')->schemaComponent('axes_change', 'form'));

        return $component;
    }

    /** @return array<string, list<string>> the product's persisted axes, definition id => value ids */
    private function persistedAxes(int $productId): array
    {
        $axes = [];

        foreach (DB::table('catalog_product_axis_values')->where('product_id', $productId)->orderBy('attribute_value_id')->get() as $row) {
            $axes[(string) $row->attribute_definition_id][] = (string) $row->attribute_value_id;
        }

        return $axes;
    }

    public function test_deleting_a_row_inside_the_change_axes_modal_does_not_recurse(): void
    {
        $this->adminLogin();
        $world = $this->productWithLiveSizes();
        $before = $this->persistedAxes($world['id']);

        $component = $this->modalOpenOnAxesTab($world['id']);
        $rowKey = array_key_first($component->get('mountedActions.0.data.axes'));

        // The merchant's own click on the trash can of the modal's Repeater row — the action mounts ON TOP of the modal.
        $component->callAction(TestAction::make('delete')->schemaComponent('axes', 'mountedActionSchema0')->arguments(['item' => $rowKey]));

        $this->assertSame([], array_values($component->get('mountedActions.0.data.axes') ?? []), 'the row is gone from the modal');
        $this->assertSame($before, $this->persistedAxes($world['id']), 'nothing was written: the modal only edits its own copy');
    }

    public function test_adding_a_row_inside_the_change_axes_modal_keeps_the_modal_state(): void
    {
        $this->adminLogin();
        $world = $this->productWithLiveSizes();

        $component = $this->modalOpenOnAxesTab($world['id']);
        $component->callAction(TestAction::make('add')->schemaComponent('axes', 'mountedActionSchema0'));

        // the seeded Size row is still there, plus the new empty one
        $this->assertCount(2, $component->get('mountedActions.0.data.axes'));
    }

    public function test_the_modal_rows_typed_before_a_row_action_are_still_the_modals_rows_afterwards(): void
    {
        $this->adminLogin();
        $world = $this->productWithLiveSizes();

        $component = $this->modalOpenOnAxesTab($world['id']);
        $component->setActionData(['axes' => [$this->axisRow($world['size'], $world['sizes'])]]);
        $component->callAction(TestAction::make('add')->schemaComponent('axes', 'mountedActionSchema0'));

        $rows = array_values($component->get('mountedActions.0.data.axes'));
        $this->assertSame(
            array_map(fn (AttributeValue $value): string => (string) $value->id(), $world['sizes']),
            array_map('strval', $rows[0]['value_ids']),
        );
    }

    public function test_adding_a_colour_axis_to_a_product_with_live_variations_is_refused_with_a_message_and_writes_nothing(): void
    {
        $this->adminLogin();
        $world = $this->productWithLiveSizes();
        $before = $this->persistedAxes($world['id']);
        $variationsBefore = DB::table('catalog_variations')->where('product_id', $world['id'])->count();

        Livewire::test(EditVariableProduct::class, ['record' => $world['id']])
            ->set('data.axes', [
                $this->axisRow($world['size'], [$world['sizes'][0], $world['sizes'][1]]),
                $this->axisRow($world['colour'], $world['colours']),
            ])
            ->call('save')
            ->assertNotified();

        $this->assertSame($before, $this->persistedAxes($world['id']), 'the refused axis was not declared');
        $this->assertSame($variationsBefore, DB::table('catalog_variations')->where('product_id', $world['id'])->count());
    }

    public function test_adding_a_third_size_value_with_live_variations_still_works(): void
    {
        $this->adminLogin();
        $world = $this->productWithLiveSizes();

        Livewire::test(EditVariableProduct::class, ['record' => $world['id']])
            ->set('data.axes', [$this->axisRow($world['size'], $world['sizes'])])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertCount(3, $this->persistedAxes($world['id'])[(string) $world['size']->id()]);
    }

    public function test_the_owners_goal_through_the_change_axes_flow_three_sizes_by_two_colours_is_six_live_variations(): void
    {
        $this->adminLogin();
        $world = $this->productWithLiveSizes();

        $component = $this->modalOpenOnAxesTab($world['id']);
        $component->setActionData([
            'axes' => [
                $this->axisRow($world['size'], $world['sizes']),
                $this->axisRow($world['colour'], $world['colours']),
            ],
            'understand_permanent' => true,
            'base_sku_confirmation' => 'SKU-D',
        ]);
        $component->callMountedAction()->assertNotified();

        $this->assertCount(2, $this->persistedAxes($world['id']));
        $this->assertSame(
            6,
            DB::table('catalog_variations')->where('product_id', $world['id'])->whereNull('deleted_at')->where('status', '!=', 'archived')->count(),
            'three sizes x two colours = six live variations',
        );
    }

    public function test_the_owners_goal_through_the_creation_wizard_three_sizes_by_two_colours_generates_six_variations(): void
    {
        $this->adminLogin();
        [$size, $sizes] = $this->definition('size', 'Size', ['S', 'M', 'L']);
        [$colour, $colours] = $this->definition('colour', 'Colour', ['Red', 'Blue']);

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm([
                'name' => 'Dress',
                'slug' => 'dress',
                'base_sku' => 'DRESS',
                'axes' => [
                    $this->axisRow($size, $sizes),
                    $this->axisRow($colour, $colours),
                ],
            ])
            ->goToNextWizardStep()
            ->goToNextWizardStep();

        $this->assertCount(6, $component->get('data.variations'));

        $component->call('create')->assertHasNoFormErrors();

        $product = ProductModel::where('slug', 'dress')->firstOrFail();
        $this->assertSame(6, DB::table('catalog_variations')->where('product_id', $product->id)->count());
    }
}
