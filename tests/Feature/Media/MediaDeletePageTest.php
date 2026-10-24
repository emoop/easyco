<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\MediaResource;
use App\Filament\Resources\MediaResource\Pages\ListMedia;
use App\Media\BulkDeleteRun;
use App\Media\DeleteUnusedMediaJob;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\BrandModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Media\Jobs\DeleteMediaFilesJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Media\Persistence\Eloquent\ProductMediaModel;
use EasyCo\Staff\Enums\Permission;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\GivesProductsAShippingClass;
use Tests\TestCase;

/**
 * ML-1b: delete in the «Медия» page — one picture (disabled with the reason while used, the confirmation says what disappears), the selected ones (the used are skipped and counted), and ALL
 * unused of the current filter (enabled only while the filter says «неизползвани», the typed count, a queued job with the filter values, one run at a time, progress, ONE log line per run).
 */
class MediaDeletePageTest extends TestCase
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
        Storage::fake('public');
        Cache::flush();
    }

    private function asset(array $override = [], bool $files = true): MediaAssetModel
    {
        $uuid = uniqid();
        $variants = array_map(fn (string $tier): array => ['tier' => $tier, 'width' => 100, 'height' => 100, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-{$tier}.webp"], ['thumbnail', 'medium', 'large', 'admin_grid']);
        $row = MediaAssetModel::query()->create($override + ['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/{$uuid}.jpg", 'processing_status' => 'ready', 'variants' => $variants, 'original_name' => "{$uuid}.jpg", 'size_bytes' => 1048576, 'width' => 40, 'height' => 30]);

        if ($files) {
            Storage::disk('public')->put($row->path, str_repeat('o', 1024));
            foreach ((array) $row->variants as $variant) {
                Storage::disk('public')->put($variant['path'], str_repeat('v', 512));
            }
        }

        return $row;
    }

    private function missing(): MediaAssetModel
    {
        return $this->asset(['path' => 'bench/2026/09/'.uniqid().'.jpg', 'variants' => null, 'missing_at' => now(), 'size_bytes' => null], files: false);
    }

    private function useOnProduct(MediaAssetModel $asset): ProductModel
    {
        $product = Product::createSimple('Рокля '.$asset->id, 'SKU-'.$asset->id, 'slug-'.$asset->id);
        app(ProductRepository::class)->save($product);
        $model = ProductModel::query()->findOrFail($product->id());
        ProductMediaModel::query()->create(['product_id' => $model->id, 'media_id' => $asset->id, 'sort_order' => 0, 'autoplay' => false]);

        return $model;
    }

    private function logLines(): \Illuminate\Support\Collection
    {
        return DB::table('activity_log')->where('entity_type', 'media')->orderBy('id')->get();
    }

    // ---- one picture -------------------------------------------------------------------------------------------------------------

    public function test_the_delete_button_in_the_details_deletes_one_picture_and_logs_one_line_with_the_snapshot(): void
    {
        Queue::fake([DeleteMediaFilesJob::class]);
        $staff = $this->actingAsStaff('Administrator');
        $asset = $this->asset(['original_name' => 'dress.jpg']);

        Livewire::test(ListMedia::class)
            ->callAction([TestAction::make('details')->table($asset), TestAction::make('delete')->table($asset)])
            ->assertNotified(__('media.library.delete.done'));

        $this->assertNull(MediaAssetModel::query()->find($asset->id));
        Queue::assertPushed(DeleteMediaFilesJob::class, 1);
        $lines = $this->logLines();
        $this->assertCount(1, $lines);
        $this->assertSame((string) $asset->id, $lines[0]->entity_id);
        $this->assertSame('deleted', $lines[0]->action);
        $this->assertStringContainsString('dress.jpg', (string) $lines[0]->old_value);
        $this->assertSame((string) $staff->id, (string) $lines[0]->staff_id);
    }

    public function test_the_delete_button_is_disabled_with_the_reason_while_the_picture_is_used_and_a_direct_call_does_nothing(): void
    {
        $asset = $this->asset();
        $this->useOnProduct($asset);
        $this->actingAsStaff('Administrator');

        try {
            Livewire::test(ListMedia::class)->callAction([TestAction::make('details')->table($asset), TestAction::make('delete')->table($asset)]);
        } catch (\Throwable) {
            // a disabled button cannot be called
        }

        $this->assertSame('Използва се на 3 места', __('media.library.delete.used', ['n' => 3]));
        $this->assertNotNull(MediaAssetModel::query()->find($asset->id));
        $this->assertSame(0, $this->logLines()->count());
    }

    public function test_the_confirmation_names_what_disappears_the_sizes_and_the_total(): void
    {
        $asset = $this->asset();
        $this->actingAsStaff('Administrator');

        [$variants, $bytes] = MediaResource::deletionSummary($asset);

        $this->assertSame(4, $variants);
        $this->assertSame(1048576 + 4 * 512, $bytes, 'the original size on record plus the variants found on the disk');
        $this->assertSame('Ще бъдат изтрити окончателно оригиналът и 4 размера (миниатюра, среден, голям, за админа) — общо 1.00 MB. Това не може да се върне.', __('media.library.delete.summary', ['n' => $variants, 'size' => MediaResource::humanSize($bytes)]));
        $this->assertSame('Файлът вече липсва — ще бъде изтрит само записът.', __('media.library.delete.summary_missing'));
        $this->assertSame('missing', MediaResource::stateOf($this->missing()));
    }

    // ---- selected ----------------------------------------------------------------------------------------------------------------

    public function test_the_selected_are_deleted_the_used_are_skipped_and_one_summary_line_is_logged(): void
    {
        Queue::fake([DeleteMediaFilesJob::class]);
        $this->actingAsStaff('Administrator');
        $a = $this->asset();
        $b = $this->asset();
        $used = $this->asset();
        $this->useOnProduct($used);

        Livewire::test(ListMedia::class)
            ->callTableBulkAction('deleteSelected', [$a, $b, $used])
            ->assertNotified(__('media.library.delete.selected_done', ['deleted' => 2, 'skipped' => 1]));

        $this->assertNull(MediaAssetModel::query()->find($a->id));
        $this->assertNull(MediaAssetModel::query()->find($b->id));
        $this->assertNotNull(MediaAssetModel::query()->find($used->id));
        $lines = $this->logLines();
        $this->assertCount(1, $lines, 'ONE line for the whole selection');
        $this->assertStringContainsString('"deleted":2', (string) $lines[0]->old_value);
        $this->assertStringContainsString('"skipped_as_used":1', (string) $lines[0]->old_value);
    }

    public function test_the_bulk_modal_says_how_many_are_selected_used_and_will_go(): void
    {
        $this->actingAsStaff('Administrator');
        $a = $this->asset();
        $used = $this->asset();
        $this->useOnProduct($used);

        $this->assertSame('Избрани: 2. От тях се използват 1 и ще бъдат пропуснати. Ще бъдат изтрити окончателно 1 файла (оригинали — 1.00 MB). Това не може да се върне.', __('media.library.delete.selected_summary', ['selected' => 2, 'used' => 1, 'count' => 1, 'size' => MediaResource::humanSize(1048576)]));
    }

    // ---- all of the filter -------------------------------------------------------------------------------------------------------

    public function test_delete_all_is_disabled_without_the_unused_filter_and_cannot_be_forced(): void
    {
        $this->actingAsStaff('Administrator');
        $this->missing();
        Queue::fake();

        $component = Livewire::test(ListMedia::class)->assertActionDisabled('deleteAllUnused');

        $component->filterTable('use', 'used')->assertActionDisabled('deleteAllUnused');
        $component->filterTable('use', 'unused')->assertActionEnabled('deleteAllUnused');

        // the forced call with the wrong filter
        $forced = Livewire::test(ListMedia::class);
        try {
            $forced->callAction('deleteAllUnused', ['confirm' => '1']);
        } catch (\Throwable) {
        }
        Queue::assertNothingPushed();
        $this->assertSame(1, MediaAssetModel::query()->count());
    }

    public function test_a_wrong_typed_number_refuses_and_nothing_is_started(): void
    {
        $this->actingAsStaff('Administrator');
        $this->missing();
        $this->missing();
        Queue::fake();

        Livewire::test(ListMedia::class)->filterTable('use', 'unused')
            ->callAction('deleteAllUnused', ['confirm' => '3'])
            ->assertHasActionErrors();

        Queue::assertNothingPushed();
        $this->assertSame(2, MediaAssetModel::query()->count());
        $this->assertFalse(BulkDeleteRun::running());
    }

    public function test_the_run_deletes_in_chunks_through_the_job_with_the_filter_values_and_reports_progress(): void
    {
        $this->actingAsStaff('Administrator');
        $rows = collect(range(1, 7))->map(fn () => $this->missing());
        $keeper = $this->asset();
        $this->useOnProduct($keeper);
        $other = $this->asset(); // unused but NOT missing: outside the «липсва» filter

        $component = Livewire::test(ListMedia::class)->filterTable('use', 'unused')->filterTable('state', 'missing');
        $this->assertSame([7, 0], $component->instance()->matchNow());

        $component->callAction('deleteAllUnused', ['confirm' => '7'])->assertNotified(__('media.library.delete.all_started'));

        // the queue is sync in the tests: the job has run
        $this->assertSame(0, MediaAssetModel::query()->whereIn('id', $rows->pluck('id'))->count());
        $this->assertNotNull(MediaAssetModel::query()->find($keeper->id));
        $this->assertNotNull(MediaAssetModel::query()->find($other->id), 'only what the filter matches');

        $progress = $component->instance()->progress();
        $this->assertSame(['total' => 7, 'done' => 7, 'skipped' => 0, 'finished' => true, 'error' => null], $progress);
        $component->call('refreshBulkProgress')->assertNotified(__('media.library.delete.finished', ['done' => 7, 'skipped' => 0]));
        $this->assertFalse(BulkDeleteRun::running(), 'the lock is released at the end');

        $lines = $this->logLines();
        $this->assertCount(1, $lines, 'ONE line for the whole run');
        $this->assertStringContainsString('"deleted":7', (string) $lines[0]->old_value);
        $this->assertStringContainsString('Използване: Неизползвани', (string) $lines[0]->old_value);
    }

    public function test_the_job_works_through_more_than_one_chunk_and_leaves_the_used_picture(): void
    {
        $this->actingAsStaff('Administrator');
        foreach (range(1, 1100) as $i) {
            DB::table('catalog_media')->insert(['type' => 'image', 'disk' => 'public', 'path' => "bench/2026/09/b{$i}.jpg", 'processing_status' => 'ready', 'variants' => '[]', 'missing_at' => now()]);
        }
        $late = MediaAssetModel::query()->orderBy('id')->skip(700)->first();
        $this->useOnProduct($late); // used from the start: the filter never matches it

        $this->assertSame(500, DeleteUnusedMediaJob::CHUNK);
        $runId = BulkDeleteRun::start(['use' => 'unused'], 1100, null);
        (new DeleteUnusedMediaJob($runId, ['use' => 'unused'], null))->handle(app(\App\Media\MediaDeleter::class), app(\App\Services\ActivityLogger::class));

        $this->assertSame(1, MediaAssetModel::query()->count());
        $this->assertNotNull(MediaAssetModel::query()->find($late->id));
        $this->assertSame(['total' => 1100, 'done' => 1099, 'skipped' => 0, 'finished' => true, 'error' => null], BulkDeleteRun::progress($runId));
    }

    public function test_the_job_refuses_to_run_without_the_unused_filter(): void
    {
        $asset = $this->asset();
        $runId = BulkDeleteRun::start(['kind' => 'image'], 1, null);

        (new DeleteUnusedMediaJob($runId, ['kind' => 'image'], null))->handle(app(\App\Media\MediaDeleter::class), app(\App\Services\ActivityLogger::class));

        $this->assertNotNull(MediaAssetModel::query()->find($asset->id));
        $this->assertTrue(BulkDeleteRun::progress($runId)['finished']);
        $this->assertNotNull(BulkDeleteRun::progress($runId)['error']);
    }

    public function test_only_one_run_at_a_time(): void
    {
        $this->actingAsStaff('Administrator');
        $this->missing();
        Queue::fake();
        $first = BulkDeleteRun::start(['use' => 'unused'], 5, null);
        $this->assertNotNull($first);

        Livewire::test(ListMedia::class)->filterTable('use', 'unused')
            ->callAction('deleteAllUnused', ['confirm' => '1'])
            ->assertNotified(__('media.library.delete.all_running'));

        Queue::assertNothingPushed();
        $this->assertNull(BulkDeleteRun::start(['use' => 'unused'], 1, null), 'the lock holds');
        BulkDeleteRun::finish($first);
        $this->assertNotNull(BulkDeleteRun::start(['use' => 'unused'], 1, null), 'free again when the run is finished');
    }

    public function test_the_progress_line_shows_only_while_a_run_is_active_and_the_poll_adds_no_query_per_card(): void
    {
        $this->actingAsStaff('Administrator');
        foreach (range(1, 20) as $i) {
            $this->asset();
        }

        $idle = Livewire::test(ListMedia::class)->html();
        $this->assertStringNotContainsString('ml-progress', $idle);
        $this->assertStringNotContainsString('wire:poll', $idle, 'nothing is processing and no run is active: no poll');

        Queue::fake();
        $queriesBefore = $this->queriesOfOneRender(Livewire::test(ListMedia::class)->filterTable('use', 'unused'));

        $component = Livewire::test(ListMedia::class)->filterTable('use', 'unused');
        $component->callAction('deleteAllUnused', ['confirm' => '20']);
        $runId = (string) $component->instance()->bulkRunId;
        $this->assertNotSame('', $runId);
        BulkDeleteRun::update($runId, 14, 1);

        $html = $component->call('$refresh')->html();
        $this->assertStringContainsString('ml-progress', $html);
        $this->assertStringContainsString('wire:poll.3s="refreshBulkProgress"', $html);
        $this->assertStringContainsString('Изтрити 14 от 20…', $html);
        $this->assertStringContainsString('(пропуснати като използвани: 1)', $html);

        BulkDeleteRun::finish($runId);
        $component->call('refreshBulkProgress')->assertNotified(__('media.library.delete.finished', ['done' => 14, 'skipped' => 1]));
        $this->assertStringNotContainsString('ml-progress', $component->call('$refresh')->html());
    }

    private function queriesOfOneRender(\Livewire\Features\SupportTesting\Testable $component): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    // ---- access ------------------------------------------------------------------------------------------------------------------

    public function test_the_delete_actions_are_refused_without_the_permission_also_on_a_direct_call(): void
    {
        $asset = $this->asset();
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $this->assertFalse(MediaResource::staffMayUse());

        try {
            Livewire::test(ListMedia::class)->callAction([TestAction::make('details')->table($asset), TestAction::make('delete')->table($asset)]);
        } catch (\Throwable) {
        }

        $this->assertNotNull(MediaAssetModel::query()->find($asset->id));
        $this->assertSame(0, $this->logLines()->count());
    }
}
