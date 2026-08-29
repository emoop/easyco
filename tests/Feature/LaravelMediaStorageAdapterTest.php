<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Media\Storage\LaravelMediaStorageAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaravelMediaStorageAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3');
    }

    private function adapter(string $defaultDisk = 'public'): LaravelMediaStorageAdapter
    {
        return new LaravelMediaStorageAdapter($defaultDisk);
    }

    public function test_store_with_explicit_disk_writes_to_that_disk_not_the_default(): void
    {
        $stored = $this->adapter(defaultDisk: 'public')->store('content', 'photo.jpg', disk: 's3');

        $this->assertSame('s3', $stored->disk);
        Storage::disk('s3')->assertExists($stored->path);
        Storage::disk('public')->assertMissing($stored->path);
    }

    public function test_store_with_null_disk_uses_the_injected_default_disk(): void
    {
        $stored = $this->adapter(defaultDisk: 's3')->store('content', 'photo.jpg');

        $this->assertSame('s3', $stored->disk);
        Storage::disk('s3')->assertExists($stored->path);
    }

    public function test_store_builds_a_month_based_path(): void
    {
        $stored = $this->adapter()->store('content', 'photo.jpg', at: new DateTimeImmutable('2026-08-15'));

        $this->assertStringContainsString('uploads/2026/08/', $stored->path);
    }

    public function test_store_preserves_the_original_file_extension(): void
    {
        $stored = $this->adapter()->store('content', 'photo.jpg');

        $this->assertStringEndsWith('.jpg', $stored->path);
    }

    public function test_two_stores_with_the_same_original_filename_produce_different_paths(): void
    {
        $first = $this->adapter()->store('content-a', 'photo.jpg');
        $second = $this->adapter()->store('content-b', 'photo.jpg');

        $this->assertNotSame($first->path, $second->path);
    }

    public function test_stored_content_is_retrievable(): void
    {
        $stored = $this->adapter()->store('the actual file bytes', 'photo.jpg');

        $this->assertSame('the actual file bytes', Storage::disk($stored->disk)->get($stored->path));
    }

    public function test_url_delegates_to_storage_disk_url(): void
    {
        $stored = $this->adapter()->store('content', 'photo.jpg');

        $this->assertSame(
            Storage::disk($stored->disk)->url($stored->path),
            $this->adapter()->url($stored->disk, $stored->path)
        );
    }

    public function test_delete_actually_removes_the_file(): void
    {
        $stored = $this->adapter()->store('content', 'photo.jpg');
        Storage::disk($stored->disk)->assertExists($stored->path);

        $this->adapter()->delete($stored->disk, $stored->path);

        Storage::disk($stored->disk)->assertMissing($stored->path);
    }
}
