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

        // only global sections: no tab
        [$clean] = SectionSet::validate(['sections' => [['key' => self::DELIV, 'title' => 'Delivery', 'source' => 'global', 'text' => '<p>G</p>']]]);
        SectionStore::publish($clean);
        $this->assertFalse(ProductResource::sectionTextsTab()->isVisible());
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
}
