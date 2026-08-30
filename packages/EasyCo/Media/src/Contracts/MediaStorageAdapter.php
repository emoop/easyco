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

    /**
     * Writes to EXACTLY the given path — no UUID generation, unlike
     * store(). Exists for generated variants (media-domain-design.md
     * §3): a variant must live at a path derived from its original
     * (e.g. "{original-without-extension}-{tier}.webp") so it's
     * associated with — and deletable alongside — that original
     * (§3.5's all-or-nothing cleanup on partial processing failure).
     * store()'s UUID generation is exactly the wrong behavior for a
     * variant: it would sever that derived-path relationship.
     */
    public function storeAt(string $content, string $disk, string $path): StoredFile;

    /**
     * Reads back raw content previously written by store()/storeAt() —
     * needed by the processing pipeline to load an asset's original
     * bytes before transforming them. A small, natural extension of
     * the same infrastructure boundary storeAt() already established.
     */
    public function get(string $disk, string $path): string;

    public function url(string $disk, string $path): string;

    public function delete(string $disk, string $path): void;
}
