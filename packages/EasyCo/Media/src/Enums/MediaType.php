<?php

namespace EasyCo\Media\Enums;

/**
 * See media-domain-design.md §9: the `social_` prefix is a PROVENANCE
 * tag, not a processing distinction. It marks content sourced from a
 * social platform (e.g. a repurposed Instagram post) rather than
 * official product photography/video — mapping onto
 * commerce-knowledge-layer-concept.md §3's CUSTOMER GENERATED
 * provenance category, as opposed to official media, which is closer
 * to a MERCHANT OBSERVATION (or, once captured, a plain FACT).
 * Processing-wise the prefix changes nothing: SOCIAL_IMAGE is
 * processed exactly like IMAGE (§3); SOCIAL_VIDEO gets no processing,
 * exactly like VIDEO (§4). The "social" qualifier is about where the
 * content came from, never how it should be processed.
 */
enum MediaType: string
{
    case IMAGE = 'image';
    case VIDEO = 'video';
    case SOCIAL_IMAGE = 'social_image';
    case SOCIAL_VIDEO = 'social_video';
}
