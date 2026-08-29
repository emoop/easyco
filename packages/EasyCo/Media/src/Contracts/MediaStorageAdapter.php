<?php

namespace EasyCo\Media\Contracts;

use DateTimeImmutable;
use EasyCo\Media\StoredFile;

/**
 * The infrastructure boundary for writing/reading/deleting media
 * bytes on a configurable disk — see media-domain-design.md §5. The
 * Media domain layer itself never touches Illuminate\Support\Facades\Storage
 * directly; only an implementation of this contract does.
 *
 * store() takes raw content directly, not Laravel's UploadedFile —
 * more generally applicable (a future image-processing pipeline will
 * write processed variants through this same adapter, not just HTTP
 * uploads), and keeps this contract itself free of a Laravel-specific
 * dependency.
 */
interface MediaStorageAdapter
{
    /**
     * $at is optional, for testability (mirrors
     * EloquentPriceResolver's PriceContext->at approach) — the real
     * now() is used only when null.
     */
    public function store(string $content, string $originalFilename, ?string $disk = null, ?DateTimeImmutable $at = null): StoredFile;

    public function url(string $disk, string $path): string;

    public function delete(string $disk, string $path): void;
}
