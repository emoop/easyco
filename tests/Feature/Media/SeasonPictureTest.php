<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\SeasonResource;
use App\Filament\Resources\SeasonResource\Pages\CreateSeason;
use App\Filament\Resources\SeasonResource\Pages\EditSeason;
use App\Filament\Resources\SeasonResource\Pages\ListSeasons;
use App\Livewire\MediaPickerBrowser;
use App\Media\MediaDeleter;
use App\Media\MediaFilters;
use App\Media\Usage\MediaUsageRegistry;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Season;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * CT-1.1: one picture for a season (`catalog_seasons` is its own entity), the mirror of CT-1's category picture: stored, editable with the media picker, shown in «Къде се ползва», protected from
 * deletion. Nothing is shown on the storefront.
 */
class SeasonPictureTest extends TestCase
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

    private function season(string $slug = 'autumn-26', ?int $picture = null): SeasonModel
    {
        $season = new Season(id: null, name: ucfirst($slug), slug: $slug);
        $picture !== null && $season->setPicture((string) $picture);
        app(SeasonRepository::class)->save($season);

        return SeasonModel::findOrFail($season->id());
    }

    /** @return list<int> */
    private function unused(): array
    {
        return MediaFilters::use(MediaAssetModel::query(), 'unused')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_a_season_is_created_with_a_picture_and_the_domain_reads_it_back(): void
    {
        $picture = $this->pickerAsset(['width' => 20, 'height' => 20, 'mime' => 'image/jpeg']);

        Livewire::test(CreateSeason::class)->fillForm(['name' => 'Есен 26', 'slug' => 'esen-26', 'picture_media_asset_id' => $picture->id])->call('create')->assertHasNoFormErrors();

        $model = SeasonModel::where('slug', 'esen-26')->firstOrFail();
        $this->assertSame((int) $picture->id, (int) $model->picture_media_asset_id, 'no minimum size, any picture type');
        $this->assertSame((string) $picture->id, app(SeasonRepository::class)->findById((string) $model->id)->pictureMediaAssetId());
    }

    public function test_a_season_without_a_picture_is_what_it_was(): void
    {
        Livewire::test(CreateSeason::class)->fillForm(['name' => 'Plain', 'slug' => 'plain'])->call('create')->assertHasNoFormErrors();

        $this->assertNull(SeasonModel::where('slug', 'plain')->firstOrFail()->picture_media_asset_id);
    }

    public function test_editing_changes_the_picture_and_a_save_that_does_not_touch_it_keeps_it(): void
    {
        [$a, $b] = [$this->pickerAsset(), $this->pickerAsset()];
        $season = $this->season('autumn-26', $a->id);

        Livewire::test(EditSeason::class, ['record' => $season->id])->assertFormSet(['picture_media_asset_id' => $a->id])->fillForm(['name' => 'Autumn 2'])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $a->id, (int) $season->fresh()->picture_media_asset_id, 'untouched = kept');

        Livewire::test(EditSeason::class, ['record' => $season->id])->fillForm(['picture_media_asset_id' => $b->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame((int) $b->id, (int) $season->fresh()->picture_media_asset_id);
        $this->assertContains((int) $a->id, $this->unused(), 'the replaced picture stays in «Медия», unused');
    }

    public function test_removing_the_picture_only_detaches_it_and_the_asset_becomes_unused(): void
    {
        $picture = $this->pickerAsset();
        $season = $this->season('autumn-26', $picture->id);
        $this->assertNotContains((int) $picture->id, $this->unused());

        Livewire::test(EditSeason::class, ['record' => $season->id])->fillForm(['picture_media_asset_id' => null])->call('save')->assertHasNoFormErrors();

        $this->assertNull($season->fresh()->picture_media_asset_id);
        $this->assertNotNull(MediaAssetModel::find($picture->id), 'the asset stays in «Медия»');
        $this->assertContains((int) $picture->id, $this->unused(), 'and is «неизползвана»');
    }

    public function test_a_video_a_social_image_and_a_missing_id_are_refused(): void
    {
        foreach ([$this->pickerVideo()->id, $this->pickerAsset(['type' => 'social_image'])->id, 987654, 'uploads/2026/10/x.jpg'] as $bad) {
            Livewire::test(CreateSeason::class)->fillForm(['name' => 'Bad', 'slug' => 'bad', 'picture_media_asset_id' => $bad])->call('create')->assertHasFormErrors(['picture_media_asset_id']);
        }

        $this->assertNull(SeasonModel::where('slug', 'bad')->first());
    }

    public function test_the_field_is_plain_images_only_and_its_picker_lists_no_social_image(): void
    {
        $image = $this->pickerAsset();
        $social = $this->pickerAsset(['type' => 'social_image']);
        $field = Livewire::test(CreateSeason::class)->instance()->form->getComponent('picture_media_asset_id');

        $this->assertTrue((new \ReflectionClass($field))->getProperty('plainImagesOnly')->getValue($field));
        $browser = Livewire::test(MediaPickerBrowser::class, ['field' => 'data.picture_media_asset_id', 'plain' => true]);
        $this->assertSame([$image->id], $browser->viewData('rows')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotContains($social->id, $browser->viewData('rows')->pluck('id')->all());
    }

    public function test_one_picture_can_be_the_picture_of_two_seasons(): void
    {
        $picture = $this->pickerAsset();
        $this->season('one', $picture->id);
        $two = $this->season('two');

        Livewire::test(EditSeason::class, ['record' => $two->id])->fillForm(['picture_media_asset_id' => $picture->id])->call('save')->assertHasNoFormErrors();

        $this->assertSame(2, SeasonModel::where('picture_media_asset_id', $picture->id)->count());
        $this->assertCount(2, (new MediaUsageRegistry)->usagesOf($picture->id));
    }

    public function test_the_field_sits_after_the_slug_with_the_help_line_and_the_standard_look(): void
    {
        $html = Livewire::test(CreateSeason::class)->html();

        $this->assertLessThan(strpos($html, 'Снимка'), strpos($html, 'Слъг'), 'after the last existing field');
        $this->assertStringContainsString('Няма избрана снимка', $html);
        $this->assertSame(1, substr_count($html, 'Добави изображение'), 'ONE button');
        $this->assertStringContainsString('Показва се в страницата на сезона. Снимката остава в Медия, ако я махнете оттук.', $html);

        $filled = Livewire::test(EditSeason::class, ['record' => $this->season('autumn-26', $this->pickerAsset()->id)->id])->html();
        $this->assertStringContainsString('Смени', $filled);
        $this->assertStringContainsString('Премахни изображението', $filled);
        $this->assertStringContainsString('-medium.webp', $filled);
    }

    public function test_the_list_shows_a_small_thumbnail_found_in_one_query_whatever_the_number_of_rows(): void
    {
        $queries = function (int $seasons): int {
            SeasonModel::query()->delete();
            MediaAssetModel::query()->delete();

            for ($i = 0; $i < $seasons; $i++) {
                $this->season('s'.$i, $this->pickerAsset()->id);
            }

            Livewire::test(ListSeasons::class)->assertSuccessful();   // warm-up
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListSeasons::class)->assertSuccessful();
            $n = count(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $q): bool => str_contains($q, 'catalog_media')));
            DB::disableQueryLog();

            return $n;
        };

        $this->assertSame($queries(2), $queries(8), 'a fixed number of media queries, not one per row');
        $this->assertSame(1, $queries(3));

        $season = $this->season('shown', $this->pickerAsset()->id);
        $thumbs = SeasonResource::pictureThumbnails();
        $this->assertStringEndsWith('-thumbnail.webp', $thumbs[(int) $season->id], 'the thumbnail size, never the original');
        Storage::disk('public')->put($thumbs[(int) $season->id], 'thumbnail bytes');
        Livewire::test(ListSeasons::class)->assertSeeHtml('-thumbnail.webp"');
    }

    public function test_the_picker_refuses_a_user_without_the_needed_permission(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        Livewire::test(MediaPickerBrowser::class, ['field' => 'data.picture_media_asset_id', 'plain' => true])->assertForbidden();
    }

    public function test_a_season_is_hard_deleted_and_that_frees_its_picture_without_deleting_it(): void
    {
        $picture = $this->pickerAsset();
        $season = $this->season('autumn-26', $picture->id);

        app(SeasonRepository::class)->delete((string) $season->id);

        $this->assertNull(SeasonModel::find($season->id), 'a hard delete: no soft-deleted row is left');
        $this->assertNotNull(MediaAssetModel::find($picture->id));
        $this->assertContains((int) $picture->id, $this->unused());
    }

    // ---- usage and the delete guard ------------------------------------------------------------------------------------------------

    public function test_the_provider_counts_and_labels_the_use_and_links_to_the_edit_page(): void
    {
        $picture = $this->pickerAsset();
        $season = $this->season('autumn-26', $picture->id);

        $usages = (new MediaUsageRegistry)->usagesOf($picture->id);

        $this->assertSame(['Сезон „Autumn-26“'], array_map(fn ($u): string => $u->label, $usages));
        $this->assertSame(SeasonResource::getUrl('edit', ['record' => $season->id]), $usages[0]->editUrl);
        $this->assertSame(1, (new MediaUsageRegistry)->counts([$picture->id])[$picture->id]);
    }

    public function test_a_bulk_usage_query_is_a_fixed_number_of_queries(): void
    {
        $count = function (int $n): int {
            $ids = [];

            for ($i = 0; $i < $n; $i++) {
                $picture = $this->pickerAsset();
                $ids[] = $picture->id;
                $this->season('bulk-'.$n.'-'.$i, $picture->id);
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

    public function test_the_delete_guard_refuses_and_the_unused_filter_skips_a_picture_used_by_a_season(): void
    {
        $used = $this->pickerAsset();
        $free = $this->pickerAsset();
        $this->season('autumn-26', $used->id);

        $deleter = app(MediaDeleter::class);
        $this->assertSame('in_use', $deleter->delete($used->id)->status);
        $this->assertSame(1, $deleter->delete($used->id)->uses, 'the registry\'s own count, not only the foreign key');
        $this->assertNotNull(MediaAssetModel::find($used->id));

        $this->assertSame([(int) $free->id], $this->unused(), '«Само неизползвани» skips it');
        $this->assertSame('deleted', $deleter->delete($free->id)->status);
    }

    public function test_the_database_itself_refuses_to_delete_a_picture_a_season_shows(): void
    {
        $picture = $this->pickerAsset();
        $this->season('autumn-26', $picture->id);

        try {
            DB::table('catalog_media')->where('id', $picture->id)->delete();
            $this->fail('the foreign key must refuse');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0], 'SQLSTATE, never the message (CLAUDE.md rule 3)');
            $this->assertSame(1451, (int) $e->errorInfo[1]);
        }
    }
}
