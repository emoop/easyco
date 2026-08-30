<?php

namespace EasyCo\Media\Contracts;

use EasyCo\Media\Exceptions\MediaProcessingException;
use EasyCo\Media\MediaVariant;

/**
 * The image-processing pipeline boundary — see media-domain-design.md
 * §3. Framework-agnostic contract; a Laravel/Illuminate\Image-backed
 * implementation lives in EasyCo\Media\Image\LaravelMediaImageProcessor.
 */
interface MediaImageProcessor
{
    /**
     * @return MediaVariant[]
     *
     * @throws MediaProcessingException
     */
    public function generateVariants(string $sourceContent, string $disk, string $originalPath): array;

    public function supportsFormat(string $content): bool;
}
