<?php

namespace Tests\Feature\Media;

use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ML-1a: `media:backfill-meta` is a DRY-RUN unless `--execute`; it fills only the EMPTY columns of old rows from the original (mime by content), sets `missing_at` for a file that is not there
 * and clears it when the file comes back, and a second run changes nothing.
 */
class BackfillMediaMetaCommandTest extends TestCase
{
    use BuildsImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function row(string $path, ?string $bytes, string $type = 'image', array $extra = []): MediaAssetModel
    {
        $bytes !== null && Storage::disk('public')->put($path, $bytes);

        return MediaAssetModel::query()->create(['type' => $type, 'disk' => 'public', 'path' => $path, 'processing_status' => 'ready', 'variants' => []] + $extra);
    }

    public function test_the_dry_run_reports_and_changes_nothing(): void
    {
        $row = $this->row('uploads/2026/10/a.jpg', $this->jpeg());
        $gone = $this->row('uploads/2026/10/gone.jpg', null);

        $this->artisan('media:backfill-meta')
            ->expectsOutputToContain('Проба (нищо не се променя)')
            ->expectsOutputToContain('прегледани редове: 2')
            ->expectsOutputToContain('ще бъдат допълнени: 2')
            ->expectsOutputToContain('файлът липсва (отбелязани): 1, от тях нови: 1')
            ->assertSuccessful();

        $row->refresh();
        $this->assertNull($row->original_name);
        $this->assertNull($row->mime);
        $this->assertNull($gone->refresh()->missing_at);
    }

    public function test_execute_fills_the_empty_columns_from_the_file_and_not_from_the_extension(): void
    {
        $png = $this->png();
        $row = $this->row('uploads/2026/10/disguised.jpg', $png);

        $this->artisan('media:backfill-meta --execute')->expectsOutputToContain('допълнени: 1')->assertSuccessful();

        $row->refresh();
        $this->assertSame('disguised.jpg', $row->original_name, 'the stored file name when the client name is unknown');
        $this->assertSame('image/png', $row->mime, 'mime by content');
        $this->assertSame(strlen($png), (int) $row->size_bytes);
        $this->assertSame([40, 30], [(int) $row->width, (int) $row->height]);
        $this->assertNull($row->missing_at);
    }

    public function test_it_never_overwrites_a_column_that_is_filled(): void
    {
        $row = $this->row('uploads/2026/10/a.jpg', $this->jpeg(), extra: ['original_name' => 'IMG_0042.JPG', 'uploaded_by' => 7]);

        $this->artisan('media:backfill-meta --execute')->assertSuccessful();

        $row->refresh();
        $this->assertSame('IMG_0042.JPG', $row->original_name);
        $this->assertSame(7, (int) $row->uploaded_by);
        $this->assertSame('image/jpeg', $row->mime);
    }

    public function test_a_missing_file_is_marked_and_the_mark_goes_when_the_file_is_back(): void
    {
        $row = $this->row('uploads/2026/10/gone.jpg', null);

        $this->artisan('media:backfill-meta --execute')->assertSuccessful();
        $row->refresh();
        $this->assertNotNull($row->missing_at);
        $this->assertSame('gone.jpg', $row->original_name);
        $this->assertNull($row->mime);

        Storage::disk('public')->put('uploads/2026/10/gone.jpg', $this->jpeg());

        $this->artisan('media:backfill-meta --execute')->expectsOutputToContain('файлът е намерен отново (отметката е махната): 1')->assertSuccessful();
        $row->refresh();
        $this->assertNull($row->missing_at);
        $this->assertSame('image/jpeg', $row->mime);
        $this->assertSame([40, 30], [(int) $row->width, (int) $row->height]);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->row('uploads/2026/10/a.jpg', $this->jpeg());
        $this->row('uploads/2026/10/gone.jpg', null);
        $this->row('uploads/2026/10/clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom", 'video');

        $this->artisan('media:backfill-meta --execute')->assertSuccessful();
        $after = MediaAssetModel::query()->orderBy('id')->get()->map->getAttributes()->all();

        $this->artisan('media:backfill-meta --execute')->expectsOutputToContain('допълнени: 0')->assertSuccessful();

        $this->assertSame($after, MediaAssetModel::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame('video/mp4', MediaAssetModel::query()->where('type', 'video')->value('mime'));
    }

    public function test_it_works_in_chunks_over_many_rows(): void
    {
        foreach (range(1, 1100) as $i) {
            MediaAssetModel::query()->insert(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/none-{$i}.jpg", 'processing_status' => 'ready', 'variants' => '[]']);
        }

        $this->artisan('media:backfill-meta --execute')->expectsOutputToContain('прегледани редове: 1100')->assertSuccessful();

        $this->assertSame(1100, MediaAssetModel::query()->whereNotNull('missing_at')->whereNotNull('original_name')->count());
    }
}
