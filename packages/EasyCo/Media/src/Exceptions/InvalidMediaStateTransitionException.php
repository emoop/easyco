<?php

namespace EasyCo\Media\Exceptions;

use EasyCo\Media\Enums\ProcessingStatus;
use RuntimeException;

/**
 * Thrown when application code attempts an invalid MediaAsset
 * processing-status transition — either a transition method
 * (markProcessing()/markReady()/markFailed()) called from a
 * processingStatus that does not allow it (e.g. markReady() before
 * markProcessing()), or any of those methods called at all on a
 * VIDEO/SOCIAL_VIDEO asset, which never goes through the image
 * processing pipeline in the first place (media-domain-design.md §4/§8).
 */
final class InvalidMediaStateTransitionException extends RuntimeException
{
    public static function forAsset(string $assetId, ProcessingStatus $from, string $attemptedTransition): self
    {
        return new self(
            "MediaAsset {$assetId} cannot {$attemptedTransition}() from processingStatus \"{$from->value}\"."
        );
    }
}
