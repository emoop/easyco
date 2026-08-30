<?php

namespace Tests\Feature;

use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Exceptions\MediaProcessingException;

/**
 * Test-only double: returns a canned MediaVariant[] or throws a given
 * MediaProcessingException, without performing any real image
 * decoding — used to test ProcessMediaAssetJob's own transition/
 * persistence logic in isolation from the real image pipeline (which
 * has its own dedicated test, LaravelMediaImageProcessorTest).
 */
final class FakeMediaImageProcessor implements MediaImageProcessor
{
    /** @param \EasyCo\Media\MediaVariant[]|null $variants */
    public function __construct(
        private readonly ?array $variants = null,
        private readonly ?MediaProcessingException $throws = null,
    ) {
    }

    public function generateVariants(string $sourceContent, string $disk, string $originalPath): array
    {
        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->variants ?? [];
    }

    public function supportsFormat(string $content): bool
    {
        return true;
    }
}
