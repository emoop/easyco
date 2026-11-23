<?php

namespace Tests\Concerns;

use App\Settings\SiteLogoWriter;
use App\Storefront\Header\AppearanceRecompiler;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\Storage;

/** LOGO-1: real PNG / WebP originals made with GD, stored on the fake public disk, with a library row; and the save of the tab without the page. */
trait MakesLogoFixtures
{
    /** A PNG with a transparent background and a coloured block, so alpha can be checked. */
    protected function pngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, (int) ($width / 4), (int) ($height / 4), (int) ($width * 3 / 4), (int) ($height * 3 / 4), (int) imagecolorallocatealpha($image, 200, 30, 30, 0));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function webpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 200));

        ob_start();
        imagewebp($image, null, 80);

        return (string) ob_get_clean();
    }

    /** A library picture whose ORIGINAL is `$bytes` (the row says what a real upload says: mime, size). @param  array<string, mixed>  $override */
    protected function logoAsset(string $bytes, string $mime = 'image/png', array $override = []): MediaAssetModel
    {
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $path = 'uploads/2026/11/'.uniqid('', true).'.'.$ext;
        Storage::disk('public')->put($path, $bytes);
        $size = @getimagesizefromstring($bytes) ?: [0, 0];

        return MediaAssetModel::query()->create($override + [
            'type' => 'image', 'disk' => 'public', 'path' => $path, 'mime' => $mime, 'processing_status' => 'ready', 'variants' => [],
            'original_name' => 'file.'.$ext, 'size_bytes' => strlen($bytes), 'width' => (int) $size[0], 'height' => (int) $size[1],
        ]);
    }

    /**
     * Saves the tab's values the way the page does (prepare, commit, then the same refresh as a «Дизайн» publish). Returns whether anything changed.
     *
     * @param  array<string, mixed>  $values  site_name, site_icon, logo_main, logo_dark
     */
    protected function saveLogoTab(array $values): bool
    {
        $writer = app(SiteLogoWriter::class);
        $changed = $writer->commit($writer->prepare($values + ['site_name' => null, 'site_icon' => null, 'logo_main' => null, 'logo_dark' => null]));
        $changed && app(AppearanceRecompiler::class)->run(app(SiteSettingsRepository::class));
        $this->app->forgetScopedInstances();

        return $changed;
    }

    /** @return list<string> the file names of the generated icons and logos on the public disk */
    protected function generatedFiles(): array
    {
        return array_values(array_map('basename', array_filter(Storage::disk('public')->files('appearance'), static fn (string $f): bool => preg_match(\App\Settings\SiteLogo::FILE, basename($f)) === 1)));
    }
}
