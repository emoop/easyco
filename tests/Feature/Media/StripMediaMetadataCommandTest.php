<?php

namespace Tests\Feature\Media;

use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ML-0: `media:strip-metadata` is a DRY-RUN unless `--execute`; it cleans the originals of image assets in place (same path), verifies every result, skips what fails, is idempotent and
 * never touches a variant or anything outside the asset paths (and, with `--html-blocks`, that one folder).
 */
class StripMediaMetadataCommandTest extends TestCase
{
    use BuildsImageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function asset(string $path, string $bytes, string $type = 'image', string $disk = 'public'): int
    {
        $bytes !== '' && Storage::disk($disk)->put($path, $bytes);

        return (int) MediaAssetModel::query()->create(['type' => $type, 'disk' => $disk, 'path' => $path, 'processing_status' => 'ready', 'variants' => []])->id;
    }

    private function stored(string $path): string
    {
        return (string) Storage::disk('public')->get($path);
    }

    public function test_the_dry_run_reports_and_changes_nothing(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $png = $this->png($this->dirtyPngChunks());
        $this->asset('uploads/2026/10/a.jpg', $dirty);
        $this->asset('uploads/2026/10/b.png', $png);
        $this->asset('uploads/2026/10/clean.jpg', $this->jpeg());
        $this->asset('uploads/2026/10/gone.jpg', '');
        $this->asset('uploads/2026/10/broken.jpg', substr($dirty, 0, 60));
        $this->asset('uploads/2026/10/film.mp4', "\x00\x00\x00\x18ftypmp42".str_repeat("\0", 30), 'video');
        Storage::disk('public')->put('uploads/2026/10/a-thumbnail.webp', $this->webp());

        $this->artisan('media:strip-metadata')
            ->expectsOutputToContain('Проба (нищо не се променя)')
            ->expectsOutputToContain('прегледани файлове: 5')
            ->expectsOutputToContain('ще бъдат почистени: 2')
            ->expectsOutputToContain('вече чисти: 1')
            ->expectsOutputToContain('повредени (не се пипат): 1 [asset #')
            ->expectsOutputToContain('липсващи файлове: 1 [asset #')
            ->assertSuccessful();

        $this->assertSame($dirty, $this->stored('uploads/2026/10/a.jpg'));
        $this->assertSame($png, $this->stored('uploads/2026/10/b.png'));
    }

    public function test_execute_cleans_in_place_keeps_the_path_verifies_and_leaves_variants_and_videos_alone(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $variant = $this->webp();
        $mp4 = "\x00\x00\x00\x18ftypmp42".str_repeat("\1", 30).self::MARKER;
        $this->asset('uploads/2026/10/a.jpg', $dirty);
        $this->asset('uploads/2026/10/b.png', $this->png($this->dirtyPngChunks()));
        $this->asset('uploads/2026/10/c.webp', $this->webp());
        $this->asset('uploads/2026/10/d.gif', $this->gif());
        $this->asset('uploads/2026/10/film.mp4', $mp4, 'video');
        Storage::disk('public')->put('uploads/2026/10/a-thumbnail.webp', $variant);
        Storage::disk('public')->put('appearance/tokens-aaaaaaaaaaaa.css', 'body{}'.self::MARKER);

        $this->artisan('media:strip-metadata --execute --force')
            ->expectsOutputToContain('Направете резервно копие на storage/app/public')
            ->expectsOutputToContain('почистени: 4')
            ->assertSuccessful();

        foreach (['a.jpg', 'b.png', 'c.webp', 'd.gif'] as $name) {
            $bytes = $this->stored('uploads/2026/10/'.$name);
            $this->assertStringNotContainsString(self::MARKER, $bytes, $name);
            $this->assertSame([40, 30], [Image::fromBytes($bytes)->width(), Image::fromBytes($bytes)->height()], $name);
        }

        $this->assertSame($this->fromSos($dirty), $this->fromSos($this->stored('uploads/2026/10/a.jpg')), 'lossless');
        $this->assertSame($variant, $this->stored('uploads/2026/10/a-thumbnail.webp'), 'a variant is never touched');
        $this->assertSame($mp4, $this->stored('uploads/2026/10/film.mp4'), 'a video is never touched');
        $this->assertSame('body{}'.self::MARKER, $this->stored('appearance/tokens-aaaaaaaaaaaa.css'));
        $this->assertSame(['uploads/2026/10/a.jpg', 'uploads/2026/10/b.png', 'uploads/2026/10/c.webp', 'uploads/2026/10/d.gif', 'uploads/2026/10/film.mp4'], MediaAssetModel::query()->orderBy('id')->pluck('path')->all(), 'the paths in the database are the same');
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->asset('uploads/2026/10/a.jpg', $this->jpeg($this->dirtyJpegSegments()));
        $this->artisan('media:strip-metadata --execute --force')->expectsOutputToContain('почистени: 1')->assertSuccessful();
        $after = $this->stored('uploads/2026/10/a.jpg');

        $this->artisan('media:strip-metadata --execute --force')->expectsOutputToContain('почистени: 0')->expectsOutputToContain('вече чисти: 1')->assertSuccessful();

        $this->assertSame($after, $this->stored('uploads/2026/10/a.jpg'));
    }

