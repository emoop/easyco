<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\Resources\ProductResource\Pages\ViewProduct;
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
use EasyCo\Extensibility\HookRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-3 §4: «Виж в магазина» beside the title of EditProduct, EditVariableProduct and ViewProduct — an icon button to the storefront page in a new tab, enabled only for a product the storefront
 * shows, otherwise disabled with the reason; it answers for the SAVED record, never the form's unsaved state.
 */
class ViewInStoreActionTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
        app(HookRegistry::class)->clear('media.usages');
        $this->actingAsStaff('Administrator');
    }

    private function product(string $status = 'active', string $visibility = 'visible', string $slug = 'summer-dress'): ProductModel
    {
        $product = Product::createSimple('Summer dress', 'SKU-'.strtoupper($slug), $slug);
        app(ProductRepository::class)->save($product);

        return tap(ProductModel::where('slug', $slug)->firstOrFail(), fn (ProductModel $model) => $model->forceFill(['status' => $status, 'catalog_visibility' => $visibility])->save());
    }

    private function url(ProductModel $product): string
    {
        return route('storefront.product', ['slug' => $product->slug]);
    }

    public function test_a_visible_product_gets_an_enabled_link_to_its_storefront_page_in_a_new_tab(): void
    {
        $product = $this->product();

        foreach ([EditProduct::class, ViewProduct::class] as $page) {
            $component = Livewire::test($page, ['record' => $product->id])
                ->assertActionVisible('view_in_store')
                ->assertActionEnabled('view_in_store')
                ->assertActionHasUrl('view_in_store', $this->url($product))
                ->assertActionShouldOpenUrlInNewTab('view_in_store');

            $html = $component->html();
            $this->assertStringContainsString('href="'.$this->url($product).'"', $html);
            $this->assertStringContainsString('target="_blank"', $html);
            $this->assertMatchesRegularExpression('/rel="[^"]*noopener/', $html);
            $this->assertStringContainsString('aria-label="Виж в магазина"', $html);
            $this->assertStringContainsString('Виж в магазина', $html, 'the tooltip');
        }
    }

    public function test_a_cyrillic_slug_is_part_of_the_url(): void
    {
        $product = $this->product(slug: 'лятна-рокля');

        $this->assertStringContainsString('/product/', $this->url($product));
        Livewire::test(EditProduct::class, ['record' => $product->id])->assertActionHasUrl('view_in_store', $this->url($product));
    }

    public function test_a_product_the_storefront_does_not_show_gets_a_disabled_button_with_the_reason(): void
    {
        $cases = [
            ['draft', 'visible', 'чернова'],
            ['archived', 'visible', 'архивиран'],
            ['active', 'hidden', 'скрит от каталога'],
        ];

        foreach ($cases as $i => [$status, $visibility, $reason]) {
            $product = $this->product($status, $visibility, "not-shown-{$i}");

            foreach ([EditProduct::class, ViewProduct::class] as $page) {
                $component = Livewire::test($page, ['record' => $product->id])
                    ->assertActionVisible('view_in_store')
                    ->assertActionDisabled('view_in_store');

                $html = $component->html();
                $this->assertStringContainsString('Продуктът не се показва в магазина: '.$reason, $html);
                $this->assertStringNotContainsString('href="'.$this->url($product).'"', $html, 'no link while it is not shown');
            }
        }
    }

    public function test_a_soft_deleted_product_is_not_shown(): void
    {
        $product = $this->product();

        $this->assertSame('Продуктът не се показва в магазина', \App\Filament\Resources\ProductResource::storeVisibilityReason(tap($product, fn ($p) => $p->deleted_at = now())));
    }

    public function test_the_button_follows_the_saved_state_not_the_unsaved_form(): void
    {
        $product = $this->product();

        $component = Livewire::test(EditProduct::class, ['record' => $product->id])->assertActionEnabled('view_in_store');

        $component->fillForm(['status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value])
            ->assertActionEnabled('view_in_store');

        $component->call('save')->assertHasNoFormErrors()->assertActionDisabled('view_in_store');
        $this->assertStringContainsString('Продуктът не се показва в магазина', $component->html());

        $component->fillForm(['status' => ProductStatus::ACTIVE->value, 'catalog_visibility' => CatalogVisibility::VISIBLE->value])
            ->assertActionDisabled('view_in_store')
            ->call('save')->assertHasNoFormErrors()
            ->assertActionEnabled('view_in_store');
    }

    public function test_the_variable_product_page_has_it_too(): void
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $variable = Product::createVariable('Variable Shirt', 'SKU-VAR', 'variable-shirt');
        $variable->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $variable->addStandardVariation([$definition->id() => $black->id()], 'SKU-VAR-BLACK');
        app(ProductRepository::class)->save($variable);
        $model = ProductModel::where('slug', 'variable-shirt')->firstOrFail();

        // a new product is a draft: disabled with the reason, then enabled once it is active and visible
        Livewire::test(EditVariableProduct::class, ['record' => $model->id])->assertActionDisabled('view_in_store');

        $model->forceFill(['status' => 'active', 'catalog_visibility' => 'visible'])->save();
        Livewire::test(EditVariableProduct::class, ['record' => $model->id])
            ->assertActionEnabled('view_in_store')
            ->assertActionHasUrl('view_in_store', $this->url($model))
            ->assertActionShouldOpenUrlInNewTab('view_in_store');
    }

    public function test_it_is_reached_only_through_the_page_so_a_user_without_the_permission_gets_nothing(): void
    {
        $product = $this->product();
        $this->actingAsCustomRole([\EasyCo\Staff\Enums\Permission::ORDER_VIEW]);

        Livewire::test(EditProduct::class, ['record' => $product->id])->assertForbidden();
        Livewire::test(ViewProduct::class, ['record' => $product->id])->assertForbidden();
    }

    public function test_a_view_only_user_sees_it_on_the_view_page(): void
    {
        $product = $this->product();
        $this->actingAsCustomRole([\EasyCo\Staff\Enums\Permission::PRODUCT_VIEW]);

        Livewire::test(ViewProduct::class, ['record' => $product->id])->assertActionVisible('view_in_store')->assertActionEnabled('view_in_store');
    }
}
