<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\ProductResource\Pages\EditVariableProduct;
use App\Media\MediaFilters;
use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\VariationAxis;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\VariationMediaModel;
use EasyCo\Media\VariationMedia;
use EasyCo\Media\VariationMediaCountGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * ML-3 §2: the photos of a variation through the media picker (`variation_photo_ids` in every row of the variations repeater). Same rules as the product photos: ids only, detach never deletes,
 * VariationMediaCountGuard stays the one real count enforcement, a photo the variation already has is not re-checked against the minimum size.
 */
class VariationPhotoPickerTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    private ProductModel $product;

    private string $blackId;

    private string $whiteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
        $this->actingAsStaff('Administrator');

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
        app(ProductRepository::class)->save($product);
        // the ids exist once the product is saved (the aggregate hands them to the variations)
        $this->blackId = (string) $blackVariation->id();
        $this->whiteId = (string) $whiteVariation->id();
        $this->product = ProductModel::findOrFail($product->id());
    }

    private function attach(string $variationId, int $mediaId, int $sort): void
    {
        app(VariationMediaRepository::class)->save(new VariationMedia(null, $variationId, (string) $mediaId, $sort));
    }

    /** @return array<int, int> media id => sort_order, in sort order */
    private function pivots(string $variationId): array
    {
        return VariationMediaModel::query()->where('variation_id', $variationId)->orderBy('sort_order')->pluck('sort_order', 'media_id')->map(fn ($v): int => (int) $v)->all();
    }

    private function page()
    {
        return Livewire::test(EditVariableProduct::class, ['record' => $this->product->id]);
    }

    private function rowKey($component, string $variationId): string
    {
        foreach ($component->get('data.existing_variations') as $key => $row) {
            if ($row['variation_id'] === $variationId) {
                return (string) $key;
            }
        }

        $this->fail('row not found');
    }

    private function path(string $key): string
    {
        return "data.existing_variations.{$key}.variation_photo_ids";
    }

    public function test_the_row_is_filled_with_the_variations_photo_ids_in_order(): void
    {
        [$a, $b] = [$this->pickerAsset()->id, $this->pickerAsset()->id];
        $this->attach($this->blackId, $b, 0);
        $this->attach($this->blackId, $a, 1);

        $component = $this->page();

        $this->assertSame([$b, $a], array_map('intval', $component->get($this->path($this->rowKey($component, $this->blackId)))));
        $this->assertSame([], $component->get($this->path($this->rowKey($component, $this->whiteId))));
    }

    public function test_the_deepest_state_path_of_the_variation_repeater_stays_far_from_livewires_limit(): void
    {
        $component = $this->page();
        $key = $this->rowKey($component, $this->blackId);
        $deepest = $this->path($key).'.0';

        // data . existing_variations . {row} . variation_photo_ids . {n}
        $this->assertSame(5, count(explode('.', $deepest)));
        $this->assertLessThan(24, count(explode('.', $deepest)));
    }

    public function test_the_row_field_is_a_list_picker_limited_to_the_per_variation_maximum(): void
    {
        config(['services.media.max_photos_per_variation' => 4]);
        $field = $this->page()->instance()->form->getComponent('existing_variations')->getDefaultChildComponents();
        $this->assertNotEmpty($field);

        $picker = collect($this->page()->instance()->form->getFlatComponents(withHidden: true))->first(fn ($c) => $c instanceof \App\Filament\Forms\Components\MediaPicker && str_ends_with($c->getStatePath(), 'variation_photo_ids'));
        $this->assertNotNull($picker);
        $this->assertTrue($picker->isMultiple());
        $this->assertSame(4, $picker->getMaxItems());
    }

    public function test_picking_reordering_and_removing_a_variations_photos(): void
    {
        [$a, $b, $c] = [$this->pickerAsset()->id, $this->pickerAsset()->id, $this->pickerAsset()->id];

        $component = $this->page();
        $key = $this->rowKey($component, $this->blackId);
        $component->set($this->path($key), [$a, $b])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$a => 0, $b => 1], $this->pivots($this->blackId));
        $this->assertSame([], $this->pivots($this->whiteId), 'a sibling variation is untouched');

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), [$b, $c, $a])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$b => 0, $c => 1, $a => 2], $this->pivots($this->blackId));

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), [$c])->call('save')->assertHasNoFormErrors();
        $this->assertSame([$c => 0], $this->pivots($this->blackId));
        $this->assertNotNull(MediaAssetModel::find($a));
        $this->assertEqualsCanonicalizing([$a, $b], MediaFilters::use(MediaAssetModel::query(), 'unused')->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'detached photos are «неизползвани» in the library');
    }

    public function test_one_asset_on_two_variations_and_a_product_is_listed_everywhere_and_detaching_keeps_the_others(): void
    {
        $shared = $this->pickerAsset()->id;

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), [$shared])->set($this->path($this->rowKey($component, $this->whiteId)), [$shared])->call('save')->assertHasNoFormErrors();

        $this->assertSame(2, VariationMediaModel::query()->where('media_id', $shared)->count());
        $this->assertCount(2, (new MediaUsageRegistry)->usagesOf($shared));

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), [])->call('save')->assertHasNoFormErrors();
        $this->assertSame([], $this->pivots($this->blackId));
        $this->assertSame([$shared => 0], $this->pivots($this->whiteId));
    }

    public function test_the_guards_message_is_shown_and_nothing_is_saved_when_the_guard_refuses(): void
    {
        $max = (int) config('services.media.max_photos_per_variation', 3);
        $ids = array_map(fn () => $this->pickerAsset()->id, range(1, $max));
        // the guard's number is smaller than the form's: the configuration changed between drawing the page and the save
        $this->app->instance(VariationMediaCountGuard::class, new VariationMediaCountGuard(app(VariationMediaRepository::class), $max - 1));

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), $ids)
            ->call('save')
            ->assertNotified(MediaLimitExceededException::forVariation($this->blackId, $max - 1, $max - 1)->getMessage());

        $this->assertSame([], $this->pivots($this->blackId), 'the whole save was undone');
    }

    public function test_more_than_the_variation_maximum_is_refused_by_the_field(): void
    {
        $max = (int) config('services.media.max_photos_per_variation', 3);
        $ids = array_map(fn () => $this->pickerAsset()->id, range(1, $max + 1));

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), $ids)->call('save')->assertHasErrors();

        $this->assertSame([], $this->pivots($this->blackId));
    }

    public function test_a_video_a_path_or_a_missing_id_is_refused(): void
    {
        $video = $this->pickerVideo()->id;

        foreach ([[$video], ['uploads/2026/10/x.jpg'], [987654], [0]] as $bad) {
            $component = $this->page();
            $component->set($this->path($this->rowKey($component, $this->blackId)), $bad)->call('save')->assertHasErrors();
        }

        $this->assertSame([], $this->pivots($this->blackId));
    }

    public function test_a_small_picture_is_refused_but_an_old_small_photo_of_the_variation_is_kept_on_a_no_op_save(): void
    {
        $oldSmall = $this->pickerAsset(['width' => 320, 'height' => 240])->id;
        $newSmall = $this->pickerAsset(['width' => 320, 'height' => 240])->id;
        $this->attach($this->blackId, $oldSmall, 0);

        $component = $this->page();
        $component->set($this->path($this->rowKey($component, $this->blackId)), [$oldSmall, $newSmall])->call('save')->assertHasErrors();
        $this->assertSame([$oldSmall => 0], $this->pivots($this->blackId));

        $this->page()->call('save')->assertHasNoFormErrors();
        $this->assertSame([$oldSmall => 0], $this->pivots($this->blackId));
    }

    public function test_the_pick_action_mounts_inside_a_repeater_row(): void
    {
        $component = $this->page();
        $path = $this->path($this->rowKey($component, $this->blackId));

        $component->mountFormComponentAction($path, 'pick')->assertHasNoErrors();
        $this->assertStringContainsString('livewire', strtolower($component->html()));
    }
}