    public function test_a_turned_original_is_made_upright_and_verified_with_the_swapped_size(): void
    {
        $this->asset('uploads/2026/10/turned.jpg', $this->jpeg([$this->exifApp1(6)]));

        $this->artisan('media:strip-metadata --execute --force')->expectsOutputToContain('обръщане по ориентация (прекодиране): 1')->assertSuccessful();

        $bytes = $this->stored('uploads/2026/10/turned.jpg');
        $this->assertSame([30, 40], [Image::fromBytes($bytes)->width(), Image::fromBytes($bytes)->height()]);
        $this->assertStringNotContainsString('Exif', $bytes);
    }

    public function test_a_file_whose_verification_fails_is_skipped_and_reported(): void
    {
        // a PNG whose chunks are well formed (so it strips) but whose header describes a picture of width 0: it does not decode, so the check refuses to write it
        $idat = $this->pngData($this->png(), 'IDAT');
        $bad = substr($this->png(), 0, 8).$this->chunk('IHDR', pack('NNCCCCC', 0, 30, 8, 2, 0, 0, 0)).$this->chunk('tEXt', "Comment\0".self::MARKER).$this->chunk('IDAT', $idat).$this->chunk('IEND', '');
        $this->asset('uploads/2026/10/bad.png', $bad);

        $this->artisan('media:strip-metadata --execute --force')
            ->expectsOutputToContain('пропуснати, защото проверката не мина: 1 [asset #')
            ->assertFailed();

        $this->assertSame($bad, $this->stored('uploads/2026/10/bad.png'), 'a file that fails the check is left as it was');
    }

    public function test_without_force_it_asks_and_a_no_changes_nothing(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        $this->asset('uploads/2026/10/a.jpg', $dirty);

        $this->artisan('media:strip-metadata --execute')->expectsConfirmation('Да презапиша ли оригиналите без метаданни?', 'no')->assertSuccessful();

        $this->assertSame($dirty, $this->stored('uploads/2026/10/a.jpg'));
    }

    public function test_html_blocks_scans_that_folder_only_when_asked(): void
    {
        $dirty = $this->jpeg($this->dirtyJpegSegments());
        Storage::disk('public')->put('html-blocks/photo.jpg', $dirty);
        Storage::disk('public')->put('html-blocks/notes.txt', 'text'.self::MARKER);
        Storage::disk('public')->put('elsewhere/photo.jpg', $dirty);

        $this->artisan('media:strip-metadata --execute --force')->expectsOutputToContain('прегледани файлове: 0')->assertSuccessful();
        $this->assertSame($dirty, $this->stored('html-blocks/photo.jpg'));

        $this->artisan('media:strip-metadata --html-blocks --execute --force')->expectsOutputToContain('прегледани файлове: 1')->expectsOutputToContain('почистени: 1')->assertSuccessful();

        $this->assertStringNotContainsString(self::MARKER, $this->stored('html-blocks/photo.jpg'));
        $this->assertSame($dirty, $this->stored('elsewhere/photo.jpg'), 'a file outside the folder is not scanned');
        $this->assertSame('text'.self::MARKER, $this->stored('html-blocks/notes.txt'), 'not a picture: not touched');
    }

    public function test_it_never_prints_a_metadata_value(): void
    {
        $this->asset('uploads/2026/10/a.jpg', $this->jpeg($this->dirtyJpegSegments()));

        $this->artisan('media:strip-metadata --execute --force')->doesntExpectOutputToContain(self::MARKER)->assertSuccessful();
        $this->assertSame(0, DB::table('catalog_media')->where('path', 'like', '%'.self::MARKER.'%')->count());
    }

    public function test_a_file_with_only_a_trailer_would_change_and_is_cut_on_execute(): void
    {
        $clean = $this->jpeg();
        $this->asset('uploads/2026/10/phone.jpg', $this->phonePhoto());
        $this->asset('uploads/2026/10/clean.jpg', $clean);

        $this->artisan('media:strip-metadata')->expectsOutputToContain('ще бъдат почистени: 1')->expectsOutputToContain('вече чисти: 1')->assertSuccessful();
        $this->assertStringContainsString(self::MARKER_SECOND, $this->stored('uploads/2026/10/phone.jpg'), 'the dry-run changed nothing');

        $this->artisan('media:strip-metadata --execute --force')->expectsOutputToContain('почистени: 1')->assertSuccessful();

        $bytes = $this->stored('uploads/2026/10/phone.jpg');
        foreach ([self::MARKER, self::MARKER_SECOND, self::MARKER_VIDEO, 'MPF', 'ftyp'] as $leak) {
            $this->assertStringNotContainsString($leak, $bytes, $leak);
        }
        $this->assertSame([40, 30], [Image::fromBytes($bytes)->width(), Image::fromBytes($bytes)->height()]);
    }
}
