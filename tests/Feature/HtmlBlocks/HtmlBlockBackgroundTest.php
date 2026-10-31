<?php

namespace Tests\Feature\HtmlBlocks;

use App\Content\HtmlBlocks\HtmlBlockBackground;
use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockStyle;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesPickerAssets;
use Tests\TestCase;

/**
 * HB-4: the background picture of a block's style — the closed choices, the exact compiled rule, the closed shape `isValidRule` accepts (the address inside `url()` is a path of the library's own storage
 * and nothing else), a missing picture compiling WITHOUT its keys, and the token stylesheet carrying the rule (never an inline style).
 */
class HtmlBlockBackgroundTest extends TestCase
{
    use MakesPickerAssets;
    use RefreshDatabase;

    private const PATH = '/storage/uploads/2026/10/banner-large.webp';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Storage::fake('public');
        config(['filesystems.disks.public.url' => 'https://cdn.test/storage']);
    }

    /** @param  array<string, mixed>  $style  @return array<string, mixed> */
    private function clean(array $style): array
    {
        [$clean, $errors] = HtmlBlockStyle::validate($style);
        $this->assertSame([], $errors);

        return $clean;
    }

    // ---- validate -------------------------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: array<string, mixed>, 1: string}> the style, the key that is refused */
    public static function refused(): array
    {
        $picture = ['bg_image' => 5];

        return [
            'a picture id that is text' => [['bg_image' => 'abc'], 'bg_image'],
            'a picture id of zero' => [['bg_image' => 0], 'bg_image'],
            'a negative id' => [['bg_image' => -3], 'bg_image'],
            'a float id' => [['bg_image' => 1.5], 'bg_image'],
            'an id with a leading zero' => [['bg_image' => '05'], 'bg_image'],
            'an id with CSS behind it' => [['bg_image' => '5;color:red'], 'bg_image'],
            'an array as the id' => [['bg_image' => [5]], 'bg_image'],
            'a 600 digit id' => [['bg_image' => str_repeat('9', 600)], 'bg_image'],
            'a size that is stretch' => [$picture + ['bg_size' => 'stretch'], 'bg_size'],
            'a position with two words' => [$picture + ['bg_position' => 'left top'], 'bg_position'],
            'a position in capitals' => [$picture + ['bg_position' => 'CENTER'], 'bg_position'],
            'an overlay of 35' => [$picture + ['overlay' => 35], 'overlay'],
            'an overlay of 35 as text' => [$picture + ['overlay' => '35'], 'overlay'],
            'a negative overlay' => [$picture + ['overlay' => -1], 'overlay'],
            'an overlay of 100' => [$picture + ['overlay' => 100], 'overlay'],
            'an overlay that is a decimal' => [$picture + ['overlay' => '20.0'], 'overlay'],
            'a height of 161' => [$picture + ['min_height' => 161], 'min_height'],
            'a height of 10000' => [$picture + ['min_height' => 10000], 'min_height'],
            'a height with a unit' => [$picture + ['min_height' => '240px'], 'min_height'],
            'a vertical position that is middle' => [$picture + ['valign' => 'middle'], 'valign'],
            'an array as a choice' => [$picture + ['bg_size' => ['cover']], 'bg_size'],
            'a 600 character choice' => [$picture + ['valign' => str_repeat('c', 600)], 'valign'],
            'a declaration as a choice' => [$picture + ['bg_size' => 'cover;color:red'], 'bg_size'],
        ];
    }

    /** @param  array<string, mixed>  $style */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_a_value_outside_a_closed_list_is_refused_under_its_key(array $style, string $key): void
    {
        $this->assertArrayHasKey($key, HtmlBlockStyle::validate($style)[1]);
    }

    public function test_every_member_of_the_closed_lists_is_accepted_and_normalised(): void
    {
        foreach (HtmlBlockStyle::BG_SIZES as $v) {
            $this->assertSame($v, $this->clean(['bg_image' => 5, 'bg_size' => $v])['bg_size']);
        }

        foreach (HtmlBlockStyle::BG_POSITIONS as $v) {
            $this->assertSame($v, $this->clean(['bg_image' => 5, 'bg_position' => $v])['bg_position']);
        }

        foreach (HtmlBlockStyle::VALIGNS as $v) {
            $this->assertSame($v, $this->clean(['bg_image' => 5, 'valign' => $v])['valign']);
        }

        foreach (HtmlBlockStyle::OVERLAYS as $v) {
            $this->assertSame($v, $this->clean(['bg_image' => 5, 'overlay' => (string) $v])['overlay'], 'a select posts text');
            $this->assertSame($v, $this->clean(['bg_image' => 5, 'overlay' => $v])['overlay']);
        }

        foreach (HtmlBlockStyle::MIN_HEIGHTS as $v) {
            $this->assertSame($v, $this->clean(['bg_image' => 5, 'min_height' => (string) $v])['min_height']);
        }

        $this->assertSame(5, $this->clean(['bg_image' => '5'])['bg_image'], 'a numeric text id is the number');
    }

    public function test_the_picture_keys_are_ignored_and_not_stored_without_a_picture_and_only_non_defaults_are_stored_with_one(): void
    {
        $this->assertSame([HtmlBlockStyle::DEFAULTS, []], HtmlBlockStyle::validate(['overlay' => 40, 'min_height' => 240, 'valign' => 'center', 'bg_size' => 'contain']), 'no picture: the rest means nothing, even a bad value');
        $this->assertSame([HtmlBlockStyle::DEFAULTS, []], HtmlBlockStyle::validate(['overlay' => 35, 'bg_size' => 'stretch']));
        $this->assertNull(HtmlBlockStyle::storable(['overlay' => 40, 'min_height' => 240]));

        $this->assertSame(['bg_image' => 5], HtmlBlockStyle::storable(['bg_image' => 5, 'bg_size' => 'cover', 'bg_position' => 'center', 'overlay' => 0, 'min_height' => 0, 'valign' => 'top']), 'the defaults are not stored');
        $this->assertSame(['bg_image' => 5, 'bg_size' => 'contain', 'overlay' => 40, 'min_height' => 240, 'valign' => 'center'], HtmlBlockStyle::storable(['bg_image' => '5', 'bg_size' => 'contain', 'overlay' => '40', 'min_height' => '240', 'valign' => 'center']));
    }

    // ---- the compiled rule ----------------------------------------------------------------------------------------------------------------------

    public function test_the_exact_compiled_rule_for_each_combination(): void
    {
        $url = self::PATH;
        $cases = [
            [['bg_image' => 5], ".sf-hb-9{width:fit-content;max-width:100%;background-image:url({$url});background-size:cover;background-position:center;background-repeat:no-repeat}"],
            [['bg_image' => 5, 'overlay' => 20], ".sf-hb-9{width:fit-content;max-width:100%;background-image:linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.2)),url({$url});background-size:cover;background-position:center;background-repeat:no-repeat}"],
            [['bg_image' => 5, 'overlay' => 40, 'bg_size' => 'contain', 'bg_position' => 'top'], ".sf-hb-9{width:fit-content;max-width:100%;background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url({$url});background-size:contain;background-position:top;background-repeat:no-repeat}"],
            [['bg_image' => 5, 'overlay' => 60, 'min_height' => 160], ".sf-hb-9{width:fit-content;max-width:100%;background-image:linear-gradient(rgba(0,0,0,.6),rgba(0,0,0,.6)),url({$url});background-size:cover;background-position:center;background-repeat:no-repeat;min-height:160px}"],
            [['bg_image' => 5, 'min_height' => 240, 'valign' => 'center'], ".sf-hb-9{width:fit-content;max-width:100%;background-image:url({$url});background-size:cover;background-position:center;background-repeat:no-repeat;min-height:240px;display:flex;flex-direction:column;justify-content:center}"],
            [['bg_image' => 5, 'min_height' => 400, 'valign' => 'bottom', 'bg_position' => 'bottom'], ".sf-hb-9{width:fit-content;max-width:100%;background-image:url({$url});background-size:cover;background-position:bottom;background-repeat:no-repeat;min-height:400px;display:flex;flex-direction:column;justify-content:flex-end}"],
            // a vertical position with no minimum height has nothing to position in: no flex
            [['bg_image' => 5, 'valign' => 'center'], ".sf-hb-9{width:fit-content;max-width:100%;background-image:url({$url});background-size:cover;background-position:center;background-repeat:no-repeat}"],
            // the picture with the older keys: the picture's declarations are APPENDED, HB-3.2's centring margins stay
            [['bg_image' => 5, 'color' => '#ffffff', 'align' => 'center', 'pad_v' => 20, 'pad_h' => 24, 'overlay' => 40, 'min_height' => 240, 'valign' => 'center'], ".sf-hb-9{color:#ffffff;padding:20px 24px;width:fit-content;max-width:100%;margin-left:auto;margin-right:auto;text-align:center;background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url({$url});background-size:cover;background-position:center;background-repeat:no-repeat;min-height:240px;display:flex;flex-direction:column;justify-content:center}"],
            [['bg_image' => 5, 'width_mode' => 'full', 'min_height' => 320], ".sf-hb-9{width:100%;background-image:url({$url});background-size:cover;background-position:center;background-repeat:no-repeat;min-height:320px}"],
        ];

        foreach ($cases as [$style, $expected]) {
            $rule = HtmlBlockStyle::css(9, $style, $url);

            $this->assertSame($expected, $rule);
            $this->assertTrue(HtmlBlockStyle::isValidRule($rule), $rule);
        }
    }

    public function test_a_missing_picture_compiles_without_its_keys_and_a_block_with_nothing_else_has_no_rule(): void
    {
        $style = ['bg_image' => 5, 'overlay' => 40, 'min_height' => 240, 'valign' => 'center', 'color' => '#ffffff'];

        $this->assertSame('.sf-hb-9{color:#ffffff;width:fit-content;max-width:100%}', HtmlBlockStyle::css(9, $style, null), 'the picture is gone: the block is as if it had none');
        $this->assertSame(HtmlBlockStyle::css(9, ['color' => '#ffffff']), HtmlBlockStyle::css(9, $style, null));
        $this->assertSame('', HtmlBlockStyle::css(9, ['bg_image' => 5, 'overlay' => 40], null));
        $this->assertSame('', HtmlBlockStyle::css(9, ['bg_image' => 5], '//evil.example/x.png'), 'an address that is not the library\'s is as good as none');
        $this->assertSame('', HtmlBlockStyle::css(9, ['bg_image' => 5], 'https://evil.example/x.png'));
    }

    /** @return array<string, array{0: string}> what must never be inside `url(...)` */
    public static function badUrls(): array
    {
        return [
            'a closing bracket' => ['/storage/a/b.webp)'], 'another host' => ['//evil.example/x.png'], 'a full url' => ['https://evil.example/storage/x.png'], 'a space' => ['/storage/a b.png'],
            'a parent folder' => ['/storage/../x.png'], 'a declaration' => ['/storage/a/b.png;color:red'], 'javascript' => ['javascript:alert(1)'], 'data' => ['data:image/png;base64,AAAA'],
            'a double quote' => ['/storage/a/"b.png'], 'a single quote' => ["/storage/a/'b.png"], 'a backslash' => ['/storage/a\\b.png'], 'a double slash' => ['/storage/a//b.png'], 'a tab' => ["/storage/a\tb.png"],
            'a newline' => ["/storage/a\nb.png"], 'a svg' => ['/storage/a/b.svg'], 'no extension' => ['/storage/a/b'], 'not under storage' => ['/uploads/a/b.png'], 'storage only' => ['/storage/'], 'a colon' => ['/storage/a:b.png'],
            'an expression' => ['/storage/a/b.png);}body{x:y'], 'a very long path' => ['/storage/'.str_repeat('a', 300).'.png'], 'an empty address' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badUrls')]
    public function test_an_address_that_is_not_a_path_of_the_library_is_refused_by_the_rule_check(string $url): void
    {
        $this->assertFalse(HtmlBlockStyle::isValidRule('.sf-hb-9{background-image:url('.$url.')}'));
        $this->assertFalse(HtmlBlockStyle::isValidRule('.sf-hb-9{background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url('.$url.')}'));
        $this->assertFalse(HtmlBlockBackground::isLibraryPath($url));
    }

    public function test_the_rule_check_accepts_exactly_the_library_shapes_and_nothing_loose(): void
    {
        $url = self::PATH;

        $this->assertTrue(HtmlBlockStyle::isValidRule(".sf-hb-9{background-image:url({$url})}"));
        $this->assertTrue(HtmlBlockStyle::isValidRule('.sf-hb-9{background-image:url(/storage/uploads/2026/10/IMG_0001.JPG)}'));

        foreach ([
            '.sf-hb-9{background-image:linear-gradient(rgba(0,0,0,.5),rgba(0,0,0,.5)),url('.$url.')}', // not one of the three veils
            '.sf-hb-9{background-image:linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.4)),url('.$url.')}', // two different strengths
            '.sf-hb-9{background-image:linear-gradient(red,blue),url('.$url.')}',
            '.sf-hb-9{background-image:url('.$url.'),url('.$url.')}',
            '.sf-hb-9{background-size:stretch}', '.sf-hb-9{background-size:100px}', '.sf-hb-9{background-position:left top}', '.sf-hb-9{background-repeat:repeat}',
            '.sf-hb-9{min-height:161px}', '.sf-hb-9{min-height:100vh}', '.sf-hb-9{display:grid}', '.sf-hb-9{display:flex;flex-direction:row}', '.sf-hb-9{justify-content:space-between}', '.sf-hb-9{position:fixed}',
            '.sf-hb-9{background:url('.$url.')}', '.sf-hb-9{background-image:url('.$url.') !important}', '.sf-hb-9{background-image:image-set(url('.$url.') 1x)}',
        ] as $rule) {
            $this->assertFalse(HtmlBlockStyle::isValidRule($rule), $rule);
        }
    }

    // ---- resolving the picture ------------------------------------------------------------------------------------------------------------------

    public function test_the_picture_is_resolved_from_its_id_to_the_largest_rendition_of_the_library(): void
    {
        $asset = $this->pickerAsset(['variants' => [
            ['tier' => 'thumbnail', 'width' => 300, 'height' => 200, 'quality' => 80, 'path' => 'uploads/2026/10/x-thumbnail.webp'],
            ['tier' => 'large', 'width' => 900, 'height' => 600, 'quality' => 80, 'path' => 'uploads/2026/10/x-large.webp'],
            ['tier' => 'medium', 'width' => 600, 'height' => 400, 'quality' => 80, 'path' => 'uploads/2026/10/x-medium.webp'],
        ]]);

        $this->assertSame('/storage/uploads/2026/10/x-large.webp', HtmlBlockBackground::urlFor($asset->id), 'the largest customer rendition, as a path of the shop');
        $this->assertTrue(HtmlBlockBackground::isPicture($asset->id));
        $this->assertSame([$asset->id => '/storage/uploads/2026/10/x-large.webp'], HtmlBlockBackground::urls([$asset->id, 999999, 0, -4]));
    }

    public function test_a_video_a_missing_id_and_an_asset_still_processing_are_handled(): void
    {
        $video = $this->pickerVideo();
        $this->assertNull(HtmlBlockBackground::urlFor($video->id), 'a video is no picture');
        $this->assertFalse(HtmlBlockBackground::isPicture($video->id));
        $this->assertNull(HtmlBlockBackground::urlFor(424242));
        $this->assertFalse(HtmlBlockBackground::isPicture(424242));

        $processing = $this->pickerAsset(['processing_status' => 'processing', 'variants' => [], 'path' => 'uploads/2026/10/orig-file.jpg']);
        $this->assertSame('/storage/uploads/2026/10/orig-file.jpg', HtmlBlockBackground::urlFor($processing->id), 'until the renditions exist the original file is used');
    }

    // ---- the token stylesheet -------------------------------------------------------------------------------------------------------------------

    private function block(string $title, ?array $style): HtmlBlockModel
    {
        $block = HtmlBlockModel::query()->create(['title' => $title, 'body' => '<p>Sale</p>']);
        $style !== null && DB::table('html_blocks')->where('id', $block->id)->update(['style' => json_encode($style)]);

        return $block;
    }

    /** The compiled stylesheet's text, or null. */
    private function css(): ?string
    {
        $compiled = json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::COMPILED_KEY), true);

        return is_array($compiled) ? Storage::disk('public')->get('appearance/tokens-'.$compiled['hash'].'.css') : null;
    }

    private function hash(): ?string
    {
        return json_decode((string) app(SiteSettingsRepository::class)->get(AppearanceSettings::COMPILED_KEY), true)['hash'] ?? null;
    }

    public function test_the_rule_of_a_block_with_a_picture_is_in_the_token_stylesheet_and_never_inline(): void
    {
        $asset = $this->pickerAsset(['variants' => [['tier' => 'large', 'width' => 900, 'height' => 600, 'quality' => 80, 'path' => 'uploads/2026/10/banner-large.webp']]]);
        $block = $this->block('Banner', ['bg_image' => $asset->id, 'overlay' => 40, 'min_height' => 240, 'valign' => 'center']);

        HtmlBlockStyle::rebuildStylesheet();

        $this->assertStringContainsString(".sf-hb-{$block->id}{width:fit-content;max-width:100%;background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url(/storage/uploads/2026/10/banner-large.webp);background-size:cover;background-position:center;background-repeat:no-repeat;min-height:240px;display:flex;flex-direction:column;justify-content:center}", (string) $this->css());

        $html = HtmlBlockModel::renderMany([(int) $block->id])[(int) $block->id];
        $this->assertSame('<div class="sf-hb sf-hb-'.$block->id.'"><p>Sale</p></div>', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringNotContainsString('banner-large', $html, 'the address is in the stylesheet, not in the markup');
    }

    public function test_changing_only_the_picture_changes_the_stylesheet_and_a_picture_that_is_gone_drops_out_without_an_error(): void
    {
        $a = $this->pickerAsset(['variants' => [['tier' => 'large', 'width' => 900, 'height' => 600, 'quality' => 80, 'path' => 'uploads/2026/10/a-large.webp']]]);
        $b = $this->pickerAsset(['variants' => [['tier' => 'large', 'width' => 900, 'height' => 600, 'quality' => 80, 'path' => 'uploads/2026/10/b-large.webp']]]);
        $block = $this->block('Banner', ['bg_image' => $a->id, 'bg_size' => 'contain']);

        HtmlBlockStyle::rebuildStylesheet();
        $first = $this->hash();
        $this->assertStringContainsString('a-large.webp', (string) $this->css());

        DB::table('html_blocks')->where('id', $block->id)->update(['style' => json_encode(['bg_image' => $b->id, 'bg_size' => 'contain'])]);
        HtmlBlockStyle::rebuildStylesheet();
        $this->assertNotSame($first, $this->hash(), 'only the picture changed');
        $this->assertStringContainsString('b-large.webp', (string) $this->css());

        // the picture is deleted by force: the block compiles without it (here: nothing else is set, so no rule at all)
        DB::table('catalog_media')->where('id', $b->id)->delete();
        HtmlBlockStyle::rebuildStylesheet();
        $this->assertNull($this->css(), 'a block whose only style was a vanished picture has no rule');
        $this->assertSame([], HtmlBlockStyle::storedRules());
    }

    public function test_a_block_with_no_picture_compiles_byte_identically_to_before(): void
    {
        $block = $this->block('Promo', ['bg' => '#2e7d32', 'color' => '#ffffff', 'pad_v' => 6, 'pad_h' => 10, 'radius' => 4, 'align' => 'center']);

        $rules = HtmlBlockStyle::storedRules();

        $this->assertCount(1, $rules);
        $this->assertSame('.sf-hb-'.$block->id.'{background:#2e7d32;color:#ffffff;padding:6px 10px;border-radius:4px;width:fit-content;max-width:100%;margin-left:auto;margin-right:auto;text-align:center}', $rules[0]);
    }

    public function test_the_hundred_rule_cap_is_unchanged(): void
    {
        $asset = $this->pickerAsset(['variants' => [['tier' => 'large', 'width' => 900, 'height' => 600, 'quality' => 80, 'path' => 'uploads/2026/10/c-large.webp']]]);

        for ($i = 1; $i <= 102; $i++) {
            $this->block("Block {$i}", ['bg_image' => $asset->id, 'min_height' => 160]);
        }

        $this->assertCount(HtmlBlockStyle::MAX_STYLED, HtmlBlockStyle::storedRules());
        $this->assertSame(100, HtmlBlockStyle::MAX_STYLED);
    }

    public function test_the_admin_preview_shows_the_picture_in_its_only_inline_style(): void
    {
        $asset = $this->pickerAsset(['variants' => [['tier' => 'large', 'width' => 900, 'height' => 600, 'quality' => 80, 'path' => 'uploads/2026/10/p-large.webp']]]);

        $declarations = HtmlBlockStyle::previewDeclarations(['bg_image' => $asset->id, 'overlay' => '20', 'min_height' => '160']);

        $this->assertStringContainsString('background-image:linear-gradient(rgba(0,0,0,.2),rgba(0,0,0,.2)),url(/storage/uploads/2026/10/p-large.webp)', $declarations);
        $this->assertStringContainsString('min-height:160px', $declarations);
        $this->assertSame('', HtmlBlockStyle::previewDeclarations(['bg_image' => 'x']), 'a bad id shows nothing');
    }
}
