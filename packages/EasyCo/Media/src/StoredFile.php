<?php

namespace EasyCo\Media;

/**
 * The disk/path pair a MediaStorageAdapter::store() call produced —
 * see media-domain-design.md §5. A plain value object, mirroring
 * MediaVariant's shape.
 */
final class StoredFile
{
    public function __construct(
        public readonly string $disk,
        public readonly string $path,
    ) {
    }
}
