<?php

namespace Tests\Feature\Media;

use App\Filament\Forms\Components\MediaPicker;
use App\Filament\Resources\AttributeValueResource\Pages\CreateAttributeValue;
use App\Filament\Resources\AttributeValueResource\Pages\EditAttributeValue;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Livewire\MediaPickerBrowser;
use App\Media\MediaDeleter;
use App\Media\MediaFilters;
use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * CT-1: one picture for a category and one for an attribute value (a season, a colour ...), chosen with the media picker: stored, editable, shown in «Къде се ползва», protected from deletion. Nothing
 * is shown on the storefront.
 */
class CategoryAndValuePictureTest extends TestCase
{
    use BuildsShippingZones;
    use GivesProductsAShippingClass;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->giveProductsAShippingClass();
        app()->setLocale('bg');
        app(HookRegistry::class)->clear('media.usages');
        Storage::fake('public');
        $this->actingAsStaff('Administrator');
    }

    private function category(string $slug = 'dresses', ?int $picture = null): CategoryModel
    {
        $category = new Category(id: null, parentId: null, name: ucfirst($slug), slug: $slug);
        $picture !== null && $category->setPicture((string) $picture);
        app(CategoryRepository::class)->save($category);

        return CategoryModel::findOrFail($category->id());
    }

    private function definition(string $code = 'season', string $name = 'Сезон'): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: $code, name: $name, type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    private function value(AttributeDefinition $definition, string $value, int $sort = 0, ?int $picture = null): AttributeValueModel
    {
        $v = new AttributeValue(id: null, attributeDefinitionId: (string) $definition->id(), value: $value, sortOrder: $sort);
        $picture !== null && $v->setPicture((string) $picture);
        app(AttributeValueRepository::class)->save($v);

        return AttributeValueModel::findOrFail($v->id());
    }

    /** @return list<int> */
    private function unused(): array
    {
        return MediaFilters::use(MediaAssetModel::query(), 'unused')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    // ================================================================ categories ===============================================================

    public function test_a_category_is_created_with_a_picture_and_the_domain_reads_it_back(): void
    {
        $picture = $this->pickerAsset(['width' => 20, 'height' => 20, 'mime' => 'image/jpeg']);

        Livewire::test(CreateCategory::class)->fillForm(['name' => 'Dresses', 'slug' => 'dresses', 'picture_media_asset_id' => $picture->id])->call('create')->assertHasNoFormErrors();

        $model = CategoryModel::where('slug', 'dresses')->firstOrFail();
        $this->assertSame((int) $picture->id, (int) $model->picture_media_asset_id, 'no minimum size, any picture type');
        $this->assertSame((string) $picture->id, app(CategoryRepository::class)->findById((string) $model->id)->pictureMediaAssetId());
    }

    public function test_a_category_without_a_picture_is_what_it_was(): void
    {
        Livewire::test(CreateCategory::class)->fillForm(['name' => 'Plain', 'slug' => 'plain'])->call('create')->assertHasNoFormErrors();

        $this->assertNull(CategoryModel::where('slug', 'plain')->firstOrFail()->picture_media_asset_id);
        $this->assertNull(app(CategoryRepository::class)->findById((string) CategoryModel::where('slug', 'plain')->value('id'))->pictureMediaAssetId());
    }

    public function test_editing_changes_the_picture_and_a_save_that_does_not_touch_it_keeps_it(): void
    {
        [$a, $b] = [$this->pickerAsset(), $this->pickerAsset()];
        $category = $this->category('dresses', $a->id);

        // opened and saved without touching the field: the picture is still there
        Livewire::test(EditCategory::class, ['record' => $category->id])->assertFormSet(['picture_media_asset_id' => $a->id])->fillForm(['name' => 'Dresses 2'])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $a->id, (int) $category->fresh()->picture_media_asset_id);

        Livewire::test(EditCategory::class, ['record' => $category->id])->fillForm(['picture_media_asset_id' => $b->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $b->id, (int) $category->fresh()->picture_media_asset_id);
        $this->assertContains((int) $a->id, $this->unused(), 'the replaced picture stays in «Медия», unused');
    }

    public function test_removing_the_picture_only_detaches_it_and_the_asset_becomes_unused(): void
    {
        $picture = $this->pickerAsset();
        $category = $this->category('dresses', $picture->id);
        $this->assertNotContains((int) $picture->id, $this->unused());

        Livewire::test(EditCategory::class, ['record' => $category->id])->fillForm(['picture_media_asset_id' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($category->fresh()->picture_media_asset_id);
        $this->assertNotNull(MediaAssetModel::find($picture->id), 'the asset stays in «Медия»');
        $this->assertContains((int) $picture->id, $this->unused(), 'and is «неизползвана»');
    }

    public function test_a_video_a_social_image_and_a_missing_id_are_refused(): void
    {
        $video = $this->pickerVideo();
        $social = $this->pickerAsset(['type' => 'social_image']);

        foreach ([$video->id, $social->id, 987654, 'uploads/2026/10/x.jpg'] as $bad) {
            Livewire::test(CreateCategory::class)->fillForm(['name' => 'Bad', 'slug' => 'bad', 'picture_media_asset_id' => $bad])->call('create')->assertHasFormErrors(['picture_media_asset_id']);
        }

        $this->assertNull(CategoryModel::where('slug', 'bad')->first());
    }

    public function test_the_picker_of_the_field_lists_no_social_image(): void
    {
        $image = $this->pickerAsset();
        $social = $this->pickerAsset(['type' => 'social_image']);
        $field = (new \ReflectionClass(MediaPicker::make('x')->plainImagesOnly()))->getProperty('plainImagesOnly');

        $form = Livewire::test(CreateCategory::class)->instance()->form->getComponent('picture_media_asset_id');
        $this->assertTrue($field->getValue($form), 'the category field is plainImagesOnly()');

        $browser = Livewire::test(MediaPickerBrowser::class, ['field' => 'data.picture_media_asset_id', 'plain' => true]);
        $this->assertSame([$image->id], $browser->viewData('rows')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotContains($social->id, $browser->viewData('rows')->pluck('id')->all());
    }

    public function test_one_picture_can_be_the_picture_of_two_categories(): void
    {
        $picture = $this->pickerAsset();
        $this->category('one', $picture->id);
        $two = $this->category('two');

        Livewire::test(EditCategory::class, ['record' => $two->id])->fillForm(['picture_media_asset_id' => $picture->id])->call('save')->assertHasNoFormErrors();

        $this->assertSame(2, CategoryModel::where('picture_media_asset_id', $picture->id)->count());
        $this->assertCount(2, (new MediaUsageRegistry)->usagesOf($picture->id));
    }

    public function test_the_field_sits_after_the_slug_with_the_owners_help_line_and_the_standard_look(): void
    {
        $html = Livewire::test(CreateCategory::class)->html();

        $this->assertLessThan(strpos($html, 'Снимка'), strpos($html, 'Слъг'), 'after the name and the slug');
        $this->assertStringContainsString('Няма избрана снимка', $html);
        $this->assertSame(1, substr_count($html, 'Добави изображение'), 'ONE button');
        $this->assertStringContainsString('Показва се в страницата на категорията и в плочките с категории. Снимката остава в Медия, ако я махнете оттук.', $html);

        $filled = Livewire::test(EditCategory::class, ['record' => $this->category('dresses', $this->pickerAsset()->id)->id])->html();
        $this->assertStringContainsString('Смени', $filled);
        $this->assertStringContainsString('Премахни изображението', $filled);
        $this->assertStringContainsString('-medium.webp', $filled);
    }

    public function test_the_list_shows_a_small_thumbnail_found_in_one_query_whatever_the_number_of_rows(): void
    {
        $queries = function (int $categories): int {
            CategoryModel::query()->delete();
            MediaAssetModel::query()->delete();

            for ($i = 0; $i < $categories; $i++) {
                $this->category('c'.$i, $this->pickerAsset()->id);
            }

            Livewire::test(ListCategories::class)->assertSuccessful();   // warm-up: one-time reads (settings, the tree) are not what is measured
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListCategories::class)->assertSuccessful();
            $n = count(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $q): bool => str_contains($q, 'catalog_media')));
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($queries(2), $queries(8), 'a fixed number of media queries, not one per row');
        $this->assertSame(1, $queries(3));

        $category = $this->category('shown', $this->pickerAsset()->id);
        $thumbs = CategoryResource::pictureThumbnails();
        $this->assertStringEndsWith('-thumbnail.webp', $thumbs[(int) $category->id], 'the thumbnail size, never the original');
        // the column shows a file that is on the disk (a missing one is no broken image)
        Storage::disk('public')->put($thumbs[(int) $category->id], 'thumbnail bytes');
        Livewire::test(ListCategories::class)->assertSeeHtml('-thumbnail.webp"');
    }

    public function test_the_picker_refuses_a_user_without_the_needed_permission(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        Livewire::test(MediaPickerBrowser::class, ['field' => 'data.picture_media_asset_id', 'plain' => true])->assertForbidden();
    }

    public function test_deleting_a_category_frees_its_picture_and_never_deletes_it(): void
    {
        $picture = $this->pickerAsset();
        $category = $this->category('dresses', $picture->id);

        app(CategoryRepository::class)->delete((string) $category->id);

        $this->assertNotNull(MediaAssetModel::find($picture->id));
        $this->assertContains((int) $picture->id, $this->unused());
    }

    // ================================================================ attribute values =========================================================

    public function test_a_value_is_created_with_a_picture_and_the_domain_reads_it_back(): void
    {
        $definition = $this->definition();
        $picture = $this->pickerAsset(['width' => 20, 'height' => 20]);

        Livewire::test(CreateAttributeValue::class)->fillForm(['attribute_definition_id' => $definition->id(), 'value' => 'Есен 26', 'sort_order' => 1, 'picture_media_asset_id' => $picture->id])->call('create')->assertHasNoFormErrors();

        $model = AttributeValueModel::where('value', 'Есен 26')->firstOrFail();
        $this->assertSame((int) $picture->id, (int) $model->picture_media_asset_id);
        $this->assertSame((string) $picture->id, app(AttributeValueRepository::class)->findById((string) $model->id)->pictureMediaAssetId());
    }

    public function test_editing_a_value_changes_keeps_and_detaches_the_picture(): void
    {
        $definition = $this->definition();
        [$a, $b] = [$this->pickerAsset(), $this->pickerAsset()];
        $value = $this->value($definition, 'Пролет 27', 0, $a->id);

        Livewire::test(EditAttributeValue::class, ['record' => $value->id])->assertFormSet(['picture_media_asset_id' => $a->id])->fillForm(['value' => 'Пролет 27!'])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $a->id, (int) $value->fresh()->picture_media_asset_id, 'untouched = kept');

        Livewire::test(EditAttributeValue::class, ['record' => $value->id])->fillForm(['picture_media_asset_id' => $b->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $b->id, (int) $value->fresh()->picture_media_asset_id);

        Livewire::test(EditAttributeValue::class, ['record' => $value->id])->fillForm(['picture_media_asset_id' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($value->fresh()->picture_media_asset_id);
        $this->assertEqualsCanonicalizing([(int) $a->id, (int) $b->id], array_values(array_intersect($this->unused(), [(int) $a->id, (int) $b->id])), 'both pictures stay in «Медия», unused');
    }

    public function test_a_value_refuses_a_video_a_social_image_and_a_missing_id(): void
    {
        $definition = $this->definition();

        foreach ([$this->pickerVideo()->id, $this->pickerAsset(['type' => 'social_image'])->id, 987654] as $bad) {
            Livewire::test(CreateAttributeValue::class)->fillForm(['attribute_definition_id' => $definition->id(), 'value' => 'Bad', 'sort_order' => 0, 'picture_media_asset_id' => $bad])->call('create')->assertHasFormErrors(['picture_media_asset_id']);
        }

        $this->assertNull(AttributeValueModel::where('value', 'Bad')->first());
    }

    public function test_several_values_keep_their_own_pictures_through_reordering_and_a_removed_value_drops_its_reference(): void
    {
        $definition = $this->definition();
        [$a, $b, $c] = [$this->pickerAsset(), $this->pickerAsset(), $this->pickerAsset()];
        $autumn = $this->value($definition, 'Есен', 1, $a->id);
        $winter = $this->value($definition, 'Зима', 2, $b->id);
        $spring = $this->value($definition, 'Пролет', 3, $c->id);

        // «reorder»: the sort order of two values is swapped through their own edit forms
        Livewire::test(EditAttributeValue::class, ['record' => $autumn->id])->fillForm(['sort_order' => 3])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditAttributeValue::class, ['record' => $spring->id])->fillForm(['sort_order' => 1])->call('save')->assertHasNoFormErrors();

        $this->assertSame(
            ['Есен' => (int) $a->id, 'Зима' => (int) $b->id, 'Пролет' => (int) $c->id],
            AttributeValueModel::query()->orderBy('id')->get()->mapWithKeys(fn ($v): array => [$v->value => (int) $v->picture_media_asset_id])->all(),
            'every picture stays with its value',
        );
        $this->assertSame(3, (int) $autumn->fresh()->sort_order);

        app(AttributeValueRepository::class)->delete((string) $winter->id);

        $this->assertNull(AttributeValueModel::find($winter->id));
        $this->assertNotNull(MediaAssetModel::find($b->id), 'the removed value\'s picture stays in «Медия»');
        $this->assertContains((int) $b->id, $this->unused());
    }

    public function test_one_picture_can_be_the_picture_of_two_values(): void
    {
        $definition = $this->definition();
        $picture = $this->pickerAsset();
        $this->value($definition, 'A', 0, $picture->id);
        $this->value($definition, 'B', 1, $picture->id);

        $this->assertCount(2, (new MediaUsageRegistry)->usagesOf($picture->id));
    }

    public function test_the_value_form_has_the_field_after_the_sort_order_and_the_picker_is_plain_images_only(): void
    {
        $html = Livewire::test(CreateAttributeValue::class)->html();

        $this->assertLessThan(strpos($html, 'Снимка'), strpos($html, 'Позиция') ?: 0, 'after the other fields');
        $this->assertStringContainsString('Добави изображение', $html);

        $field = Livewire::test(CreateAttributeValue::class)->instance()->form->getComponent('picture_media_asset_id');
        $this->assertTrue((new \ReflectionClass($field))->getProperty('plainImagesOnly')->getValue($field));
    }

    public function test_the_deepest_state_path_of_the_value_picker_is_two_levels(): void
    {
        $state = Livewire::test(CreateAttributeValue::class)->instance()->form->getComponent('picture_media_asset_id')->getStatePath();

        $this->assertSame('data.picture_media_asset_id', $state);
        $this->assertLessThan(24, count(explode('.', $state)));
    }

    // ================================================================ compact() ================================================================

    public function test_the_compact_picker_renders_empty_and_filled_and_keeps_the_same_state(): void
    {
        $picture = $this->pickerAsset();

        $host = Livewire::test(CompactPickerHost::class);
        $empty = $host->html();
        $this->assertStringContainsString('mpf-compact', $empty);
        $this->assertStringContainsString('mpf-compact-add', $empty, 'the dashed square');
        $this->assertStringContainsString('Добави изображение', $empty, 'its accessible name');
        $this->assertStringNotContainsString('mpf-empty', $empty, 'not the standard dashed box');
        $this->assertStringNotContainsString('Няма избрана снимка', $empty);

        $host->set('data.picture', $picture->id);
        $filled = $host->html();
        $this->assertStringContainsString('mpf-compact-thumb', $filled);
        $this->assertStringContainsString('-thumbnail.webp', $filled, '64×64 from the thumbnail size');
        $this->assertStringContainsString('width="64" height="64"', $filled);
        $this->assertStringContainsString('Смени', $filled);
        $this->assertStringContainsString('Премахни', $filled);
        $this->assertStringNotContainsString('Премахни изображението', $filled, 'the short quiet link');
        $this->assertStringNotContainsString((string) MediaAssetModel::find($picture->id)->original_name, $filled, 'no name');
        $this->assertStringNotContainsString('×', $filled, 'no size');

        $host->call('check')->assertHasNoErrors();
        $this->assertSame((int) $picture->id, (int) $host->get('data.picture'), 'the saved state is the same id');
    }

    public function test_the_compact_picker_validates_exactly_like_the_standard_one(): void
    {
        $host = Livewire::test(CompactPickerHost::class);

        foreach ([$this->pickerVideo()->id, $this->pickerAsset(['type' => 'social_image'])->id, 987654] as $bad) {
            $host->set('data.picture', $bad)->call('check')->assertHasErrors(['data.picture']);
        }
    }

    public function test_compact_is_a_look_only_and_not_for_a_list_or_a_video(): void
    {
        $this->assertFalse(MediaPicker::make('x')->isCompact());
        $this->assertTrue(MediaPicker::make('x')->compact()->isCompact());
        $this->assertFalse(MediaPicker::make('x')->compact()->multiple(3)->isCompact());
        $this->assertFalse(MediaPicker::make('x')->compact()->videos()->isCompact());
    }

    // ================================================================ usage and the delete guard ===============================================

    public function test_both_providers_count_and_label_the_uses_and_link_to_the_edit_pages(): void
    {
        $definition = $this->definition('season', 'Сезон');
        $picture = $this->pickerAsset();
        $category = $this->category('dresses', $picture->id);
        $value = $this->value($definition, 'Есен 26', 0, $picture->id);

        $usages = (new MediaUsageRegistry)->usagesOf($picture->id);
        $labels = array_map(fn ($u): string => $u->label, $usages);

        $this->assertContains('Категория „Dresses“', $labels);
        $this->assertContains('Стойност „Есен 26“ на атрибут „Сезон“', $labels);
        $this->assertSame(2, (new MediaUsageRegistry)->counts([$picture->id])[$picture->id]);

        $byLabel = collect($usages)->mapWithKeys(fn ($u): array => [$u->label => $u->editUrl])->all();
        $this->assertSame(CategoryResource::getUrl('edit', ['record' => $category->id]), $byLabel['Категория „Dresses“']);
        $this->assertStringContainsString('/attribute-definitions/'.$definition->id().'/edit', $byLabel['Стойност „Есен 26“ на атрибут „Сезон“']);
    }

    public function test_a_bulk_usage_query_is_a_fixed_number_of_queries(): void
    {
        $definition = $this->definition();
        $count = function (int $n): int {
            $ids = [];

            for ($i = 0; $i < $n; $i++) {
                $picture = $this->pickerAsset();
                $ids[] = $picture->id;
                $this->category('cat-'.$n.'-'.$i, $picture->id);
                $this->value($this->definition('code-'.$n.'-'.$i, 'A'.$i), 'v'.$i, $i, $picture->id);
            }

            (new MediaUsageRegistry)->counts($ids);   // warm-up: the settings read of the design zones is cached after the first call
            DB::flushQueryLog();
            DB::enableQueryLog();
            $counts = (new MediaUsageRegistry)->counts($ids);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertCount($n, $counts);

            return $queries;
        };

        $this->assertSame($count(2), $count(25), 'one batched query per provider, never one per picture');
    }

    public function test_the_delete_guard_refuses_and_the_unused_filter_skips_a_picture_used_by_a_category_or_a_value(): void
    {
        $definition = $this->definition();
        $byCategory = $this->pickerAsset();
        $byValue = $this->pickerAsset();
        $free = $this->pickerAsset();
        $this->category('dresses', $byCategory->id);
        $this->value($definition, 'Зима', 0, $byValue->id);

        $deleter = app(MediaDeleter::class);
        $this->assertSame('in_use', $deleter->delete($byCategory->id)->status);
        $this->assertSame('in_use', $deleter->delete($byValue->id)->status);
        $this->assertSame(1, $deleter->delete($byCategory->id)->uses, 'the registry\'s own count, not only the foreign key');
        $this->assertNotNull(MediaAssetModel::find($byCategory->id));
        $this->assertNotNull(MediaAssetModel::find($byValue->id));

        $this->assertSame([(int) $free->id], $this->unused(), '«Само неизползвани» skips both');
        $this->assertSame('deleted', $deleter->delete($free->id)->status);
    }

    public function test_the_database_itself_refuses_to_delete_a_used_picture(): void
    {
        $category = $this->pickerAsset();
        $value = $this->pickerAsset();
        $this->category('dresses', $category->id);
        $this->value($this->definition(), 'Лято', 0, $value->id);

        foreach ([$category->id, $value->id] as $id) {
            try {
                DB::table('catalog_media')->where('id', $id)->delete();
                $this->fail('the foreign key must refuse');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame('23000', $e->errorInfo[0]);
                $this->assertSame(1451, (int) $e->errorInfo[1]);
            }
        }
    }

    public function test_the_media_page_counts_the_uses(): void
    {
        $picture = $this->pickerAsset();
        $this->category('dresses', $picture->id);
        $this->value($this->definition(), 'Лято', 0, $picture->id);

        $this->assertSame(2, (new MediaUsageRegistry)->counts([$picture->id])[(int) $picture->id]);
        $this->assertNotContains((int) $picture->id, $this->unused());
    }
}

/** A tiny Livewire host for ONE compact picker, so the option can be rendered and validated on its own. */
class CompactPickerHost extends Component implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, mixed>|null */
    public ?array $data = ['picture' => null];

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([MediaPicker::make('picture')->compact()->plainImagesOnly()]);
    }

    public function check(): void
    {
        $this->form->getState();
    }

    public function render(): string
    {
        return '<div>{{ $this->form }}</div>';
    }
}
