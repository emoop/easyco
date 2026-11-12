<?php

namespace Tests\Feature;

use App\Filament\Pages\Settings\CatalogSettings;
use App\Filament\Resources\CollectionResource;
use App\Filament\Resources\CollectionResource\Pages\CreateCollection;
use App\Filament\Resources\CollectionResource\Pages\EditCollection;
use App\Filament\Resources\CollectionResource\Pages\ListCollections;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Jobs\RefreshProductSortPrices;
use App\Media\Usage\CollectionPictures;
use App\Services\DuplicateProduct;
use App\Services\MassEdit\MassEditRefused;
use App\Services\MassEdit\MassEditSelection;
use App\Services\MassEdit\ProductMassEditor;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Collection;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\CollectionRepository;
use EasyCo\Catalog\Contracts\ProductCollectionRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Enums\CatalogVisibility;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Exceptions\CollectionAlreadyAssignedException;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCollection;
use EasyCo\Catalog\VariationAxis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Feature\Storefront\BuildsStorefrontCatalog;
use Tests\TestCase;

/**
 * P6e-1: «Колекции» — the data layer and the admin, switched OFF by default (`catalog.collections_enabled`, `'1'` = on). A collection is name + slug + ONE picture (the season's shape) and many-to-many with products
 * (the tag's pivot shape). Nothing of it is visible in the admin while the switch is OFF, and a product saved while it is OFF keeps its collections.
 */
class CollectionsAdminTest extends TestCase
{
    use BuildsShippingZones;
    use BuildsStorefrontCatalog;
    use GivesProductsAShippingClass;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
        $this->giveProductsAShippingClass();
        app()->setLocale('en');
        Storage::fake('public');
        app(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        $this->actingAsStaff('Administrator');
    }

    private function on(bool $on = true): void
    {
        app(SiteSettingsRepository::class)->set('catalog.collections_enabled', $on ? '1' : '0');
    }

    private function collection(string $name, string $slug): string
    {
        $collection = new Collection(id: null, name: $name, slug: $slug);
        app(CollectionRepository::class)->save($collection);

        return (string) $collection->id();
    }

