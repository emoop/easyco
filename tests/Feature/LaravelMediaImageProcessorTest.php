<?php

namespace Tests\Feature;

use EasyCo\Media\Exceptions\MediaProcessingException;
use EasyCo\Media\Image\LaravelMediaImageProcessor;
use EasyCo\Media\Storage\LaravelMediaStorageAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LaravelMediaImageProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /** Generates a real, decodable JPEG in memory via GD — no fixture files needed. */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 80, 140, 200));

        ob_start();
        imagejpeg($image);
        $raw = ob_get_clean();
        imagedestroy($image);

        return $raw;
    }

    private function defaultVariantConfig(): array
    {
        return [
            'thumbnail' => ['method' => 'scale', 'max' => 400, 'quality' => 80],
            'medium' => ['method' => 'scale', 'max' => 900, 'quality' => 82],
            'large' => ['method' => 'scale', 'max' => 1600, 'quality' => 85],
            'admin_grid' => ['method' => 'cover', 'width' => 42, 'height' => 42, 'quality' => 80],
        ];
    }

    private function processor(?array $variantConfig = null): LaravelMediaImageProcessor
    {
        return new LaravelMediaImageProcessor(
            new LaravelMediaStorageAdapter('public'),
            $variantConfig ?? $this->defaultVariantConfig(),
        );
    }

    /** @return array<string, \EasyCo\Media\MediaVariant> */
    private function byTier(array $variants): array
    {
        $indexed = [];
        foreach ($variants as $variant) {
            $indexed[$variant->tier] = $variant;
        }

        return $indexed;
    }

    public function test_large_square_source_generates_all_four_tiers(): void
    {
        $variants = $this->processor()->generateVariants($this->jpeg(2000, 2000), 'public', 'products/original.jpg');

        $this->assertCount(4, $variants);
        $this->assertSame(
            ['thumbnail', 'medium', 'large', 'admin_grid'],
            array_map(fn ($v) => $v->tier, $variants)
        );
    }

    public function test_variants_carry_real_dimensions_after_processing_not_the_configured_max(): void
    {
        $byTier = $this->byTier(
            $this->processor()->generateVariants($this->jpeg(2000, 2000), 'public', 'products/original.jpg')
        );

        // 2000x2000 is larger than every scale() bound, so each tier's
        // real output is exactly its configured max — not because the
        // config value was copied verbatim, but because that's the
        // real post-processing size for this particular source.
        $this->assertSame(400, $byTier['thumbnail']->width);
        $this->assertSame(400, $byTier['thumbnail']->height);
        $this->assertSame(900, $byTier['medium']->width);
        $this->assertSame(900, $byTier['medium']->height);
        $this->assertSame(1600, $byTier['large']->width);
        $this->assertSame(1600, $byTier['large']->height);
        $this->assertSame(42, $byTier['admin_grid']->width);
        $this->assertSame(42, $byTier['admin_grid']->height);
    }

    public function test_small_source_does_not_upscale(): void
    {
        $byTier = $this->byTier(
            $this->processor()->generateVariants($this->jpeg(300, 300), 'public', 'products/original.jpg')
        );

        // 300x300 is smaller than every scale() tier's max bound —
        // never-upscale (§3.4) means all three stay exactly 300x300,
        // not stretched up to fill thumbnail/medium/large's configured
        // maximums.
        $this->assertSame(300, $byTier['thumbnail']->width);
        $this->assertSame(300, $byTier['thumbnail']->height);
        $this->assertSame(300, $byTier['medium']->width);
        $this->assertSame(300, $byTier['medium']->height);
        $this->assertSame(300, $byTier['large']->width);
        $this->assertSame(300, $byTier['large']->height);
    }

    public function test_rectangular_source_scale_tiers_preserve_aspect_ratio(): void
    {
        // 3200x1600 (2:1) is large enough that every scale() tier's max
        // bound actually constrains it — large's max is 1600, so the
        // width becomes the limiting dimension and height follows the
        // 2:1 ratio down to 800.
        $byTier = $this->byTier(
            $this->processor()->generateVariants($this->jpeg(3200, 1600), 'public', 'products/original.jpg')
        );

        $this->assertSame(1600, $byTier['large']->width);
        $this->assertSame(800, $byTier['large']->height);
    }

    public function test_admin_grid_is_always_exactly_42x42_even_for_a_rectangular_source(): void
    {
        // Contrast with the scale() tiers above: cover() crops to an
        // exact fixed size regardless of the source's own aspect ratio.
        $byTier = $this->byTier(
            $this->processor()->generateVariants($this->jpeg(3200, 1600), 'public', 'products/original.jpg')
        );

        $this->assertSame(42, $byTier['admin_grid']->width);
        $this->assertSame(42, $byTier['admin_grid']->height);
    }

    public function test_variants_exist_on_disk_at_the_expected_derived_paths(): void
    {
        $this->processor()->generateVariants($this->jpeg(2000, 2000), 'public', 'products/original.jpg');

        foreach (['thumbnail', 'medium', 'large', 'admin_grid'] as $tier) {
            Storage::disk('public')->assertExists("products/original-{$tier}.webp");
        }
    }

    public function test_all_variants_are_valid_webp(): void
    {
        $variants = $this->processor()->generateVariants($this->jpeg(2000, 2000), 'public', 'products/original.jpg');

        foreach ($variants as $variant) {
            $bytes = Storage::disk('public')->get($variant->path);
            $info = getimagesizefromstring($bytes);

            $this->assertNotFalse($info, "Variant {$variant->tier} is not a decodable image.");
            $this->assertSame('image/webp', $info['mime']);
        }
    }

    public function test_partial_failure_deletes_all_variants_and_throws(): void
    {
        // The original itself is written to disk first, exactly as the
        // real upload flow would leave it — so the assertions below can
        // tell "only the variants were deleted" (correct) apart from
        // "everything including the original was wiped" (a real bug).
        $sourceBytes = $this->jpeg(2000, 2000);
        Storage::disk('public')->put('products/original.jpg', $sourceBytes);

        // 4 tiers configured; failing on the 4th storeAt() call means
        // the first 3 (thumbnail/medium/large) really get written to
        // disk before admin_grid's write fails.
        $failingAdapter = new FailingMediaStorageAdapter(new LaravelMediaStorageAdapter('public'), failOnCallNumber: 4);
        $processor = new LaravelMediaImageProcessor($failingAdapter, $this->defaultVariantConfig());

        $this->expectException(MediaProcessingException::class);

        try {
            $processor->generateVariants($sourceBytes, 'public', 'products/original.jpg');
        } finally {
            Storage::disk('public')->assertExists('products/original.jpg');

            foreach (['thumbnail', 'medium', 'large', 'admin_grid'] as $tier) {
                Storage::disk('public')->assertMissing("products/original-{$tier}.webp");
            }
        }
    }
}
