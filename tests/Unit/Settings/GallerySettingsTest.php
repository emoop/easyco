<?php

namespace Tests\Unit\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\GallerySettings;
use PHPUnit\Framework\TestCase;

/** GL-1: the gallery settings are closed sets with defaults; anything else stored falls back to the default and never fails. */
class GallerySettingsTest extends TestCase
{
    /** @param array<string, string> $stored */
    private function repository(array $stored): SiteSettingsRepository
    {
        return new class($stored) implements SiteSettingsRepository {
            /** @param array<string, string> $stored */
            public function __construct(private array $stored)
            {
            }

            public function get(string $key): ?string
            {
                return $this->stored[$key] ?? null;
            }

            public function getMany(array $keys): array
            {
                return array_combine($keys, array_map(fn (string $key): ?string => $this->stored[$key] ?? null, $keys));
            }

            public function set(string $key, string $value): void
            {
            }

            public function forget(string $key): void
            {
            }
        };
    }

    public function test_nothing_stored_gives_the_defaults(): void
    {
        $settings = GallerySettings::fromRepository($this->repository([]));

        $this->assertSame('grid', $settings->desktopMode);
        $this->assertSame('carousel', $settings->mobileMode);
        $this->assertSame(65, $settings->widthPercent);
        $this->assertSame('same', $settings->oddLast);
        $this->assertSame('light', $settings->hoverZoom);
        $this->assertSame('sf-gal-w-65', $settings->widthClass());
        $this->assertSame(['data-gal-desktop' => 'grid', 'data-gal-mobile' => 'carousel', 'data-gal-odd' => 'same', 'data-gal-zoom' => 'light'], $settings->dataAttributes());
    }

    public function test_every_allowed_value_is_kept_as_stored_strings(): void
    {
        foreach (GallerySettings::DESKTOP_MODES as $mode) {
            $this->assertSame($mode, GallerySettings::fromRepository($this->repository([GallerySettings::DESKTOP_MODE_KEY => $mode]))->desktopMode);
        }

        foreach (GallerySettings::MOBILE_MODES as $mode) {
            $this->assertSame($mode, GallerySettings::fromRepository($this->repository([GallerySettings::MOBILE_MODE_KEY => $mode]))->mobileMode);
        }

        foreach (GallerySettings::WIDTH_PERCENTS as $width) {
            $settings = GallerySettings::fromRepository($this->repository([GallerySettings::WIDTH_PERCENT_KEY => (string) $width]));
            $this->assertSame($width, $settings->widthPercent);
            $this->assertSame('sf-gal-w-'.$width, $settings->widthClass());
        }

        foreach (GallerySettings::ODD_LAST as $odd) {
            $this->assertSame($odd, GallerySettings::fromRepository($this->repository([GallerySettings::ODD_LAST_KEY => $odd]))->oddLast);
        }

        foreach (GallerySettings::HOVER_ZOOMS as $zoom) {
            $this->assertSame($zoom, GallerySettings::fromRepository($this->repository([GallerySettings::HOVER_ZOOM_KEY => $zoom]))->hoverZoom);
        }
    }

    public function test_every_unknown_value_falls_back_to_its_default(): void
    {
        $settings = GallerySettings::fromRepository($this->repository([
            GallerySettings::DESKTOP_MODE_KEY => 'masonry',
            GallerySettings::MOBILE_MODE_KEY => 'grid',
            GallerySettings::WIDTH_PERCENT_KEY => 'wide',
            GallerySettings::ODD_LAST_KEY => '"><b>',
            GallerySettings::HOVER_ZOOM_KEY => 'huge',
        ]));

        $this->assertEquals(new GallerySettings(), $settings);
    }

    public function test_a_width_outside_the_set_is_65(): void
    {
        foreach (['0', '49', '51', '85', '100', '-65', '65.5', '6 5', '065x', '', '9999999999'] as $stored) {
            $this->assertSame(65, GallerySettings::fromRepository($this->repository([GallerySettings::WIDTH_PERCENT_KEY => $stored]))->widthPercent, "stored «{$stored}»");
        }
    }

    public function test_stored_values_are_trimmed(): void
    {
        $settings = GallerySettings::fromRepository($this->repository([GallerySettings::DESKTOP_MODE_KEY => ' thumbs-left ', GallerySettings::WIDTH_PERCENT_KEY => ' 70 ']));

        $this->assertSame('thumbs-left', $settings->desktopMode);
        $this->assertSame(70, $settings->widthPercent);
    }
}
