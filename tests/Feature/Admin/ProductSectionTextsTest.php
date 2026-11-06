<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Storefront\Sections\SectionSet;
use App\Storefront\Sections\SectionStore;
use App\Storefront\Sections\SectionTexts;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * P4b: the product form's «Информация» tab: one rich-text field per PUBLISHED section whose source takes a product text, in the sections' order, labelled with the section's title; loaded and saved with the product on the
 * simple create + edit forms and the variable edit form (the wizard is not changed); an empty text deletes the row; the tab is hidden when no section needs a text; a section that is not a product-text one has no field.
 */
class ProductSectionTextsTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    private const DELIV = '11111111-1111-4111-8111-111111111111';

    private const SIZE = '22222222-2222-4222-8222-222222222222';

    private const COMP = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('en');
        Storage::fake('public');
        $this->actingAsStaff('Administrator');
    }

    private function publishSections(): void
    {
        [$clean, $errors] = SectionSet::validate(['sections' => [
            ['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>'],
            ['key' => self::SIZE, 'title' => 'Individual size', 'source' => 'product', 'text' => ''],
            ['key' => self::COMP, 'title' => 'Composition', 'source' => 'product_or_global', 'text' => '<p>G</p>'],
        ]]);
        $this->assertSame([], $errors);
        SectionStore::publish($clean);
    }

    private function product(): ProductModel
    {
        app(ProductRepository::class)->save(Product::createSimple('Texts Product', 'SKU-TEXTS', 'texts-product'));

        return ProductModel::where('slug', 'texts-product')->firstOrFail();
    }

    private function rows(int $product): array
    {
        $rows = DB::table('product_section_texts')->where('product_id', $product)->pluck('body', 'section_key')->all();
        ksort($rows);

        return $rows;
    }

    /** the tab is there only when some section takes a product text; it holds one editor per such section, in the sections' order, labelled with the title */
    public function test_the_information_tab_follows_the_published_sections(): void
    {
        $this->assertFalse(ProductResource::sectionTextsTab()->isVisible(), 'no section: no tab');

        $this->publishSections();
        $tab = ProductResource::sectionTextsTab();
        $this->assertTrue($tab->isVisible());

        $product = $this->product();
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $html = $page->html();

        $this->assertStringContainsString('Information', $html);
        $this->assertStringContainsString('Individual size', $html);
        $this->assertStringContainsString('Composition', $html);
        $this->assertTrue(strpos($html, 'Individual size') < strpos($html, 'Composition'), 'the sections\' order');
        // «Delivery» is a general-text section: it has no field
        $this->assertSame([self::SIZE, self::COMP], SectionTexts::formKeys());

        // P4c: only global sections: the tab is there (the switch «Показвай на този продукт» of every section), with no text field
        [$clean] = SectionSet::validate(['sections' => [['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>']]]);
        SectionStore::publish($clean);
        $this->assertTrue(ProductResource::sectionTextsTab()->isVisible());
        $this->assertSame([], SectionTexts::formKeys(), 'no text field');
        $this->assertSame([self::DELIV], SectionTexts::switchKeys(), 'one switch');
    }

    /** ★ the simple edit form loads the texts and saves them; an empty text deletes the row; a text of a section that is not a product-text one is not saved */
    public function test_the_simple_edit_form_loads_saves_and_clears_the_texts(): void
    {
        $this->publishSections();
        $product = $this->product();
        $id = (int) $product->id;
        SectionTexts::save($id, [self::SIZE => '<p>EXISTING</p>'], [self::SIZE]);

        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $page->assertFormSet(['section_texts.'.self::SIZE => '<p>EXISTING</p>']);

        $page->fillForm(['section_texts' => [self::SIZE => '<p>CHANGED</p>', self::COMP => '<p>COMPOSITIONTEXT</p>', self::DELIV => '<p>SHOULDNOTBESAVED</p>']])->call('save')->assertHasNoFormErrors();

        $this->assertSame([self::SIZE => '<p>CHANGED</p>', self::COMP => '<p>COMPOSITIONTEXT</p>'], $this->rows($id));

        $page->fillForm(['section_texts' => [self::SIZE => '', self::COMP => '<p>COMPOSITIONTEXT</p>']])->call('save')->assertHasNoFormErrors();
        $this->assertSame([self::COMP => '<p>COMPOSITIONTEXT</p>'], $this->rows($id), 'cleared: the row is deleted');

        // a section that is removed from the definitions: its text stays in the table and is left alone
        [$clean] = SectionSet::validate(['sections' => [['key' => self::SIZE, 'title' => 'Individual size', 'source' => 'product', 'text' => '']]]);
        SectionStore::publish($clean);
        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $page->fillForm(['section_texts' => [self::SIZE => '<p>AGAIN</p>']])->call('save')->assertHasNoFormErrors();
        $this->assertSame([self::SIZE => '<p>AGAIN</p>', self::COMP => '<p>COMPOSITIONTEXT</p>'], $this->rows($id));
    }

    /** the same product saved with no section defined: nothing is read or written for the texts */
    public function test_a_product_saved_with_no_section_defined_touches_no_text(): void
    {
        $product = $this->product();
        DB::table('product_section_texts')->insert(['product_id' => $product->id, 'section_key' => self::SIZE, 'body' => '<p>KEPT</p>', 'created_at' => now(), 'updated_at' => now()]);

        Livewire::test(EditProduct::class, ['record' => $product->id])->fillForm(['name' => 'Renamed'])->call('save')->assertHasNoFormErrors();

        $this->assertSame([self::SIZE => '<p>KEPT</p>'], $this->rows((int) $product->id));
    }

    /** the create form saves the texts with the new product */
    public function test_the_create_form_saves_the_texts_with_the_product(): void
    {
        $this->publishSections();

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Created', 'slug' => 'created', 'base_sku' => 'SKU-CREATED', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'section_texts' => [self::SIZE => '<p>FROMCREATE</p>']])
            ->call('create')
            ->assertHasNoFormErrors();

        $id = (int) ProductModel::where('slug', 'created')->value('id');
        $this->assertSame([self::SIZE => '<p>FROMCREATE</p>'], $this->rows($id));
    }

    /** the variable product's edit form has the tab too (the creation wizard is not changed) */
    public function test_the_variable_edit_form_loads_and_saves_the_texts(): void
    {
        $this->publishSections();

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);

        $product = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        app(ProductRepository::class)->save($product);
        $model = ProductModel::where('slug', 'variable-shirt')->firstOrFail();

        SectionTexts::save((int) $model->id, [self::COMP => '<p>VARTEXT</p>'], [self::COMP]);

        $page = Livewire::test(EditVariableProduct::class, ['record' => $model->id]);
        $page->assertFormSet(['section_texts.'.self::COMP => '<p>VARTEXT</p>']);
        $this->assertStringContainsString('Individual size', $page->html());

        $page->fillForm(['section_texts' => [self::SIZE => '<p>VARSIZE</p>', self::COMP => '']])->call('save')->assertHasNoFormErrors();

        $this->assertSame([self::SIZE => '<p>VARSIZE</p>'], $this->rows((int) $model->id));
    }
    /** @return array<string, array{0: string, 1: bool}> the rows of the product: section key => [body, hidden] */
    private function switchRows(int $product): array
    {
        $rows = DB::table('product_section_texts')->where('product_id', $product)->get(['section_key', 'body', 'hidden'])->mapWithKeys(fn ($r): array => [$r->section_key => [(string) $r->body, (bool) $r->hidden]])->all();
        ksort($rows);

        return $rows;
    }

    /** ★ P4c: every published section has the switch «Показвай на този продукт» (ON by default), the text field only for the product-text sources; saved as a flag next to the text; the text is kept while it is off */
    public function test_the_simple_edit_form_has_a_switch_for_every_section(): void
    {
        $this->publishSections();
        $product = $this->product();
        $id = (int) $product->id;

        $page = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $page->assertFormSet(['section_shown.'.self::DELIV => true, 'section_shown.'.self::SIZE => true, 'section_shown.'.self::COMP => true]);
        $this->assertStringContainsString('Show on this product', $page->html());
        $this->assertSame([self::DELIV, self::SIZE, self::COMP], SectionTexts::switchKeys());
        $this->assertSame([self::SIZE, self::COMP], SectionTexts::formKeys(), 'the text fields: only the product-text sources');

        // all ON and no text: nothing is stored
        $page->call('save')->assertHasNoFormErrors();
        $this->assertSame([], $this->switchRows($id));

        // «Delivery» (a global text) OFF for this product, «Size» with a text and OFF: two rows
        $page->fillForm(['section_shown' => [self::DELIV => false, self::SIZE => false, self::COMP => true], 'section_texts' => [self::SIZE => '<p>SIZETEXT</p>']])->call('save')->assertHasNoFormErrors();
        $this->assertSame([self::DELIV => ['', true], self::SIZE => ['<p>SIZETEXT</p>', true]], $this->switchRows($id));

        // loaded again: the switches are OFF, the text is there
        $again = Livewire::test(EditProduct::class, ['record' => $product->id]);
        $again->assertFormSet(['section_shown.'.self::DELIV => false, 'section_shown.'.self::SIZE => false, 'section_shown.'.self::COMP => true, 'section_texts.'.self::SIZE => '<p>SIZETEXT</p>']);

        // switched back ON: the text stays, the empty row of the global section goes
        $again->fillForm(['section_shown' => [self::DELIV => true, self::SIZE => true, self::COMP => true]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([self::SIZE => ['<p>SIZETEXT</p>', false]], $this->switchRows($id));

        // a switch for a section that is not defined is ignored
        $again->fillForm(['section_shown' => ['99999999-9999-4999-8999-999999999999' => false]])->call('save')->assertHasNoFormErrors();
        $this->assertArrayNotHasKey('99999999-9999-4999-8999-999999999999', $this->switchRows($id));
    }

    /** the create form saves the switches with the new product (the tab is there for a product that does not exist yet) */
    public function test_the_create_form_saves_the_switches(): void
    {
        $this->publishSections();

        Livewire::test(CreateProduct::class)
            ->assertFormSet(['section_shown.'.self::DELIV => true])
            ->fillForm(['name' => 'Created 2', 'slug' => 'created-2', 'base_sku' => 'SKU-CREATED-2', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'section_shown' => [self::DELIV => false, self::SIZE => true, self::COMP => true]])
            ->call('create')
            ->assertHasNoFormErrors();

        $id = (int) ProductModel::where('slug', 'created-2')->value('id');
        $this->assertSame([self::DELIV => ['', true]], $this->switchRows($id));
    }

    /** the variable product's edit form has the switches too (not the wizard, not the variations) */
    public function test_the_variable_edit_form_saves_the_switches(): void
    {
        $this->publishSections();

        $definition = new AttributeDefinition(id: null, code: 'color2', name: 'Color2', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $product = Product::createVariable('Variable Shirt 2', 'SKU-VAR2', 'variable-shirt-2');
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR2-BLACK');
        app(ProductRepository::class)->save($product);
        $model = ProductModel::where('slug', 'variable-shirt-2')->firstOrFail();

        $page = Livewire::test(EditVariableProduct::class, ['record' => $model->id]);
        $page->assertFormSet(['section_shown.'.self::DELIV => true]);
        $page->fillForm(['section_shown' => [self::DELIV => false, self::SIZE => true, self::COMP => true]])->call('save')->assertHasNoFormErrors();

        $this->assertSame([self::DELIV => ['', true]], $this->switchRows((int) $model->id));
    }
}