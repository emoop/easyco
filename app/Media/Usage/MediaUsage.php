<?php

namespace App\Media\Usage;

/** One place that holds a picture (ML-1a): what it is (`kind`, a stable word of the provider), how to show it to a person (`label`, plain text, escaped where it is printed) and where to change it (`editUrl`, null when there is no page). */
final class MediaUsage
{
    public function __construct(
        public readonly string $kind,
        public readonly string $label,
        public readonly ?string $editUrl = null,
    ) {
    }
}
