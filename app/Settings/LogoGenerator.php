<?php

namespace App\Settings;

use GdImage;
use RuntimeException;

/**
 * LOGO-1 (site-logo.md): the derivatives of the site icon and the logos, made with GD (the default driver; Imagick is absent on dev and CI). Pure bytes in, bytes out: no disk, no queue, no process.
 *
 *   icon(): PNG 32, PNG 180 (the iOS touch icon) and a real `favicon.ico` (16, 32, 48). GD and Imagick cannot write ICO, but the format only WRAPS PNG bytes (a 6-byte header, a 16-byte entry per
 *           image), so it is built by hand.
 *   wide(): ONE PNG at most SiteLogo::LOGO_MAX_WIDTH wide, never upscaled, transparency kept.
 *
 * The caller has already refused what GD cannot read and what is too big (SiteLogoWriter), so a source that fails here is a RuntimeException with a plain message.
 */
class LogoGenerator
{
    /** @return array{png32: string, png180: string, ico: string} */
    public function icon(string $source): array
    {
        $image = $this->decode($source);

        try {
            $sizes = [];

            foreach ([16, 32, 48, 180] as $size) {
                $sizes[$size] = $this->png($this->resized($image, $size, $size));
            }
        } finally {
            unset($image);
        }

        return ['png32' => $sizes[32], 'png180' => $sizes[180], 'ico' => self::ico([16 => $sizes[16], 32 => $sizes[32], 48 => $sizes[48]])];
    }

    /** @return array{bytes: string, w: int, h: int} */
    public function wide(string $source): array
    {
        $image = $this->decode($source);
        $w = imagesx($image);
        $h = imagesy($image);
        $width = min($w, SiteLogo::LOGO_MAX_WIDTH);
        $height = max(1, (int) round($h * $width / $w));

        return ['bytes' => $this->png($width === $w ? $this->resized($image, $w, $h) : $this->resized($image, $width, $height)), 'w' => $width, 'h' => $height];
    }

    /**
     * The ICO container: ICONDIR (reserved 0, type 1, count), one 16-byte ICONDIRENTRY per image (width, height, 0 colours, 0, 1 plane, 32 bits, byte length, offset), then the PNG files.
     *
     * @param  array<int, string>  $pngs  size (px, at most 255) => PNG bytes
     */
    public static function ico(array $pngs): string
    {
        $header = pack('vvv', 0, 1, count($pngs));
        $entries = '';
        $data = '';
        $offset = 6 + 16 * count($pngs);

        foreach ($pngs as $size => $png) {
            $entries .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($png), $offset);
            $data .= $png;
            $offset += strlen($png);
        }

        return $header.$entries.$data;
    }

    private function decode(string $source): GdImage
    {
        $image = @imagecreatefromstring($source);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The picture could not be read.');
        }

        return $image;
    }

    private function resized(GdImage $source, int $width, int $height): GdImage
    {
        $target = imagecreatetruecolor($width, $height);

        if ($target === false) {
            throw new RuntimeException('The picture could not be resized.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    private function png(GdImage $image): string
    {
        ob_start();
        $ok = imagepng($image, null, 9);
        $bytes = (string) ob_get_clean();

        if (! $ok || $bytes === '') {
            throw new RuntimeException('The picture could not be saved.');
        }

        return $bytes;
    }
}