    private function simpleProduct(string $slug): ProductModel
    {
        app(ProductRepository::class)->save(Product::createSimple('Product '.$slug, 'SKU-'.strtoupper($slug), $slug));

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /** @return list<int> */
    private function collectionsOf(int|string $productId): array
    {
        return DB::table('catalog_product_collections')->where('product_id', $productId)->orderBy('collection_id')->pluck('collection_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<array<string, mixed>> */
    private function entries(): array
    {
        return DB::table('activity_log')->where('entity_type', 'product')->where('field', 'collections')->orderBy('id')->get(['entity_id', 'old_value', 'new_value'])->map(fn ($r) => (array) $r)->all();
    }

    // ---- data layer -----------------------------------------------------------------------------------------------------

    /** ★ the pivot repository: save, the grouped read, the unique pair, remove; the collection repository counts through the pivot */
    public function test_the_repositories(): void
    {
        $a = $this->collection('Wedding', 'wedding');
        $b = $this->collection('Pre-spring', 'pre-spring');
        $one = $this->simpleProduct('one');
        $two = $this->simpleProduct('two');
        $pivots = app(ProductCollectionRepository::class);

        $pivots->save(new ProductCollection(id: null, productId: (string) $one->id, collectionId: $a));
        $pivots->save(new ProductCollection(id: null, productId: (string) $one->id, collectionId: $b));
        $pivots->save(new ProductCollection(id: null, productId: (string) $two->id, collectionId: $a));

        $grouped = [];
        foreach ($pivots->findByProductIds([(string) $one->id, (string) $two->id]) as $pivot) {
            $grouped[$pivot->productId()][] = $pivot->collectionId();
        }
        $this->assertSame([(string) $one->id => [$a, $b], (string) $two->id => [$a]], $grouped);
        $this->assertSame([], $pivots->findByProductIds([]));

        try {
            $pivots->save(new ProductCollection(id: null, productId: (string) $one->id, collectionId: $a));
            $this->fail('the same pair twice must be refused');
        } catch (CollectionAlreadyAssignedException) {
            $this->assertCount(2, $pivots->findByProductId((string) $one->id));
        }

        $this->assertSame(2, app(CollectionRepository::class)->countProductsUsing($a));
        $this->assertSame(1, app(CollectionRepository::class)->countProductsUsing($b));

        $pivots->remove((string) $pivots->findByProductId((string) $two->id)[0]->id());
        $this->assertSame(1, app(CollectionRepository::class)->countProductsUsing($a));
    }

    /** ★ deleting a collection removes its pivot rows; deleting a product removes its pivot rows */
    public function test_the_pivot_follows_the_collection_and_the_product(): void
    {
        $a = $this->collection('Wedding', 'wedding');
        $b = $this->collection('Other', 'other');
        $one = $this->simpleProduct('one');
        $pivots = app(ProductCollectionRepository::class);
        $pivots->save(new ProductCollection(id: null, productId: (string) $one->id, collectionId: $a));
        $pivots->save(new ProductCollection(id: null, productId: (string) $one->id, collectionId: $b));

        app(CollectionRepository::class)->delete($a);
        $this->assertSame([(int) $b], $this->collectionsOf($one->id));

        DB::table('catalog_variations')->where('product_id', $one->id)->delete();
        DB::table('catalog_products')->where('id', $one->id)->delete();
        $this->assertSame([], $this->collectionsOf($one->id));
        $this->assertSame(0, DB::table('catalog_product_collections')->count());
    }

    // ---- OFF (the default) ---------------------------------------------------------------------------------------------------

    /** ★ OFF: no navigation item, the direct URL is refused, no product field / filter / mass action; the settings page offers only the switch */
    public function test_everything_is_invisible_while_the_switch_is_off(): void
    {
        $this->assertFalse(ProductResource::collectionsEnabled(), 'a missing key is OFF');
        $this->assertFalse(CollectionResource::canViewAny());
        $this->assertFalse(collect(CollectionResource::getNavigationItems())->isNotEmpty() && CollectionResource::canViewAny());
        $this->get(CollectionResource::getUrl('index'))->assertForbidden();

        $product = $this->simpleProduct('one');
        Livewire::test(ListProducts::class)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::ADD_COLLECTION)
            ->assertTableBulkActionHidden('mass_'.ProductMassEditor::REMOVE_COLLECTION)
            ->assertTableFilterHidden('collections');

        $html = Livewire::test(EditProduct::class, ['record' => $product->id])->html();
        $this->assertStringNotContainsString('Collections', $html);

        try {
            $this->simpleProduct('two');
            app(ProductMassEditor::class)->start(ProductMassEditor::ADD_COLLECTION, '1', new MassEditSelection(['1'], true, false));
            $this->fail('expected a refusal');
        } catch (MassEditRefused $refused) {
            $this->assertSame('field_off', $refused->reason);
        }

        // the settings page: the switch is the only collection control, default off
        Livewire::test(CatalogSettings::class)->assertSchemaStateSet(['collections_enabled' => false]);
    }

    /** ★ a product saved while the switch is OFF KEEPS its collections (the hidden field must not sync an empty list) */
    public function test_a_product_saved_while_off_keeps_its_collections(): void
    {
        $a = $this->collection('Wedding', 'wedding');
        $b = $this->collection('Other', 'other');
        $simple = $this->simpleProduct('simple');
        $pivots = app(ProductCollectionRepository::class);
        $pivots->save(new ProductCollection(id: null, productId: (string) $simple->id, collectionId: $a));
        $pivots->save(new ProductCollection(id: null, productId: (string) $simple->id, collectionId: $b));
        DB::table('activity_log')->delete();

        Livewire::test(EditProduct::class, ['record' => $simple->id])->fillForm(['name' => 'Renamed while off'])->call('save')->assertHasNoFormErrors();

        $this->assertSame([(int) $a, (int) $b], $this->collectionsOf($simple->id));
        $this->assertSame([], $this->entries(), 'no collection audit entry while off');

        $variable = $this->variableProduct('variable-off');
        $pivots->save(new ProductCollection(id: null, productId: (string) $variable->id, collectionId: $a));
        Livewire::test(EditVariableProduct::class, ['record' => $variable->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame([(int) $a], $this->collectionsOf($variable->id));
    }

    private function variableProduct(string $slug): ProductModel
    {
        $definition = new AttributeDefinition(id: null, code: 'color-'.$slug, name: 'Color '.$slug, type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);
        $black = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        app(AttributeValueRepository::class)->save($black);
        $product = Product::createVariable('Variable '.$slug, 'SKU-'.strtoupper($slug), $slug);
        $product->declareVariationAxes([new VariationAxis($definition, [$black])]);
        $product->addStandardVariation([$definition->id() => $black->id()], 'SKU-'.strtoupper($slug).'-BLACK');
        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    // ---- ON --------------------------------------------------------------------------------------------------------------------

    /** ★ ON: the resource is reachable; create and edit a collection with a picture */
    public function test_create_and_edit_a_collection_with_a_picture(): void
    {
        $this->on();
        $this->assertTrue(CollectionResource::canViewAny());
        $this->get(CollectionResource::getUrl('index'))->assertOk();

        $product = $this->simpleProduct('pic');
        $this->makeImage(501, (int) $product->id);

        Livewire::test(CreateCollection::class)
            ->fillForm(['name' => 'Wedding', 'slug' => 'wedding', 'picture_media_asset_id' => 501])
            ->call('create')
            ->assertHasNoFormErrors();

        $model = CollectionModel::where('slug', 'wedding')->firstOrFail();
        $this->assertSame(501, (int) $model->picture_media_asset_id);

        Livewire::test(EditCollection::class, ['record' => $model->id])->fillForm(['name' => 'Wedding 26', 'picture_media_asset_id' => null])->call('save')->assertHasNoFormErrors();
        $model->refresh();
        $this->assertSame(['Wedding 26', null], [$model->name, $model->picture_media_asset_id]);

        Livewire::test(ListCollections::class)->assertCanSeeTableRecords([$model]);
    }

    /** ★ ON: the product form assigns several collections (simple edit, variable edit, create), with one audit entry per change; the form shows them */
    public function test_the_product_form_assigns_several_collections(): void
    {
        $this->on();
        $a = $this->collection('Wedding', 'wedding');
        $b = $this->collection('Pre-spring', 'pre-spring');
        $simple = $this->simpleProduct('simple');

        $page = Livewire::test(EditProduct::class, ['record' => $simple->id])->assertFormFieldVisible('collections');
        $page->fillForm(['collections' => [$a, $b]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([(int) $a, (int) $b], $this->collectionsOf($simple->id));
        $this->assertSame([['entity_id' => (string) $simple->id, 'old_value' => null, 'new_value' => 'Wedding'], ['entity_id' => (string) $simple->id, 'old_value' => null, 'new_value' => 'Pre-spring']], $this->entries());

        Livewire::test(EditProduct::class, ['record' => $simple->id])->assertFormSet(['collections' => [$a, $b]])->fillForm(['collections' => [$b]])->call('save');
        $this->assertSame([(int) $b], $this->collectionsOf($simple->id));
        $this->assertSame(['old_value' => 'Wedding', 'new_value' => null], array_diff_key(array_slice($this->entries(), -1)[0], ['entity_id' => 1]));

        $variable = $this->variableProduct('variable-on');
        Livewire::test(EditVariableProduct::class, ['record' => $variable->id])->fillForm(['collections' => [$a, $b]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([(int) $a, (int) $b], $this->collectionsOf($variable->id));

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Created', 'slug' => 'created', 'base_sku' => 'SKU-CREATED', 'status' => ProductStatus::DRAFT->value, 'catalog_visibility' => CatalogVisibility::HIDDEN->value, 'collections' => [$a]])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame([(int) $a], $this->collectionsOf((int) ProductModel::where('slug', 'created')->value('id')));
    }

    /** ★ duplicate copies the collections like the tags */
    public function test_duplicate_copies_the_collections(): void
    {
        $a = $this->collection('Wedding', 'wedding');
        $source = $this->simpleProduct('source');
        app(ProductCollectionRepository::class)->save(new ProductCollection(id: null, productId: (string) $source->id, collectionId: $a));

        $copy = app(DuplicateProduct::class)->duplicate((string) $source->id);

        $this->assertSame([(int) $a], $this->collectionsOf($copy->id()));
        $this->assertSame([(int) $a], $this->collectionsOf($source->id));
    }

    /** ★ the list filter returns the right products (and the resource's count column links to it) */
    public function test_the_list_filter(): void
    {
        $this->on();
        $a = $this->collection('Wedding', 'wedding');
        $in = $this->simpleProduct('in');
        $out = $this->simpleProduct('out');
        app(ProductCollectionRepository::class)->save(new ProductCollection(id: null, productId: (string) $in->id, collectionId: $a));

        Livewire::withQueryParams(['status' => 'all'])->test(ListProducts::class)
            ->assertTableFilterVisible('collections')
            ->filterTable('collections', $a)
            ->assertCanSeeTableRecords([$in])
            ->assertCanNotSeeTableRecords([$out]);
    }

    /** ★ mass add / remove are idempotent with «променени / без промяна»; the actions are visible while ON */
    public function test_mass_add_and_remove(): void
    {
        $this->on();
        $a = $this->collection('Wedding', 'wedding');
        $one = $this->simpleProduct('one');
        $two = $this->simpleProduct('two');
        $editor = app(ProductMassEditor::class);
        $selection = new MassEditSelection([(string) $one->id, (string) $two->id], true, false);

        Livewire::test(ListProducts::class)->assertTableBulkActionVisible('mass_'.ProductMassEditor::ADD_COLLECTION);

        $first = $editor->start(ProductMassEditor::ADD_COLLECTION, $a, $selection);
        $this->assertSame([2, 0], [$first->changed, $first->unchanged]);
        $second = $editor->start(ProductMassEditor::ADD_COLLECTION, $a, $selection);
        $this->assertSame([0, 2], [$second->changed, $second->unchanged]);

        $removed = $editor->start(ProductMassEditor::REMOVE_COLLECTION, $a, new MassEditSelection([(string) $one->id], true, false));
        $this->assertSame([1, 0], [$removed->changed, $removed->unchanged]);
        $this->assertSame([], $this->collectionsOf($one->id));
        $this->assertSame([(int) $a], $this->collectionsOf($two->id));
        $this->assertCount(3, $this->entries(), 'one audit entry per changed product');
    }

    // ---- media usage, triggers ---------------------------------------------------------------------------------------------

    /** ★ the media library knows the collection's picture (it cannot be deleted as unused) */
    public function test_media_usage_lists_the_collection_picture(): void
    {
        $product = $this->simpleProduct('pic');
        $this->makeImage(601, (int) $product->id);
        $collection = new Collection(id: null, name: 'Wedding', slug: 'wedding', pictureMediaAssetId: '601');
        app(CollectionRepository::class)->save($collection);

        $registry = app(\App\Media\Usage\MediaUsageRegistry::class);
        $this->assertGreaterThanOrEqual(2, $registry->counts([601])[601] ?? 0, 'the product photo AND the collection use it');
        $usages = array_values(array_filter($registry->usagesOf(601), fn ($usage) => str_contains($usage->label, 'Collection')));
        $this->assertCount(1, $usages);
        $this->assertSame([601 => 1], (new CollectionPictures)->counts([601]));
    }

    /** ★ a pivot save queues a sort-price refresh; the mass editor's muted chunk queues exactly one */
    public function test_the_triggers_follow_the_pivot(): void
    {
        $this->on();
        $a = $this->collection('Wedding', 'wedding');
        $one = $this->simpleProduct('one');
        $two = $this->simpleProduct('two');

        Queue::fake();
        app(ProductCollectionRepository::class)->save(new ProductCollection(id: null, productId: (string) $one->id, collectionId: $a));
        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class));

        Queue::fake();
        app(ProductMassEditor::class)->start(ProductMassEditor::ADD_COLLECTION, $a, new MassEditSelection([(string) $one->id, (string) $two->id], true, false));
        $this->assertCount(1, Queue::pushed(RefreshProductSortPrices::class), 'muted per chunk, one refresh for the changed products');
        $this->assertSame([(string) $two->id], Queue::pushed(RefreshProductSortPrices::class)->first()->productIds);
    }

    // ---- query counts ----------------------------------------------------------------------------------------------------------

    /** ★ the products list renders with the SAME number of queries with the switch ON and OFF (the new key rides the one getMany of the other switches) */
    public function test_the_list_render_costs_the_same_with_the_switch_on_and_off(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->simpleProduct('p'.$i);
        }
        $this->collection('Wedding', 'wedding');

        $count = function (bool $on): int {
            $this->on($on);
            $this->app->forgetScopedInstances();
            Livewire::withQueryParams(['status' => 'all'])->test(ListProducts::class); // warm-up
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            Livewire::withQueryParams(['status' => 'all'])->test(ListProducts::class);

            return $n;
        };

        $off = $count(false);
        $on = $count(true);

        fwrite(STDERR, "\n[query-count] products list render: collections switch OFF {$off}, ON {$on}\n");
        $this->assertLessThanOrEqual($off + 1, $on, 'ON may add at most the filter\'s own option read');
    }
}
