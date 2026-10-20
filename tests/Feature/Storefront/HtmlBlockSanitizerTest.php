<?php

namespace Tests\Feature\Storefront;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\HtmlBlocks\HtmlBlockSanitizer;
use App\Storefront\Support\DescriptionSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MENU-2: the filter of an HTML block. It is the description allow-list (the SAME walker, DescriptionSanitizer extended, not copied) plus pictures: nothing that runs,
 * styles or embeds gets through, our own and https pictures stay with their whole numbers for width and height.
 */
class HtmlBlockSanitizerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> input, a fragment that must NOT survive */
    public static function dangerous(): array
    {
        return [
            'a script' => ['<p>Hi</p><script>alert(1)</script>', 'script'],
            'an inline handler' => ['<p onclick="x()">Hi</p>', 'onclick'],
            'onerror on a picture' => ['<img src="/storage/a.jpg" onerror="x()">', 'onerror'],
            'a style attribute' => ['<p style="color:red">Hi</p>', 'style'],
            'a style element' => ['<style>p{color:red}</style><p>Hi</p>', 'style'],
            'a class' => ['<p class="x">Hi</p>', 'class='],
            'an id' => ['<p id="x">Hi</p>', 'id='],
            'a data attribute' => ['<p data-x="1">Hi</p>', 'data-x'],
            'an iframe' => ['<iframe src="https://example.com"></iframe>', 'iframe'],
            'an svg' => ['<svg onload="x()"><circle/></svg>', 'svg'],
            'a video' => ['<video src="/storage/a.mp4"></video>', 'video'],
            'an object' => ['<object data="/a.swf"></object>', 'object'],
            'a javascript: link' => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
            'a hidden javascript: link' => ['<a href="java&#x09;script:alert(1)">x</a>', 'script:'],
            'a javascript: picture' => ['<img src="javascript:alert(1)">', 'javascript'],
            'a data: picture' => ['<img src="data:image/svg+xml;base64,PHN2Zz4=">', 'data:'],
            'a protocol-relative picture' => ['<img src="//evil.test/a.jpg">', 'evil.test'],
            'an http picture' => ['<img src="http://example.com/a.jpg">', 'example.com'],
            'a picture off our storage path' => ['<img src="/uploads/a.jpg">', 'uploads'],
            'a storage path that climbs out' => ['<img src="/storage/../.env">', '.env'],
            'a srcset' => ['<img src="/storage/a.jpg" srcset="/storage/b.jpg 2x">', 'srcset'],
            'a form' => ['<form action="/x"><input name="a"></form>', 'form'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_nothing_that_runs_styles_or_embeds_survives(string $input, string $fragment): void
    {
        $clean = HtmlBlockSanitizer::clean($input);

        $this->assertStringNotContainsStringIgnoringCase($fragment, $clean);
        $this->assertStringNotContainsStringIgnoringCase($fragment, HtmlBlockSanitizer::render($input));
    }

    public function test_our_own_and_https_pictures_are_kept_with_their_whole_numbers(): void
    {
        $clean = HtmlBlockSanitizer::clean('<figure><a href="/promo"><img src="/storage/html-blocks/a.jpg" alt="A boot" width="300" height="200" class="x"></a><figcaption>Promo</figcaption></figure>'
            .'<img src="https://cdn.example.com/b.png" alt="B" width="12px" height="9999">'
            .'<img src="/storage/c.jpg" width="4000" height="0" alt="'.str_repeat('a', 300).'">');

        $this->assertStringContainsString('<figure><a href="/promo" rel="noopener nofollow"><img src="/storage/html-blocks/a.jpg" alt="A boot" width="300" height="200"></a><figcaption>Promo</figcaption></figure>', $clean);
        $this->assertStringContainsString('<img src="https://cdn.example.com/b.png" alt="B">', $clean, 'a width with a unit and a height over 4000 are dropped, the picture stays');
        $this->assertStringContainsString('width="4000"', $clean, '4000 is allowed');
        $this->assertStringNotContainsString('height="0"', $clean, '0 is not a size');
        $this->assertSame(200, mb_strlen((string) preg_replace('/^.*alt="(a+)".*$/s', '$1', $clean)), 'the alt text is cut at 200');
    }

    public function test_the_allow_list_keeps_what_the_editor_offers(): void
    {
        $html = '<h2>T</h2><h3>S</h3><h4>U</h4><p><strong>b</strong> <em>i</em> <a href="https://example.com">l</a><br></p><ul><li>a</li></ul><ol><li>b</li></ol>';

        $this->assertSame('<h2>T</h2><h3>S</h3><h4>U</h4><p><strong>b</strong> <em>i</em> <a href="https://example.com" rel="noopener nofollow">l</a><br></p><ul><li>a</li></ul><ol><li>b</li></ol>', HtmlBlockSanitizer::clean($html));
    }

    public function test_render_adds_lazy_loading_to_every_picture(): void
    {
        $out = HtmlBlockSanitizer::render('<img src="/storage/a.jpg" alt="a"><p>x &lt;img src=x&gt;</p>');

        $this->assertStringContainsString('<img loading="lazy" decoding="async" src="/storage/a.jpg" alt="a">', $out);
        $this->assertSame(1, substr_count($out, 'loading="lazy"'), 'text that looks like a tag is not touched');
        $this->assertSame('', HtmlBlockSanitizer::render(null));
    }

    public function test_the_description_sanitizer_is_unchanged_and_still_drops_pictures(): void
    {
        $this->assertSame('<p>Hi</p>', DescriptionSanitizer::clean('<p onclick="x" style="y">Hi</p><img src="/storage/a.jpg" alt="x"><script>x</script>'));
        $this->assertSame('<a href="/x" rel="noopener nofollow">l</a>', DescriptionSanitizer::clean('<a href="/x" class="c">l</a>'));
    }

    public function test_a_block_is_cleaned_whoever_saves_it(): void
    {
        $block = HtmlBlockModel::create(['title' => 'T', 'body' => '<p onclick="x()">Hi</p><script>alert(1)</script>']);

        $this->assertSame('<p>Hi</p>', $block->fresh()->body);

        $block->update(['body' => '<img src="javascript:alert(1)"><p style="x">Two</p>']);
        $this->assertSame('<p>Two</p>', $block->fresh()->body);
    }
}
