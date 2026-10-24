<?php

namespace Tests\Feature\Media;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ML-2: `media:import-html-block-files`. A dry-run changes nothing; `--execute` makes each file a block's text references an asset and rewrites the `src` to the asset's `large` size only once the
 * asset is READY (a second run does it for the ones still processing); `--delete-old` (a separate run) removes the imported old files no text uses any more; the whole thing is idempotent.
 */
class ImportHtmlBlockFilesTest extends TestCase
{
    use BuildsImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Queue::fake();
        app()->setLocale('bg');
    }

    private function block(string $title, string $body): HtmlBlockModel
    {
        return HtmlBlockModel::create(['title' => $title, 'body' => $body]);
    }

    private function loose(string $name): string
    {
        Storage::disk('public')->put('html-blocks/'.$name, $this->png());

        return $name;
    }

    /** Pretends the processing job has run for the asset of this original name. */
    private function makeReady(string $originalName): MediaAssetModel
    {
        $asset = MediaAssetModel::query()->where('original_name', $originalName)->firstOrFail();
        $uuid = pathinfo($asset->path, PATHINFO_FILENAME);
        $asset->update(['processing_status' => 'ready', 'variants' => [['tier' => 'large', 'width' => 40, 'height' => 30, 'quality' => 80, 'path' => "uploads/2026/10/{$uuid}-large.webp"]]]);

        return $asset->fresh();
    }

    public function test_the_dry_run_counts_and_changes_nothing(): void
    {
        $this->loose('used.png');
        $this->loose('orphan.png');
        $body = '<p>x</p><img src="/storage/html-blocks/used.png" alt="" width="40" height="30">';
        $this->block('One', $body);

        $this->artisan('media:import-html-block-files')
            ->expectsOutputToContain('Проба (нищо не се променя)')
            ->expectsOutputToContain('файлове в html-blocks/: 2, от тях на които текст се позовава: 1, без позоваване (само в списъка): 1')
            ->expectsOutputToContain('ще бъдат внесени: 1')
            ->assertSuccessful();

        $this->assertSame(0, MediaAssetModel::query()->count());
        $this->assertSame($body, HtmlBlockModel::query()->firstOrFail()->body);
        Queue::assertNothingPushed();
    }

    public function test_execute_makes_an_asset_but_leaves_the_text_alone_until_the_asset_is_ready(): void
    {
        $this->loose('used.png');
        $this->loose('orphan.png');
        $body = '<p>x</p><img src="/storage/html-blocks/used.png" alt="A" width="40" height="30">';
        $this->block('One', $body);

        $this->artisan('media:import-html-block-files --execute')->expectsOutputToContain('внесени сега: 1')->expectsOutputToContain('още се обработват (следва втори пуск): 1')->assertSuccessful();

        $this->assertSame(['used.png'], MediaAssetModel::query()->pluck('original_name')->all(), 'only the referenced file is imported; the orphan is only listed');
        Queue::assertPushed(ProcessMediaAssetJob::class, 1);
        $this->assertSame($body, HtmlBlockModel::query()->firstOrFail()->body, 'still processing: the text is not touched');
        $this->assertTrue(Storage::disk('public')->exists('html-blocks/used.png'), 'the old file stays');
    }

    public function test_the_second_run_rewrites_the_text_when_the_asset_is_ready_and_a_third_changes_nothing(): void
    {
        $this->loose('used.png');
        $this->block('One', '<p>x</p><img src="https://shop.test/storage/html-blocks/used.png" alt="A" width="40" height="30">');
        $this->block('Two', '<img src="/storage/html-blocks/used.png" alt="" width="40" height="30"> and <p>none</p>');
        $this->artisan('media:import-html-block-files --execute')->assertSuccessful();
        $asset = $this->makeReady('used.png');
        $large = '/storage/uploads/2026/10/'.pathinfo($asset->path, PATHINFO_FILENAME).'-large.webp';

        $this->artisan('media:import-html-block-files --execute')->expectsOutputToContain('вече внесени: 1')->expectsOutputToContain('внесени сега: 0')->expectsOutputToContain('пренаписани адреса в текстове: 2')->assertSuccessful();

        foreach (HtmlBlockModel::query()->get() as $block) {
            $this->assertStringContainsString('src="'.$large.'"', $block->body);
            $this->assertStringNotContainsString('html-blocks/used.png', $block->body);
        }
        $this->assertSame(1, MediaAssetModel::query()->count(), 'not imported twice');

        $before = HtmlBlockModel::query()->orderBy('id')->pluck('body')->all();
        $this->artisan('media:import-html-block-files --execute')->expectsOutputToContain('пренаписани адреса в текстове: 0')->assertSuccessful();
        $this->assertSame($before, HtmlBlockModel::query()->orderBy('id')->pluck('body')->all());
        $this->assertSame(1, MediaAssetModel::query()->count());
    }

    public function test_the_imported_picture_is_then_used_inside_a_text_and_protected(): void
    {
        $this->loose('used.png');
        $this->block('One', '<img src="/storage/html-blocks/used.png" alt="" width="40" height="30">');
        $this->artisan('media:import-html-block-files --execute')->assertSuccessful();
        $asset = $this->makeReady('used.png');
        $this->artisan('media:import-html-block-files --execute')->assertSuccessful();

        $this->assertSame('in_use', app(\App\Media\MediaDeleter::class)->delete((int) $asset->id)->status);
    }

    public function test_delete_old_removes_only_imported_files_that_no_text_uses_any_more(): void
    {
        $this->loose('moved.png');
        $this->loose('still.png');
        $this->loose('never-imported.png');
        $this->block('One', '<img src="/storage/html-blocks/moved.png" alt="" width="40" height="30">');
        $this->block('Two', '<img src="/storage/html-blocks/still.png" alt="" width="40" height="30">');
        $this->artisan('media:import-html-block-files --execute')->assertSuccessful();
        $this->makeReady('moved.png');
        $this->artisan('media:import-html-block-files --execute')->assertSuccessful();
        // the text of «Two» was edited by hand and no longer uses the loose file: it is unreferenced and imported
        HtmlBlockModel::query()->where('title', 'Two')->update(['body' => '<p>changed</p>']);

        $this->artisan('media:import-html-block-files --delete-old')->expectsOutputToContain('изтрити (внесени и никой текст не ги ползва): 2')->assertSuccessful();

        $this->assertSame(['html-blocks/never-imported.png'], Storage::disk('public')->allFiles('html-blocks'), 'a file that was never imported is not ours to remove');
        $this->artisan('media:import-html-block-files --delete-old')->expectsOutputToContain('изтрити (внесени и никой текст не ги ползва): 0')->assertSuccessful();
    }

    public function test_a_file_that_is_not_a_picture_is_refused_and_a_text_pointing_at_nothing_is_counted(): void
    {
        Storage::disk('public')->put('html-blocks/fake.png', 'not a picture at all');
        $this->block('One', '<img src="/storage/html-blocks/fake.png" alt=""><img src="/storage/html-blocks/gone.png" alt="">');

        $this->artisan('media:import-html-block-files --execute')->expectsOutputToContain('отказани: 1')->expectsOutputToContain('липсващи (текст сочи файл, който го няма): 1')->assertSuccessful();

        $this->assertSame(0, MediaAssetModel::query()->count());
    }

    public function test_the_remembered_pairs_live_in_a_setting(): void
    {
        $this->loose('used.png');
        $this->block('One', '<img src="/storage/html-blocks/used.png" alt="">');
        $this->artisan('media:import-html-block-files --execute')->assertSuccessful();

        $map = json_decode((string) app(SiteSettingsRepository::class)->get('media.html_block_import'), true);

        $this->assertSame(['used.png'], array_keys($map));
        $this->assertSame((int) MediaAssetModel::query()->value('id'), (int) $map['used.png']);
    }
}
