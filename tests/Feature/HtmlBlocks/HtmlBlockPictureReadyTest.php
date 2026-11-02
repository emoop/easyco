<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Media\Contracts\MediaAssetRepository;
use EasyCo\Media\Contracts\MediaImageProcessor;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Events\MediaAssetReady;
use EasyCo\Media\Jobs\ProcessMediaAssetJob;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesPickerAssets;
use Tests\Feature\Media\BuildsImageFixtures;
use Tests\TestCase;

/**
 * HB-4.1: (1) an HTML block's picture height is `min-height:min(Npx,70vw)` — one declaration, no media query, N on a desktop and shrinking with the screen; (2) when a picture's asset becomes READY, the
 * stylesheet is rebuilt for the blocks that use that picture (and only then): a block compiled while its picture was still processing gets the picture; the job dispatches the event only for a READY asset.
 */
class HtmlBlockPictureReadyTest extends TestCase
{
    use BuildsImageFixtures;
    use MakesPickerAssets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Storage::fake('public');
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
    }

    /** A block whose stored style is $style (written as it is stored: the picture need not be ready for this). @param array<string, mixed> $style */
    private function block(string $title, array $style): HtmlBlockModel
    {
        $block = HtmlBlockModel::query()->create(['title' => $title, 'body' => '<p>Sale</p>']);
        DB::table('html_blocks')->where('id', $block->id)->update(['style' => json_encode($style)]);

        return $block;
    }

    /** A picture of the library that is still PROCESSING: its file is stored, it has no variants yet. */
    private function pendingAsset(): MediaAssetModel
    {
        $uuid = uniqid('', true);
        $path = "uploads/2026/11/{$uuid}.jpg";
        Storage::disk('public')->put($path, $this->jpeg());

        return MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => $path, 'processing_status' => 'pending', 'variants' => [], 'original_name' => "{$uuid}.jpg"]);
    }

    /** The rule of one block as the stylesheet is compiled now. */
    private function ruleOf(int $id): string
    {
        foreach (HtmlBlockStyle::storedRules() as $rule) {
            if (str_starts_with($rule, ".sf-hb-{$id}{")) {
                return $rule;
            }
        }

        return '';
    }

    /** The compiled token stylesheet as it is stored (the file the page links). */
    private function storedCss(): string
    {
        $compiled = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::COMPILED_KEY), true);

        return (string) Storage::disk('public')->get('appearance/tokens-'.($compiled['hash'] ?? 'none').'.css');
    }

    // ---- (1) the height ----------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: int}> */
    public static function heights(): array
    {
        return ['160' => [160], '240' => [240], '320' => [320], '400' => [400]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('heights')]
    public function test_the_picture_height_is_min_of_n_pixels_and_70vw_with_no_media_query(int $height): void
    {
        $asset = $this->pickerAsset();
        $block = $this->block('Banner', ['bg_image' => $asset->id, 'min_height' => $height]);

        HtmlBlockStyle::rebuildStylesheet();

        $rule = $this->ruleOf((int) $block->id);
        $this->assertStringContainsString("min-height:min({$height}px,70vw)", $rule);
        $this->assertStringNotContainsString('@media', $rule, 'one declaration for every width: no media query for the height');
        $this->assertStringContainsString("min-height:min({$height}px,70vw)", $this->storedCss(), 'the stored stylesheet has it too');
    }

    public function test_a_block_without_a_height_has_no_min_height_at_all(): void
    {
        $asset = $this->pickerAsset();
        $block = $this->block('Banner', ['bg_image' => $asset->id]);

        HtmlBlockStyle::rebuildStylesheet();

        $this->assertStringNotContainsString('min-height', $this->ruleOf((int) $block->id));
    }

    // ---- (2) the picture that becomes ready --------------------------------------------------------------------------------------------------

    public function test_a_block_compiled_while_its_picture_is_processing_is_recompiled_to_a_rendition_when_the_asset_is_ready(): void
    {
        $asset = $this->pendingAsset();
        $block = $this->block('Banner', ['bg_image' => $asset->id, 'min_height' => 240]);

        // still processing: the picture is the ORIGINAL file (HtmlBlockBackground serves it until the renditions exist)
        HtmlBlockStyle::rebuildStylesheet();
        $before = $this->ruleOf((int) $block->id);
        $this->assertStringContainsString($asset->path, $before, 'processing: the original file');
        $this->assertStringNotContainsString('-large.webp', $before);

        (new ProcessMediaAssetJob((string) $asset->id))->handle(app(MediaAssetRepository::class), app(MediaImageProcessor::class), app(MediaStorageAdapter::class));

        // ready: the rule was recompiled by the event, WITHOUT anyone saving the block: it now points at the large rendition
        $this->assertSame('ready', MediaAssetModel::query()->find($asset->id)->processing_status);
        $after = $this->ruleOf((int) $block->id);
        $this->assertStringContainsString('.webp', $after, 'ready: a rendition (WebP) of the library is in the rule');
        $this->assertStringNotContainsString($asset->path, $after, 'and the original address is gone from it');
        $this->assertStringContainsString('min-height:min(240px,70vw)', $after);
        $this->assertStringContainsString('.webp', $this->storedCss(), 'and in the stored stylesheet');
    }

    public function test_a_block_that_uses_another_picture_is_not_recompiled(): void
    {
        $other = $this->pickerAsset();
        $this->block('Other', ['bg_image' => $other->id]);
        $pending = $this->pendingAsset();

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert into|update) .?site_settings/i', $query->sql) === 1) {
                $writes++;
            }
        });

        event(new MediaAssetReady((string) $pending->id));

        $this->assertSame(0, $writes, 'no block uses this picture: the stylesheet is not rebuilt');
    }

    public function test_a_block_with_no_style_is_not_recompiled_either(): void
    {
        HtmlBlockModel::query()->create(['title' => 'Plain', 'body' => '<p>x</p>']);
        $pending = $this->pendingAsset();

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert into|update) .?site_settings/i', $query->sql) === 1) {
                $writes++;
            }
        });

        event(new MediaAssetReady((string) $pending->id));

        $this->assertSame(0, $writes);
    }

    public function test_the_job_dispatches_the_event_once_for_a_ready_asset_and_never_for_a_failed_one(): void
    {
        Event::fake([MediaAssetReady::class]);

        $asset = $this->pendingAsset();
        (new ProcessMediaAssetJob((string) $asset->id))->handle(app(MediaAssetRepository::class), app(MediaImageProcessor::class), app(MediaStorageAdapter::class));

        Event::assertDispatchedTimes(MediaAssetReady::class, 1);
        Event::assertDispatched(MediaAssetReady::class, fn (MediaAssetReady $e): bool => $e->mediaAssetId === (string) $asset->id);

        // a picture that cannot be read: the asset FAILS, and no event says it is ready
        Event::fake([MediaAssetReady::class]);
        $broken = MediaAssetModel::query()->create(['type' => 'image', 'disk' => 'public', 'path' => 'uploads/2026/11/missing.jpg', 'processing_status' => 'pending', 'variants' => [], 'original_name' => 'missing.jpg']);
        (new ProcessMediaAssetJob((string) $broken->id))->handle(app(MediaAssetRepository::class), app(MediaImageProcessor::class), app(MediaStorageAdapter::class));

        Event::assertNotDispatched(MediaAssetReady::class);
    }

    // ---- the markup ---------------------------------------------------------------------------------------------------------------------------

    public function test_the_markup_has_no_style_attribute_and_is_the_same_for_every_visit(): void
    {
        $asset = $this->pickerAsset();
        $block = $this->block('Banner', ['bg_image' => $asset->id, 'min_height' => 320]);
        HtmlBlockStyle::rebuildStylesheet();

        $html = HtmlBlockModel::renderMany([(int) $block->id])[(int) $block->id];

        $this->assertStringNotContainsString('style=', $html);
        $this->assertSame($html, HtmlBlockModel::renderMany([(int) $block->id])[(int) $block->id]);
    }
}
