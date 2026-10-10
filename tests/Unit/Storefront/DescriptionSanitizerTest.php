<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Support\DescriptionSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DescriptionSanitizerTest extends TestCase
{
    public function test_allowed_formatting_survives(): void
    {
        $html = '<h2>Title</h2><p>Soft <strong>cotton</strong>, <em>washable</em><br>and <b>light</b> <i>too</i>.</p><ul><li>One</li></ul><ol><li>Two</li></ol><blockquote>Quote</blockquote>'
            .'<table><thead><tr><th>Size</th></tr></thead><tbody><tr><td>M</td></tr></tbody></table>';

        $this->assertSame($html, DescriptionSanitizer::clean($html));
    }

    public function test_links_keep_only_href_gain_rel_and_accept_https_mailto_and_relative_urls(): void
    {
        $out = DescriptionSanitizer::clean('<a href="https://example.com/a?b=1" target="_blank" onclick="x()" class="c">x</a> <a href="mailto:a@b.co">m</a> <a href="/product/x">r</a> <a href="size-guide">s</a>');

        $this->assertStringContainsString('<a href="https://example.com/a?b=1" rel="noopener nofollow">x</a>', $out);
        $this->assertStringContainsString('<a href="mailto:a@b.co" rel="noopener nofollow">m</a>', $out);
        $this->assertStringContainsString('<a href="/product/x" rel="noopener nofollow">r</a>', $out);
        $this->assertStringContainsString('<a href="size-guide" rel="noopener nofollow">s</a>', $out);
        $this->assertStringNotContainsString('target', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('class', $out);
    }

    /** @return array<string, array{0: string}> */
    public static function hostile(): array
    {
        return [
            'script' => ['<script>alert(1)</script>'],
            'script in a paragraph' => ['<p>hi<script>alert(1)</script></p>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg onload=alert(1)><circle/></svg>'],
            'svg script' => ['<svg><script>alert(1)</script></svg>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'object' => ['<object data="x"></object><embed src="x">'],
            'style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'style element' => ['<style>body{display:none}</style>'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>'],
            'javascript href upper' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'javascript href with tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'javascript href with entity tab' => ['<a href="jav&#x09;ascript:alert(1)">x</a>'],
            'javascript href with entity newline' => ['<a href="jav&#10;ascript:alert(1)">x</a>'],
            'javascript href with null' => ["<a href=\"java\0script:alert(1)\">x</a>"],
            'javascript href leading space' => ['<a href="  javascript:alert(1)">x</a>'],
            'data href' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
            'vbscript href' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'protocol relative href' => ['<a href="//evil.example/x">x</a>'],
            'backslash href' => ['<a href="/\\evil.example">x</a>'],
            'nested broken tag' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'event on allowed tag' => ['<p onmouseover="alert(1)">x</p>'],
            'form' => ['<form action="https://evil.example"><input name="x"><button>go</button></form>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'base' => ['<base href="https://evil.example/">'],
            'link' => ['<link rel="stylesheet" href="https://evil.example/x.css">'],
            'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
            'comment with payload' => ['<!--<script>alert(1)</script>--><p>x</p>'],
            'cdata' => ['<![CDATA[<script>alert(1)</script>]]>'],
            'video onerror' => ['<video><source onerror="alert(1)"></video>'],
            'template' => ['<template><script>alert(1)</script></template>'],
            'xmp' => ['<xmp><script>alert(1)</script></xmp>'],
        ];
    }

    #[DataProvider('hostile')]
    public function test_hostile_input_never_produces_active_content(string $payload): void
    {
        $out = DescriptionSanitizer::clean($payload);

        $this->assertDoesNotMatchRegularExpression('/<\s*(script|iframe|frame|object|embed|svg|math|style|form|input|button|meta|link|base|img|video|audio|template)\b/i', $out, $out);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $out, $out);
        $this->assertDoesNotMatchRegularExpression('/style\s*=/i', $out, $out);
        $this->assertDoesNotMatchRegularExpression('/href\s*=\s*["\']?\s*(javascript|data|vbscript|\/\/)/i', $out, $out);
        $this->assertStringNotContainsString('alert(1)</script', $out);
    }

    public function test_text_that_looks_like_markup_stays_escaped_text(): void
    {
        $out = DescriptionSanitizer::clean('<p>1 &lt; 2 &amp; &lt;script&gt;alert(1)&lt;/script&gt;</p>');

        $this->assertSame('<p>1 &lt; 2 &amp; &lt;script&gt;alert(1)&lt;/script&gt;</p>', $out);
    }

    public function test_unknown_wrappers_are_unwrapped_and_their_text_kept(): void
    {
        $this->assertSame('<p>kept</p>', DescriptionSanitizer::clean('<div><span><p>kept</p></span></div>'));
    }

    public function test_empty_null_and_oversized_input_give_an_empty_string(): void
    {
        $this->assertSame('', DescriptionSanitizer::clean(null));
        $this->assertSame('', DescriptionSanitizer::clean('   '));
        $this->assertSame('', DescriptionSanitizer::clean(str_repeat('a', 200_001)));
    }

    public function test_invalid_utf8_does_not_throw(): void
    {
        $this->assertIsString(DescriptionSanitizer::clean("<p>\xFF\xFE broken</p>"));
    }
}
