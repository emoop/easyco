<?php

namespace Tests\Feature\Storefront;

use App\Storefront\Reader\ImageSetBuilder;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Tests\TestCase;

class ImageSetBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
    }

    /** @param list<array<string, mixed>> $variants */
    private function asset(array $variants, string $status = 'ready', ?string $alt = null, string $type = 'image'): MediaAssetModel
    {
        $asset = new MediaAssetModel();
        $asset->forceFill(['type' => $type, 'disk' => 'public', 'path' => 'p/x.jpg', 'alt_text' => $alt, 'processing_status' => $status, 'variants' => $variants]);

        return $asset;
    }

    private function variant(string $tier, int $width, int $height): array
    {
        return ['tier' => $tier, 'width' => $width, 'height' => $height, 'quality' => 80, 'path' => "p/x-{$tier}.webp"];
    }

    private function build(MediaAssetModel $asset, string $fallbackAlt = 'Name')
    {
        return app(ImageSetBuilder::class)->build($asset, $fallbackAlt, ImageSetBuilder::SIZES_CARD);
    }

    public function test_the_srcset_uses_the_real_widths_narrowest_first_and_never_the_admin_crop(): void
    {
        $set = $this->build($this->asset([
            $this->variant('large', 1600, 1200), $this->variant('admin_grid', 42, 42), $this->variant('thumbnail', 400, 300), $this->variant('medium', 900, 675),
        ]));

        $this->assertSame([400, 900, 1600], array_column($set->srcset, 'width'));
        $this->assertSame('https://cdn.test/storage/p/x-medium.webp', $set->src);
        $this->assertSame([900, 675], [$set->width, $set->height], 'the dimensions of the src rendition, from its variant row');
        $this->assertStringNotContainsString('admin_grid', json_encode($set->toArray()));
    }

    public function test_a_small_source_advertises_its_true_width_and_is_not_upscaled(): void
    {
        $set = $this->build($this->asset([$this->variant('thumbnail', 320, 240), $this->variant('medium', 320, 240)]));

        $this->assertSame([320], array_column($set->srcset, 'width'), 'two tiers of the same width are one entry');
        $this->assertSame([320, 240], [$set->width, $set->height]);
    }

    public function test_without_a_medium_tier_the_largest_ready_variant_is_the_src(): void
    {
        $set = $this->build($this->asset([$this->variant('thumbnail', 400, 300), $this->variant('large', 1600, 1200)]));

        $this->assertSame('https://cdn.test/storage/p/x-large.webp', $set->src);
        $this->assertSame([1600, 1200], [$set->width, $set->height]);
    }

    public function test_an_asset_that_is_not_ready_has_no_image_even_with_variant_rows(): void
    {
        foreach (['pending', 'processing', 'failed'] as $status) {
            $this->assertNull($this->build($this->asset([$this->variant('medium', 900, 675)], status: $status)), $status);
        }

        $this->assertNull($this->build($this->asset([$this->variant('medium', 900, 675)], type: 'video')));
        $this->assertNull($this->build($this->asset([])), 'ready but no variant');
        $this->assertNull($this->build($this->asset([$this->variant('admin_grid', 42, 42)])), 'only the admin crop');
    }

    public function test_malformed_variant_rows_are_skipped(): void
    {
        $set = $this->build($this->asset([
            ['tier' => 'medium', 'width' => '900', 'height' => 675, 'quality' => 80, 'path' => 'p/a.webp'],
            ['tier' => 'medium', 'width' => 0, 'height' => 10, 'quality' => 80, 'path' => 'p/b.webp'],
            ['tier' => 'medium', 'width' => 10, 'height' => 10, 'quality' => 80, 'path' => ''],
            'garbage',
            $this->variant('thumbnail', 400, 300),
        ]));

        $this->assertSame([400], array_column($set->srcset, 'width'));
    }

    public function test_alt_is_the_asset_alt_text_else_the_given_fallback_raw(): void
    {
        $this->assertSame('A <b>photo</b>', $this->build($this->asset([$this->variant('medium', 900, 675)], alt: 'A <b>photo</b>'))->alt);
        $this->assertSame('Name', $this->build($this->asset([$this->variant('medium', 900, 675)], alt: null))->alt);
        $this->assertSame('Name', $this->build($this->asset([$this->variant('medium', 900, 675)], alt: '   '))->alt);
    }
}
