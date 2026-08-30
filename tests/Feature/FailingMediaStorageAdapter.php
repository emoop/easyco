<?php

namespace Tests\Feature;

use DateTimeImmutable;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\StoredFile;
use RuntimeException;

/**
 * Test-only decorator: delegates every call to the wrapped adapter,
 * except storeAt(), which throws on its Nth call — used to simulate a
 * mid-pipeline storage failure and prove LaravelMediaImageProcessor's
 * §3.5 all-or-nothing cleanup actually runs.
 */
final class FailingMediaStorageAdapter implements MediaStorageAdapter
{
    private int $storeAtCalls = 0;

    public function __construct(
        private readonly MediaStorageAdapter $inner,
        private readonly int $failOnCallNumber,
    ) {
    }

    public function store(string $content, string $originalFilename, ?string $disk = null, ?DateTimeImmutable $at = null): StoredFile
    {
        return $this->inner->store($content, $originalFilename, $disk, $at);
    }

    public function storeAt(string $content, string $disk, string $path): StoredFile
    {
        $this->storeAtCalls++;

        if ($this->storeAtCalls === $this->failOnCallNumber) {
            throw new RuntimeException('Simulated storage failure.');
        }

        return $this->inner->storeAt($content, $disk, $path);
    }

    public function get(string $disk, string $path): string
    {
        return $this->inner->get($disk, $path);
    }

    public function url(string $disk, string $path): string
    {
        return $this->inner->url($disk, $path);
    }

    public function delete(string $disk, string $path): void
    {
        $this->inner->delete($disk, $path);
    }
}
