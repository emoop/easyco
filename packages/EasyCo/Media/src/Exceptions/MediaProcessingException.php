<?php

namespace EasyCo\Media\Exceptions;

use RuntimeException;

/**
 * Thrown by MediaImageProcessor::generateVariants() — either the
 * source content isn't a supported format (§3.1), or generating one
 * of the configured variant tiers failed partway through (§3.5, in
 * which case any already-generated variants for this asset have
 * already been deleted before this is thrown — no orphaned files).
 */
final class MediaProcessingException extends RuntimeException
{
    public static function unsupportedFormat(): self
    {
        return new self(
            "This image format isn't supported on this server — install ImageMagick, or convert the image first."
        );
    }

    public static function variantGenerationFailed(string $reason): self
    {
        return new self("Failed to generate media variants: {$reason}");
    }
}
