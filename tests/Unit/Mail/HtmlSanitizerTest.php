<?php

namespace Tests\Unit\Mail;

use App\Mail\HtmlSanitizer;
use App\Mail\HtmlToText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private const BASE = 'https://shop.example';

    private function clean(string $html): string
    {
        return HtmlSanitizer::clean($html, self::BASE);
    }

    /** @return array<string, array{string}> */
    public static function attacks(): array
    {
        return [
            'script tag' => ['<p>a</p><script>alert(1)</script>'],
            'script with source' => ['<script src="https://evil.example/x.js"></script>'],
            'javascript link' => ['<a href="javascript:alert(1)">x</a>'],
            'javascript link upper case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'javascript link with tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"],
            'javascript link with newline' => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'javascript link with leading control' => ["<a href=\"\x01javascript:alert(1)\">x</a>"],
            'entity-encoded javascript' => ['<a href="&#106;avascript:alert(1)">x</a>'],
            'hex entity-encoded javascript' => ['<a href="&#x6A;avascript:alert(1)">x</a>'],
            'named-entity colon' => ['<a href="javascript&colon;alert(1)">x</a>'],
            'data uri' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'],
            'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'],
            'event attribute' => ['<p onclick="alert(1)">x</p>'],
            'event attribute on link' => ['<a href="https://ok.example" onmouseover="alert(1)">x</a>'],
            'img onerror' => ['<img src="x" onerror="alert(1)">'],
            'svg onload' => ['<svg onload="alert(1)"><circle/></svg>'],
            'style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'style tag' => ['<style>body{background:url(javascript:alert(1))}</style><p>x</p>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
            'form' => ['<form action="https://evil.example"><input name="p"></form>'],
            'object and embed' => ['<object data="x"></object><embed src="x">'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'base tag' => ['<base href="https://evil.example/"><a href="/x">x</a>'],
            'html comment with script' => ['<!-- <script>alert(1)</script> --><p>x</p>'],
            'conditional comment' => ['<!--[if IE]><script>alert(1)</script><![endif]-->'],
            'cdata' => ['<![CDATA[<script>alert(1)</script>]]>'],
            'protocol relative link' => ['<a href="//evil.example/x">x</a>'],
            'nested broken tags' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'double encoded entity' => ['<a href="&amp;#106;avascript:alert(1)">x</a>'],
            'attribute breakout' => ['<a href="https://ok.example&quot; onclick=&quot;alert(1)">x</a>'],
        ];
    }

    #[DataProvider('attacks')]
    public function test_nothing_active_survives(string $html): void
    {
        $clean = $this->clean($html);
        $lower = strtolower($clean);

        foreach (['<script', '<style', '<iframe', '<form', '<input', '<object', '<embed', '<svg', '<img', '<meta', '<base', 'javascript:', 'vbscript:', 'data:text', ' style=', '<!--', 'cdata', 'evil.example/x.js'] as $needle) {
            $this->assertStringNotContainsString($needle, $lower, "'{$needle}' survived in: {$clean}");
        }

        // No attribute named like an event handler, and no style attribute, anywhere (a look at the parsed result, not the text:
        // the characters "onclick" inside a percent-encoded URL are harmless).
        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><body>'.$clean.'</body>');
        foreach ((new \DOMXPath($document))->query('//@*') as $attribute) {
            $this->assertDoesNotMatchRegularExpression('/^(on|style$)/i', $attribute->nodeName, "attribute {$attribute->nodeName} survived in: {$clean}");
        }

        // No surviving link may point anywhere but https:, mailto: or the shop itself.
        preg_match_all('/href="([^"]*)"/i', $clean, $links);
        foreach ($links[1] as $href) {
            $this->assertMatchesRegularExpression('#^(https:|mailto:)#i', html_entity_decode($href), "href '{$href}' survived in: {$clean}");
        }
    }

    public function test_a_sanitised_output_is_unchanged_by_a_second_pass(): void
    {
        foreach (self::attacks() as [$html]) {
            $once = $this->clean($html);
            $this->assertSame($once, $this->clean($once), $html);
        }
    }

    public function test_allowed_formatting_survives(): void
    {
        $html = '<h2>T</h2><p>Hello <strong>bold</strong> and <em>it</em><br>next</p><ul><li>a</li><li>b</li></ul>'
            .'<table width="100%" border="0" cellpadding="6" cellspacing="0" role="presentation"><thead><tr><th align="left">P</th></tr></thead><tbody><tr><td align="right" colspan="2">1</td></tr></tbody></table>'
            .'<p><a href="https://ok.example/p?a=1&amp;b=2">link</a> <a href="mailto:a@example.com">mail</a></p>';

        $clean = $this->clean($html);

        foreach (['<h2>T</h2>', '<strong>bold</strong>', '<em>it</em>', '<ul><li>a</li><li>b</li></ul>', 'cellpadding="6"', 'align="right"', 'colspan="2"', 'href="https://ok.example/p?a=1&amp;b=2"', 'href="mailto:a@example.com"'] as $needle) {
            $this->assertStringContainsString($needle, $clean);
        }
    }

    public function test_a_root_relative_link_is_expanded_against_the_shop_and_other_relatives_are_dropped(): void
    {
        $clean = $this->clean('<a href="/product/blue-shirt">a</a><a href="product/x">b</a><a href="#top">c</a><a href="//evil.example">d</a>');

        $this->assertStringContainsString('href="https://shop.example/product/blue-shirt"', $clean);
        $this->assertSame(1, substr_count($clean, 'href='));
        $this->assertStringContainsString('>b<', $clean); // the text of a dropped link stays
    }

    public function test_unknown_attributes_and_values_are_dropped(): void
    {
        $clean = $this->clean('<table width="100%" class="x" id="y" data-a="1" border="x" cellpadding="999"><tr><td align="evil" colspan="0">c</td></tr></table>');

        $this->assertStringContainsString('width="100%"', $clean);
        foreach (['class=', 'id=', 'data-a', 'border=', 'cellpadding=', 'align=', 'colspan='] as $needle) {
            $this->assertStringNotContainsString($needle, $clean);
        }
    }

    public function test_non_ascii_text_stays_readable(): void
    {
        $this->assertStringContainsString('Здравейте, Иван', $this->clean('<p>Здравейте, Иван</p>'));
    }

    public function test_the_plain_text_part_is_derived_from_the_clean_html(): void
    {
        $text = HtmlToText::convert('<p>Hello <strong>you</strong></p><p><a href="https://ok.example/p">the shop</a></p><ul><li>one</li><li>two</li></ul><table><tbody><tr><td>A</td><td>1</td></tr></tbody></table>');

        $this->assertStringContainsString("Hello you\n", $text);
        $this->assertStringContainsString('the shop (https://ok.example/p)', $text);
        $this->assertStringContainsString("- one\n- two", $text);
        $this->assertStringContainsString('A  1', $text);
        $this->assertStringNotContainsString('<', $text);
    }
}
