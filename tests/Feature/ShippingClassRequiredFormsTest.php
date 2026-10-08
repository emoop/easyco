<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\CreateVariableProduct;
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
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5e: the shipping class is REQUIRED in the admin forms — the SIMPLE product form (create and edit) and every
 * variation row of a VARIABLE product (existing rows, new rows, the create wizard, "generate missing") — and ONLY
 * there: the domain and the quote still accept a variation with no class. The store's default class is pre-selected
 * for anything new; a legacy blank or free-text value shows as empty and must be chosen; a store with no class at all
 * gets a fact line and a link, never a 500.
 */
class ShippingClassRequiredFormsTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PricingSystemListsSeeder::class)->run(app(PriceListRepository::class));
        $this->actingAsStaff('Administrator');
    }

    private function classId(string $code, ?string $name = null, bool $default = false): string
    {
        $this->shippingClass($code, $name ?? ucfirst($code));
        $id = (string) app(ShippingClassRepository::class)->findByCode($code)->id();

        if ($default) {
            app(ShippingClassRepository::class)->markDefault($id);
        }

        return $id;
    }

    private function stored(string $variationId): ?string
    {
        return DB::table('catalog_variations')->where('id', $variationId)->value('shipping_class');
    }

    /** @return array{0: string, 1: string} product id, its variation id — built straight through the repository: the domain accepts no class */
    private function simpleProduct(?string $class = null): array
    {
        static $n = 0;
        $n++;
        $product = Product::createSimple("Required Product {$n}", "RP-{$n}", "required-product-{$n}");
        $product->variations()[0]->setShippingClass($class);
        app(ProductRepository::class)->save($product);

        return [(string) $product->id(), (string) $product->variations()[0]->id()];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} product id, Black id, White id, and the (unused) Red value id */
    private function variableProduct(): array
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $values = [];

        foreach (['Black', 'White', 'Red'] as $name) {
            $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: $name);
            app(AttributeValueRepository::class)->save($value);
            $values[$name] = $value;
        }

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, array_values($values))]);
        $black = $product->addStandardVariation([$definition->id() => $values['Black']->id()], 'SKU-VAR-BLACK');
        $white = $product->addStandardVariation([$definition->id() => $values['White']->id()], 'SKU-VAR-WHITE');
        $black->setShippingClass('standard');
        $white->setShippingClass('standard');
        app(ProductRepository::class)->save($product);

        return [(string) $product->id(), (string) $black->id(), (string) $white->id(), (string) $values['Red']->id()];
    }

    private function axisId(): string
    {
        return (string) DB::table('catalog_attribute_definitions')->value('id');
    }

    // ---- SIMPLE product ------------------------------------------------------------------------------------------

    public function test_a_new_product_form_pre_selects_the_default_class_and_creates_with_it(): void
    {
        $this->classId('light', 'Light');
        $standard = $this->classId('standard', 'Standard', default: true);
        $this->spyOnClassHooks();

        Livewire::test(CreateProduct::class)
            ->assertFormSet(['shipping_class' => $standard])
            ->fillForm(['name' => 'Fresh', 'slug' => 'fresh', 'base_sku' => 'SKU-FRESH', 'status' => ProductStatus::DRAFT->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('standard', $this->stored((string) DB::table('catalog_variations')->value('id')));
    }

    public function test_without_a_default_class_the_new_product_form_starts_empty_and_a_save_is_refused(): void
    {
        $this->classId('light', 'Light');

        Livewire::test(CreateProduct::class)
            ->assertFormSet(['shipping_class' => null])
            ->fillForm(['name' => 'Fresh', 'slug' => 'fresh', 'base_sku' => 'SKU-FRESH', 'status' => ProductStatus::DRAFT->value])
            ->call('create')
            ->assertHasFormErrors(['shipping_class' => __('shipping.classes.errors.class_required')]);

        $this->assertSame(0, DB::table('catalog_products')->count());
    }

    public function test_a_store_with_no_class_shows_the_fact_line_and_the_link_and_refuses_the_save_without_a_500(): void
    {
        $this->assertSame(0, DB::table('shipping_classes')->count());

        $component = Livewire::test(CreateProduct::class);
        $html = html_entity_decode($component->html());

        $this->assertStringContainsString('Create at least one shipping class first.', $html);
        $this->assertStringContainsString('href="'.\App\Filament\Resources\ShippingClassResource::getUrl('index').'"', $html);
        $this->assertStringContainsString('what you typed on this form is not kept', $html);

        $component
            ->fillForm(['name' => 'Fresh', 'slug' => 'fresh', 'base_sku' => 'SKU-FRESH', 'status' => ProductStatus::DRAFT->value])
            ->call('create')
            ->assertHasFormErrors(['shipping_class']);

        $this->assertSame(0, DB::table('catalog_products')->count());

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(CreateProduct::class)->html());
        $this->assertStringContainsString('Първо създайте поне един клас за доставка.', $bg);
        $this->assertStringContainsString('Към класовете за доставка', $bg);
    }

    public function test_an_existing_classless_product_shows_the_select_empty_even_with_a_default_and_is_refused_until_chosen(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        [$productId, $variationId] = $this->simpleProduct(null);

        $component = Livewire::test(EditProduct::class, ['record' => $productId])->assertFormSet(['shipping_class' => null]);

        $component->fillForm(['name' => 'Renamed'])->call('save')->assertHasFormErrors(['shipping_class']);
        $this->assertNull($this->stored($variationId));

        $component->fillForm(['shipping_class' => $standard])->call('save')->assertHasNoFormErrors();
        $this->assertSame('standard', $this->stored($variationId));
    }

    public function test_the_domain_and_the_assigner_stay_permissive_a_classless_variation_still_quotes_and_can_be_cleared_by_a_system_caller(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        [$productId, $variationId] = $this->simpleProduct(null);

        // a variation with no class is a valid domain object, saved and read back
        $this->assertNull(app(ProductRepository::class)->findByIdWithVariations($productId)->universalVariation()->shippingClass());

        // and the service has no "required" rule: a system caller may assign and then clear
        $assigner = app(\App\Services\ShippingClassAssigner::class);
        $this->assertTrue($assigner->setForProduct($productId, $standard));
        $this->assertTrue($assigner->setForProduct($productId, null));
        $this->assertNull($this->stored($variationId));
    }

    // ---- VARIABLE product -------------------------------------------------------------------------------------------

    public function test_a_new_variation_row_is_pre_filled_with_the_default_class_required_and_created_with_it(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        [$productId, , , $red] = $this->variableProduct();
        $this->spyOnClassHooks();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $axis = $this->axisId();

        $component->set('data.axes', [['attribute_definition_id' => $this->axisId(), 'value_ids' => array_map('strval', DB::table('catalog_attribute_values')->pluck('id')->all())]]);

        // a row without a class is refused ...
        $component->set('data.new_variations', [["axis_value_{$axis}" => $red, 'sku' => 'SKU-VAR-RED', 'barcode' => '', 'is_active' => false, 'shipping_class' => null]])
            ->call('save')
            ->assertHasFormErrors(['new_variations.0.shipping_class' => __('shipping.classes.errors.class_required')]);
        $this->assertSame(2, DB::table('catalog_variations')->where('type', 'standard')->count());

        // ... with the class chosen it is created carrying it, as a creation (no "assigned" hook, the product's own audit)
        $component->set('data.new_variations.0.shipping_class', $standard)->call('save')->assertHasNoFormErrors();

        $red = DB::table('catalog_variations')->where('sku', 'SKU-VAR-RED')->first();
        $this->assertSame('standard', $red->shipping_class);
        $this->assertSame([], $this->hookCalls);
    }

    public function test_the_add_variation_action_starts_the_row_with_the_default_class(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        [$productId] = $this->variableProduct();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $component->callAction(TestAction::make('add')->schemaComponent('new_variations', 'form'));

        $rows = array_values($component->get('data.new_variations') ?? []);
        $this->assertCount(1, $rows);
        $this->assertSame($standard, (string) $rows[0]['shipping_class']);
    }

    public function test_an_existing_variation_row_with_a_legacy_value_is_empty_and_the_whole_save_waits_for_it(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        [$productId, $black, $white] = $this->variableProduct();
        DB::table('catalog_variations')->where('id', $white)->update(['shipping_class' => 'free text from 2024']);

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $keys = [];
        foreach ($component->get('data.existing_variations') as $key => $row) {
            $keys[$row['variation_id']] = $key;
        }

        $this->assertSame($standard, (string) $component->get("data.existing_variations.{$keys[$black]}.shipping_class"));
        $this->assertNull($component->get("data.existing_variations.{$keys[$white]}.shipping_class"));

        $component->call('save')->assertHasFormErrors(["existing_variations.{$keys[$white]}.shipping_class"]);
        $this->assertSame('free text from 2024', $this->stored($white));

        $component->set("data.existing_variations.{$keys[$white]}.shipping_class", $standard)->call('save')->assertHasNoFormErrors();
        $this->assertSame('standard', $this->stored($white));
    }

    public function test_generate_missing_variations_gives_the_new_ones_the_default_class(): void
    {
        $this->classId('standard', 'Standard', default: true);
        [$productId, $black, $white] = $this->variableProduct();

        $component = Livewire::test(EditVariableProduct::class, ['record' => $productId]);
        $component->set('data.axes', [['attribute_definition_id' => $this->axisId(), 'value_ids' => array_map('strval', DB::table('catalog_attribute_values')->pluck('id')->all())]]);
        $component->call('save')->assertHasNoFormErrors();

        $component->mountAction(TestAction::make('generate_missing_variations')->schemaComponent('existing_variations_section', 'form'));
        $component->callMountedAction();

        $created = DB::table('catalog_variations')->where('type', 'standard')->whereNotIn('id', [$black, $white])->get();
        $this->assertCount(1, $created, 'the one missing combination (Red) was generated');

        foreach ($created as $row) {
            $this->assertSame('standard', $row->shipping_class, 'a generated variation is born with the default class');
        }
    }

    // ---- the create wizard -------------------------------------------------------------------------------------------

    public function test_the_wizard_rows_start_with_the_default_class_and_a_row_without_one_is_refused(): void
    {
        $standard = $this->classId('standard', 'Standard', default: true);
        $definition = new AttributeDefinition(id: null, code: 'size', name: 'Size', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $s = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'S');
        app(AttributeValueRepository::class)->save($s);
        $m = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'M');
        app(AttributeValueRepository::class)->save($m);

        $component = Livewire::test(CreateVariableProduct::class)
            ->fillForm(['name' => 'Wizard Shirt', 'slug' => 'wizard-shirt', 'base_sku' => 'WIZ', 'axes' => [['attribute_definition_id' => $definition->id(), 'value_ids' => [$s->id(), $m->id()]]]])
            ->goToNextWizardStep()
            ->goToNextWizardStep();

        $rows = array_values($component->get('data.variations'));
        $this->assertCount(2, $rows);
        $this->assertSame([$standard, $standard], array_map(fn (array $row): string => (string) $row['shipping_class'], $rows));

        // a row cleared by the merchant is refused
        $component->set('data.variations.0.shipping_class', null)->call('create')->assertHasFormErrors(['variations.0.shipping_class' => __('shipping.classes.errors.class_required')]);
        $this->assertSame(0, DB::table('catalog_products')->count());

        $component->set('data.variations.0.shipping_class', $standard)->call('create')->assertHasNoFormErrors();

        $this->assertSame(['standard', 'standard'], DB::table('catalog_variations')->where('type', 'standard')->pluck('shipping_class')->all());
    }

    public function test_every_new_string_renders_in_both_languages(): void
    {
        $this->classId('standard', 'Standard', default: true);
        [$productId] = $this->simpleProduct('standard');

        foreach (['en' => ['Shipping class', 'Choose a class', 'A shipping class is required.'], 'bg' => ['Клас за доставка', 'Изберете клас', 'Класът за доставка е задължителен.']] as $locale => $texts) {
            App::setLocale($locale);
            $html = html_entity_decode(Livewire::test(EditProduct::class, ['record' => $productId])->html());

            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $html, "{$locale}: {$text}");
            }

            $this->assertStringContainsString('/admin/help/shipping#action-class-required', $html);
        }
    }
}
