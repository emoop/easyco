<?php

namespace Tests\Feature;

use EasyCo\Media\Contracts\MediaStorageAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Confirms the container binding itself, not just
 * LaravelMediaStorageAdapter's own behavior in isolation (already
 * covered by LaravelMediaStorageAdapterTest) — MediaServiceProvider
 * must actually inject config's real default_disk ('public', no env
 * override in this test environment), not a value hardcoded in this
 * test or a different default made up in the provider.
 */
class MediaServiceProviderStorageAdapterBindingTest extends TestCase
{
    public function test_storage_adapter_binding_uses_the_configured_default_disk(): void
    {
        Storage::fake('public');

        $stored = app(MediaStorageAdapter::class)->store('content', 'photo.jpg');

        $this->assertSame('public', $stored->disk);
        Storage::disk('public')->assertExists($stored->path);
    }
}
