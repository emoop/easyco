<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5b (shipping-domain-design.md §12.3.4): the ONE optional "Shipping class" select on the SIMPLE product form
 * (Price & Stock tab) and in each existing-variation row of a VARIABLE product. It lists every class by name, empty =
 * no class, and is saved through ShippingClassAssigner after the product's own save — an untouched select writes
 * nothing, and a legacy free-text value that names no class is never cleared by an unrelated save.
 */
class ProductShippingClassFieldTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaff('Administrator');
        $this->activityLogOn();
    }

    private function classId(string $code, ?string $name = null): string
    {
        $this->shippingClass($code, $name ?? ucfirst($code));

        return (string) app(ShippingClassRepository::class)->findByCode($code)->id();
    }

    /** @return array{0: string, 1: string} the product id and its single variation's id */
    private function simpleProduct(?string $storedClass = null): array
    {
        static $n = 0;
        $n++;

        $product = Product::createSimple("Form Product {$n}", "FP-{$n}", "form-product-{$n}");
        $product->variations()[0]->setShippingClass($storedClass);
        app(ProductRepository::class)->save($product);

        return [(string) $product->id(), (string) $product->variations()[0]->id()];
    }

    private function stored(string $variationId): ?string
    {
        return DB::table('catalog_variations')->where('id', $variationId)->value('shipping_class');
    }

    private function hookNames(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->hookCalls);
    }

    // =====================================================================================================
    // SIMPLE product
    // =====================================================================================================

    public function test_the_simple_form_has_one_required_select_of_all_classes_by_name_and_the_fact_line(): void
    {
        $heavy = $this->classId('heavy', 'Heavy parcels');
        $light = $this->classId('light', 'Light');
        [$productId] = $this->simpleProduct();

        $component = Livewire::test(EditProduct::class, ['record' => $productId])
            ->assertFormFieldExists('shipping_class')
            ->assertFormSet(['shipping_class' => null]);

        $html = html_entity_decode($component->html());
        $this->assertStringContainsString('Shipping class', $html);
        $this->assertStringContainsString('Heavy parcels (heavy)', $html);
        $this->assertStringContainsString('Light (light)', $html);
        $this->assertStringContainsString('A shipping class is required.', $html);
        $this->assertStringContainsString('Choose a class', $html);
        $this->assertStringContainsString('/admin/help/shipping#action-class-assignment', $html);
        $this->assertStringContainsString('/admin/help/shipping#action-class-required', $html);
        $this->assertNotSame($heavy, $light);

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(EditProduct::class, ['record' => $productId])->html());
        $this->assertStringContainsString('Клас за доставка', $bg);
        $this->assertStringContainsString('Класът за доставка е задължителен.', $bg);
    }

    public function test_the_stored_class_code_is_shown_as_the_class_and_saving_assigns_a_change_but_cannot_clear_it(): void
    {
        $heavy = $this->classId('heavy');
        $light = $this->classId('light');
        [$productId, $variationId] = $this->simpleProduct('heavy');
        $this->spyOnClassHooks();

        $component = Livewire::test(EditProduct::class, ['record' => $productId])
            ->assertFormSet(['shipping_class' => $heavy]);

        $component->fillForm(['shipping_class' => $light])->call('save')->assertHasNoFormErrors();
        $this->assertSame('light', $this->stored($variationId));

        // stage 5e: the class is required in the form — clearing it is refused, with a field error and no write
        $component->fillForm(['shipping_class' => null])->call('save')->assertHasFormErrors(['shipping_class' => __('shipping.classes.errors.class_required')]);
        $this->assertSame('light', $this->stored($variationId));

        $this->assertSame(['shipping.class.assigned'], $this->hookNames());
        $this->assertSame(['product', $productId, 'heavy', 'light'], $this->hookCalls[0][1]);

        $fields = DB::table('activity_log')->where('entity_id', $productId)->where('field', 'shipping_class')->orderBy('id')->get();
        $this->assertCount(1, $fields, 'one audit entry per change');
    }

    public function test_a_legacy_free_text_value_shows_as_empty_and_the_save_is_refused_until_a_class_is_chosen(): void
    {
        $heavy = $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct('Fragile things'); // free text from before the classes were real
        $this->spyOnClassHooks();

        $component = Livewire::test(EditProduct::class, ['record' => $productId])
            ->assertFormSet(['shipping_class' => null])
            ->fillForm(['name' => 'Renamed product'])
            ->call('save')
            ->assertHasFormErrors(['shipping_class' => __('shipping.classes.errors.class_required')]);

        $this->assertSame('Fragile things', $this->stored($variationId), 'a refused save writes nothing, the legacy text is untouched');
        $this->assertSame([], $this->hookCalls);

        $component->fillForm(['shipping_class' => $heavy])->call('save')->assertHasNoFormErrors();
        $this->assertSame('heavy', $this->stored($variationId));
        $this->assertSame(['product', $productId, 'Fragile things', 'heavy'], $this->hookCalls[0][1], 'the old text is the audit and hook old value');
    }

    public function test_a_class_chosen_on_create_is_assigned_after_the_product_exists(): void
    {
        $heavy = $this->classId('heavy');
        $this->spyOnClassHooks();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Brand new', 'slug' => 'brand-new', 'base_sku' => 'SKU-BRAND-NEW',
                'status' => ProductStatus::DRAFT->value, 'shipping_class' => $heavy,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variationId = (string) DB::table('catalog_variations')->value('id');
        $this->assertSame('heavy', $this->stored($variationId));
        $this->assertSame(['shipping.class.assigned'], $this->hookNames());
        $this->assertSame('product', $this->hookCalls[0][1][0]);
    }

    public function test_create_without_a_class_is_refused_with_a_field_error_and_creates_nothing(): void
    {
        $this->classId('heavy');
        $this->spyOnClassHooks();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Plain', 'slug' => 'plain', 'base_sku' => 'SKU-PLAIN', 'status' => ProductStatus::DRAFT->value, 'shipping_class' => null])
            ->call('create')
            ->assertHasFormErrors(['shipping_class' => __('shipping.classes.errors.class_required')]);

        $this->assertSame(0, DB::table('catalog_products')->count());
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_class_id_that_is_not_in_the_options_is_refused_by_the_form_and_nothing_is_saved(): void
    {
        $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct();

        Livewire::test(EditProduct::class, ['record' => $productId])
            ->fillForm(['shipping_class' => '999999'])
            ->call('save')
            ->assertHasFormErrors(['shipping_class']);

        $this->assertNull($this->stored($variationId));
    }

    public function test_a_class_deleted_between_the_form_and_the_save_is_told_to_the_merchant_and_the_product_is_saved(): void
    {
        $heavy = $this->classId('heavy');
        [$productId, $variationId] = $this->simpleProduct();

        $component = Livewire::test(EditProduct::class, ['record' => $productId])
            ->fillForm(['shipping_class' => $heavy, 'name' => 'Saved anyway']);

        DB::table('shipping_classes')->delete();

        // the form validates the option against the current list, so the stale id is refused there; and were it to
        // reach the service, the service's own refusal is a notice, never a 500 (see the assigner tests)
        $component->call('save');

        $this->assertNull($this->stored($variationId));
    }

    // =====================================================================================================
    // VARIABLE product
    // =====================================================================================================

    /** @return array{0: string, 1: string, 2: string} the product id and the Black and White variation ids */
    private function variableProduct(?string $blackClass = null): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $white = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'White');
        app(AttributeValueRepository::class)->save($white);

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black, $white])]);
        $blackVariation = $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        $whiteVariation = $product->addStandardVariation([$definition->id() => $white->id()], 'SKU-VAR-WHITE');
        $blackVariation->setShippingClass($blackClass);
        app(ProductRepository::class)->save($product);

        return [(string) $product->id(), (string) $blackVariation->id(), (string) $whiteVariation->id()];
    }

    /** @return array<string, string> variation id => the row key of the repeater */
    private function rowKeys($component): array
    {
        $keys = [];

        foreach ($component->get('data.existing_variations') as $key => $row) {
            $keys[$row['variation_id']] = $key;
        }

        return $keys;
    }

    public function test_each_existing_variation_row_has_the_select_seeded_from_its_own_stored_code(): void
    {
        $heavy = $this->classId('heavy');
        [$productId, $black, $white] = $this->variableProduct('heavy');

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId])->assertSuccessful();
        $keys = $this->rowKeys($component);

        $this->assertSame($heavy, $component->get("data.existing_variations.{$keys[$black]}.shipping_class"));
        $this->assertNull($component->get("data.existing_variations.{$keys[$white]}.shipping_class"));
    }

    public function test_saving_a_variation_row_assigns_only_the_rows_that_changed_one_audit_entry_and_one_hook_each(): void
    {
        $heavy = $this->classId('heavy');
        $light = $this->classId('light');
        [$productId, $black, $white] = $this->variableProduct('heavy');
        $this->spyOnClassHooks();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $keys = $this->rowKeys($component);

        $component->set("data.existing_variations.{$keys[$black]}.shipping_class", $light)
            ->set("data.existing_variations.{$keys[$white]}.shipping_class", $heavy)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('light', $this->stored($black));
        $this->assertSame('heavy', $this->stored($white));
        $this->assertSame(['shipping.class.assigned', 'shipping.class.assigned'], $this->hookNames());
        $this->assertSame(['variation', $black, 'heavy', 'light'], $this->hookCalls[0][1]);
        $this->assertSame(['variation', $white, null, 'heavy'], $this->hookCalls[1][1]);

        foreach ([$black, $white] as $variationId) {
            $this->assertSame(1, DB::table('activity_log')->where('entity_id', $productId)->where('field', "variation[{$variationId}].shipping_class")->count());
        }

        // a second save with nothing changed assigns nothing
        $this->hookCalls = [];
        $component->call('save')->assertHasNoFormErrors();
        $this->assertSame([], $this->hookCalls);
    }

    public function test_a_variation_row_cleared_to_no_class_is_refused_and_nothing_is_saved(): void
    {
        $this->classId('heavy');
        [$productId, $black, $white] = $this->variableProduct('heavy');
        $heavy = (string) app(ShippingClassRepository::class)->findByCode('heavy')->id();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $keys = $this->rowKeys($component);

        // the white row has no class yet (a legacy blank): the form refuses until BOTH rows have one
        $component->set("data.existing_variations.{$keys[$black]}.shipping_class", null)->call('save')
            ->assertHasFormErrors([
                "existing_variations.{$keys[$black]}.shipping_class" => __('shipping.classes.errors.class_required'),
                "existing_variations.{$keys[$white]}.shipping_class" => __('shipping.classes.errors.class_required'),
            ]);

        $this->assertSame('heavy', $this->stored($black));
        $this->assertNull($this->stored($white));

        $component->set("data.existing_variations.{$keys[$black]}.shipping_class", $heavy)
            ->set("data.existing_variations.{$keys[$white]}.shipping_class", $heavy)
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('heavy', $this->stored($white));
    }

    public function test_the_class_list_is_read_once_per_form_however_many_variation_rows_there_are(): void
    {
        $this->classId('heavy');
        [$productId] = $this->variableProduct();
        $this->app->forgetScopedInstances();

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'from `shipping_classes`')) {
                $reads++;
            }
        });

        Livewire::test(EditVariableProduct::class, ['record' => $productId])->assertSuccessful();

        fwrite(STDERR, sprintf("\n[query-count] variable product edit page: %d shipping_classes read(s) for 2 variation rows\n", $reads));
        $this->assertSame(2, $reads, 'one to seed the rows, one for the options of the form — never per row');
    }
}
