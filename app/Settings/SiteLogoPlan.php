<?php

namespace App\Settings;

/**
 * LOGO-1: what SiteLogoWriter::prepare() decided: the name, the picture ids, the files generated IN MEMORY (`path on the public disk => bytes`) and the new `logo.compiled` parts. Nothing here touches
 * a disk or a setting; SiteLogoWriter::commit() does.
 */
final class SiteLogoPlan
{
    public ?string $name = null;

    public bool $nameChanged = false;

    /** @var array<string, ?int> kind => the chosen picture id (null = none) */
    public array $ids = ['icon' => null, 'main' => null, 'dark' => null];

    /** @var array<string, bool> kind => was this picture added, replaced or removed? */
    public array $changed = [];

    /** @var array<string, array<string, mixed>|null> kind => the compiled part after the save */
    public array $compiled = ['icon' => null, 'main' => null, 'dark' => null];

    /** @var array<string, string> */
    public array $files = [];

    public function hasChanges(): bool
    {
        return $this->nameChanged || in_array(true, $this->changed, true);
    }

    /** Generates the derivatives of one picture and records its compiled part. */
    public function generate(string $kind, string $source, LogoGenerator $generator): void
    {
        $hash = substr(sha1($kind.'|'.$source), 0, 12);
        $dir = SiteLogo::DIRECTORY;
        $url = static fn (string $file): string => '/storage/'.$dir.'/'.$file;

        if ($kind === 'icon') {
            $icon = $generator->icon($source);
            $this->files["{$dir}/icon-{$hash}-32.png"] = $icon['png32'];
            $this->files["{$dir}/icon-{$hash}-180.png"] = $icon['png180'];
            $this->files["{$dir}/icon-{$hash}.ico"] = $icon['ico'];
            $this->compiled['icon'] = [
                'hash' => $hash,
                'png32' => ['url' => $url("icon-{$hash}-32.png"), 'w' => 32, 'h' => 32],
                'png180' => ['url' => $url("icon-{$hash}-180.png"), 'w' => 180, 'h' => 180],
                'ico' => ['url' => $url("icon-{$hash}.ico")],
            ];

            return;
        }

        $wide = $generator->wide($source);
        $file = $kind === 'dark' ? "logo-{$hash}-dark.png" : "logo-{$hash}.png";
        $this->files["{$dir}/{$file}"] = $wide['bytes'];
        $this->compiled[$kind] = ['hash' => $hash, 'png' => ['url' => $url($file), 'w' => $wide['w'], 'h' => $wide['h']]];
    }
}
