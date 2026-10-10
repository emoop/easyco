<?php

namespace App\Storefront\ReadModels;

/** One step of a breadcrumb trail. The "Home" step is the view's (it is a translated label, not a fact). */
final readonly class Breadcrumb
{
    public function __construct(
        public string $label,
        public string $url,
    ) {
    }

    /** @return array{label: string, url: string} */
    public function toArray(): array
    {
        return ['label' => $this->label, 'url' => $this->url];
    }
}
