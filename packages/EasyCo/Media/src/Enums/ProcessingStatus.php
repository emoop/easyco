<?php

namespace EasyCo\Media\Enums;

/**
 * A MediaAsset's image-processing pipeline state — see
 * media-domain-design.md §3/§8/§9. Only meaningful for IMAGE/
 * SOCIAL_IMAGE assets; VIDEO/SOCIAL_VIDEO assets go straight to READY
 * since §4 has no processing pipeline for them at all.
 */
enum ProcessingStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
}
