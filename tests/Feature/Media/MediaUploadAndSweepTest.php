<?php

namespace Tests\Feature\Media;

use App\Filament\Resources\MediaResource\Pages\ListMedia;
use App\Media\MediaUploader;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * ML-1b: upload from the «Медия» page (several files at once, one bad file does not cancel the rest, images get the processing job and videos do not, no minimum size, never SVG) and the
 * `media:sweep-files` command (abandoned files under uploads/ only).
 */
class MediaUploadAndSweepTest extends TestCase
{
    use BuildsImageFixtures;
    use BuildsShippingZones;
    use RefreshDatabase;

    private const MP4 = "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41\x00\x00\x00\x08free";

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app()->setLocale('bg');
        Storage::fake('public');
    }

    private function file(string $name, string $content): UploadedFile
    {
        $file = UploadedFile::fake()->createWithContent($name, $content);

        // a browser reports the kind of the file; the fake one has to be told
        str_ends_with($name, '.mp4') && $file->mimeType('video/mp4');

        return $file;
    }

    // ---- upload ------------------------------------------------------------------------------------------------------------------

    public function test_several_files_are_uploaded_at_once_through_the_same_path_as_every_other_upload(): void
    {
        Queue::fake();
        $staff = $this->actingAsStaff('Administrator');
        $png = $this->png();
        $dirty = $this->jpeg($this->dirtyJpegSegments());

        Livewire::test(ListMedia::class)
            ->callAction('upload', ['files' => [$this->file('Лято 1.jpg', $dirty), $this->file('logo.png', $png), $this->file('clip.mp4', self::MP4.str_repeat("\x05", 200))]])
            ->assertNotified(__('media.library.upload.done', ['count' => 3]));

        $rows = MediaAssetModel::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['Лято 1.jpg', 'logo.png', 'clip.mp4'], $rows->pluck('original_name')->all());
        $this->assertSame([(int) $staff->id], $rows->pluck('uploaded_by')->map(fn ($v) => (int) $v)->unique()->values()->all());
        $this->assertSame(['image', 'image', 'video'], $rows->pluck('type')->all());
        $this->assertStringNotContainsString(self::MARKER, Storage::disk('public')->get($rows[0]->path), 'the metadata strip of ML-0 applies');
        $this->assertSame([40, 30], [(int) $rows[0]->width, (int) $rows[0]->height], 'no minimum dimensions here');
        $this->assertSame('pending', $rows[0]->processing_status);
        $this->assertSame('ready', $rows[2]->processing_status);
        Queue::assertPushed(ProcessMediaAssetJob::class, 2);
    }

    public function test_a_damaged_file_is_reported_by_name_and_the_others_are_kept(): void
    {
        Queue::fake();
        $this->actingAsStaff('Administrator');
        $damaged = substr($this->jpeg($this->dirtyJpegSegments()), 0, -2);

        Livewire::test(ListMedia::class)
            ->callAction('upload', ['files' => [$this->file('good.jpg', $this->jpeg()), $this->file('broken.jpg', $damaged), $this->file('good2.png', $this->png())]])
            ->assertNotified(__('media.library.upload.done', ['count' => 2]));

        $this->assertSame(['good.jpg', 'good2.png'], MediaAssetModel::query()->orderBy('id')->pluck('original_name')->all());
        $this->assertCount(2, Storage::disk('public')->allFiles());
        Queue::assertPushed(ProcessMediaAssetJob::class, 2);
    }

    public function test_the_refusal_names_the_file_and_the_reason(): void
    {
        $this->actingAsStaff('Administrator');
        $damaged = substr($this->jpeg($this->dirtyJpegSegments()), 0, -2);

        $result = app(MediaUploader::class)->upload([['name' => 'broken.jpg', 'content' => $damaged]]);

        $this->assertSame([], $result['stored']);
        $this->assertSame([['name' => 'broken.jpg', 'reason' => __('media.malformed')]], $result['refused']);
    }

    public function test_svg_and_other_kinds_are_refused_whatever_the_name_says(): void
    {
        Queue::fake();
        $this->actingAsStaff('Administrator');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $result = app(MediaUploader::class)->upload([
            ['name' => 'evil.svg', 'content' => $svg],
            ['name' => 'evil.jpg', 'content' => $svg],
            ['name' => 'notes.txt', 'content' => 'plain text'],
            ['name' => 'page.html', 'content' => '<html></html>'],
        ]);

        $this->assertSame([], $result['stored']);
        $this->assertCount(4, $result['refused']);
        $this->assertSame(0, MediaAssetModel::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        $this->actingAsStaff('Administrator');
        config(['services.media.max_image_size_kb' => 1]);

        $result = app(MediaUploader::class)->upload([['name' => 'big.png', 'content' => $this->png().str_repeat('x', 4000)]]);

        $this->assertSame([], $result['stored']);
        $this->assertSame(__('media.library.upload.too_large'), $result['refused'][0]['reason']);
    }

    public function test_more_than_twenty_files_are_not_accepted(): void
    {
        $this->actingAsStaff('Administrator');
        $files = array_map(fn (int $i): UploadedFile => $this->file("f{$i}.png", $this->png()), range(1, 21));

        Livewire::test(ListMedia::class)->callAction('upload', ['files' => $files])->assertHasActionErrors();

        $this->assertSame(0, MediaAssetModel::query()->count());
    }

    public function test_the_upload_is_refused_without_the_permission(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        try {
            Livewire::test(ListMedia::class)->callAction('upload', ['files' => [$this->file('a.png', $this->png())]]);
        } catch (\Throwable) {
            // the page itself is a 403
        }

        $this->assertSame(0, MediaAssetModel::query()->count());
    }

    public function test_the_grid_polls_only_while_a_picture_on_the_page_is_processing(): void
    {
        $this->actingAsStaff('Administrator');
        MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => 'uploads/a.jpg', 'processing_status' => 'ready', 'variants' => [['tier' => 'thumbnail', 'width' => 1, 'height' => 1, 'quality' => 1, 'path' => 'uploads/a-thumbnail.webp']]]);

        $this->assertStringNotContainsString('wire:poll', Livewire::test(ListMedia::class)->html());

        MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => 'uploads/b.jpg', 'processing_status' => 'pending', 'variants' => []]);

        $html = Livewire::test(ListMedia::class)->html();
        $this->assertStringContainsString('wire:poll.3s', $html);
        $this->assertStringContainsString('Обработва се', $html);
    }

    // ---- sweep -------------------------------------------------------------------------------------------------------------------

    private function putFile(string $path, string $content, int $hoursOld): void
    {
        Storage::disk('public')->put($path, $content);
        touch(Storage::disk('public')->path($path), time() - $hoursOld * 3600);
    }

    public function test_the_sweep_lists_only_old_unreferenced_files_under_uploads_and_a_dry_run_deletes_nothing(): void
    {
        $this->putFile('uploads/2026/10/abandoned.jpg', str_repeat('a', 2048), 48);
        $this->putFile('uploads/2026/10/abandoned-thumbnail.webp', str_repeat('a', 1024), 48);
        $this->putFile('uploads/2026/10/fresh.jpg', 'f', 1);
        $this->putFile('uploads/2026/10/live.jpg', 'l', 48);
        $this->putFile('uploads/2026/10/live-thumbnail.webp', 'l', 48);
        $this->putFile('uploads/2026/10/live-medium.webp', 'stray of a live asset', 48);
        $this->putFile('html-blocks/old.jpg', 'h', 48);
        $this->putFile('appearance/tokens-aaaaaaaaaaaa.css', 'body{}', 48);
        $this->putFile('bench/2026/09/x.jpg', 'b', 48);
        MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => 'uploads/2026/10/live.jpg', 'processing_status' => 'ready', 'variants' => [['tier' => 'thumbnail', 'width' => 1, 'height' => 1, 'quality' => 1, 'path' => 'uploads/2026/10/live-thumbnail.webp']]]);
        $before = Storage::disk('public')->allFiles();

        $this->artisan('media:sweep-files')
            ->expectsOutputToContain('Проба (нищо не се трие)')
            ->expectsOutputToContain('изоставени файлове в uploads/ (по-стари от 24 часа): 2, общо 3.0 KB')
            ->expectsOutputToContain('по-нови от 24 часа (не се пипат): 1')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing($before, Storage::disk('public')->allFiles());
    }

    public function test_delete_removes_exactly_those_and_never_anything_outside_uploads(): void
    {
        $this->putFile('uploads/2026/10/abandoned.jpg', 'a', 48);
        $this->putFile('uploads/2026/10/abandoned-thumbnail.webp', 'a', 48);
        $this->putFile('uploads/2026/10/fresh.jpg', 'f', 1);
        $this->putFile('uploads/2026/10/live.jpg', 'l', 48);
        $this->putFile('html-blocks/old.jpg', 'h', 48);
        $this->putFile('appearance/tokens-aaaaaaaaaaaa.css', 'body{}', 48);
        $this->putFile('bench/2026/09/x.jpg', 'b', 48);
        MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => 'uploads/2026/10/live.jpg', 'processing_status' => 'ready', 'variants' => []]);

        $this->artisan('media:sweep-files --delete')->expectsOutputToContain('изтрити: 2')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['uploads/2026/10/fresh.jpg', 'uploads/2026/10/live.jpg', 'html-blocks/old.jpg', 'appearance/tokens-aaaaaaaaaaaa.css', 'bench/2026/09/x.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_the_sweep_reads_the_rows_in_chunks(): void
    {
        foreach (range(1, 2100) as $i) {
            DB::table('catalog_media')->insert(['type' => 'image', 'disk' => 'public', 'path' => "uploads/2026/10/r{$i}.jpg", 'processing_status' => 'ready', 'variants' => '[]']);
        }
        $this->putFile('uploads/2026/10/r2050.jpg', 'x', 48);
        $this->putFile('uploads/2026/10/orphan.jpg', 'x', 48);

        $this->artisan('media:sweep-files --delete')->expectsOutputToContain('изтрити: 1')->assertSuccessful();

        $this->assertSame(['uploads/2026/10/r2050.jpg'], Storage::disk('public')->allFiles());
    }
}
