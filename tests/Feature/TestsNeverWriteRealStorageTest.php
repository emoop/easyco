<?php

namespace Tests\Feature;

use EasyCo\Media\Contracts\MediaStorageAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the global Storage::fake() override on Tests\TestCase.
 *
 * Every media write in the app funnels through Storage::disk(...); before
 * the override, any feature test that uploaded a file (UploadedFile::fake()
 * driven merchant flows, Filament resources, etc.) without calling
 * Storage::fake() itself wrote those fake images into the real
 * storage/app/public/uploads folder. These tests pin the fix in place:
 * the disks the override fakes must be rooted under
 * storage/framework/testing, and storing through the real
 * MediaStorageAdapter contract must never add a file to the real uploads
 * folder.
 */
class TestsNeverWriteRealStorageTest extends TestCase
{
    /**
     * The disks Tests\TestCase::setUp() fakes. 'public' is both an explicit
     * disk and the media package's own default_disk (config key
     * 'services.media.default_disk', value 'public') — de-duplicated so the
     * loop asserts each distinct disk once.
     *
     * @return list<string>
     */
    private function fakedDisks(): array
    {
        return array_values(array_unique([
            'public',
            'local',
            (string) config('services.media.default_disk', 'public'),
        ]));
    }

    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /** Recursively counts the files physically sitting in the real uploads folder. */
    private function realUploadsFileCount(): int
    {
        $uploads = storage_path('app/public/uploads');

        if (! is_dir($uploads)) {
            return 0;
        }

        return count(File::allFiles($uploads));
    }

    public function test_every_media_disk_is_faked_under_storage_framework_testing(): void
    {
        $testingRoot = $this->normalize(storage_path('framework/testing'));

        foreach ($this->fakedDisks() as $disk) {
            $root = $this->normalize(Storage::disk($disk)->path(''));

            $this->assertStringStartsWith(
                $testingRoot,
                $root,
                "Disk [{$disk}] root [{$root}] is not under storage/framework/testing — media faked on this disk would leak into the real filesystem."
            );
        }
    }

    public function test_media_storage_adapter_stores_never_touch_the_real_uploads_folder(): void
    {
        $adapter = app(MediaStorageAdapter::class);

        foreach ($this->fakedDisks() as $disk) {
            $before = $this->realUploadsFileCount();

            $stored = $adapter->store('fake image bytes', 'photo.jpg', disk: $disk);

            $this->assertSame($disk, $stored->disk);
            Storage::disk($disk)->assertExists($stored->path);
            $this->assertSame(
                $before,
                $this->realUploadsFileCount(),
                "Storing through the MediaStorageAdapter on disk [{$disk}] added files under storage/app/public/uploads."
            );
        }
    }
}
