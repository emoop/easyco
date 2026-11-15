<?php

namespace App\Storefront\Index;

use App\Storefront\ReadModels\ImageSet;

/** One tile of an index page: the link, the name (plain text, escaped by the view), its picture when it has a usable one, and its letter group. */
final readonly class SubjectTile
{
    public function __construct(
        public int $id,
        public string $name,
        public string $url,
        public ?ImageSet $image,
        /** A collection with no picture at all: the storefront's neutral placeholder. A brand with no logo is a name-only tile. */
        public bool $placeholder,
        public string $letter,
        public string $anchor,
    ) {
    }
}
