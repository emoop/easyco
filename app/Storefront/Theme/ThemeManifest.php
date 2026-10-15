<?php

namespace App\Storefront\Theme;

/**
 * A validated theme manifest (resources/themes/{key}/theme.json), a readonly value. Built only by ThemeRegistry, which has checked every rule.
 *
 * @phpstan-type Header array{rows: list<string>, elements: list<string>}
 */
final class ThemeManifest
{
    /**
     * @param  array<string, string>  $assets
     * @param  array<string, string>  $tokens
     * @param  array{rows: list<string>, elements: list<string>}  $header
     * @param  list<mixed>  $slots
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $version,
        public readonly ?string $extends,
        public readonly ?string $views,
        public readonly array $assets,
        public readonly array $tokens,
        public readonly array $header,
        public readonly array $slots,
        private readonly ?string $folder = null,
    ) {
    }

    /** The absolute folder of this theme's own views, or null when the theme uses resources/views as it is (the default theme). */
    public function viewsPath(): ?string
    {
        return $this->views === null ? null : ($this->folder ?? resource_path('themes/'.$this->key)).'/'.$this->views;
    }
}
