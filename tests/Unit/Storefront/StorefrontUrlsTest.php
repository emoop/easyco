<?php

namespace Tests\Unit\Storefront;

use App\Storefront\Url\StorefrontUrls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StorefrontUrlsTest extends TestCase
{
    public function test_urls_are_root_relative_and_percent_encode_native_script_slugs(): void
    {
        $urls = new StorefrontUrls();

        $this->assertSame('/product/summer-dress', $urls->product('summer-dress'));
        $this->assertSame('/product/%D0%BB%D1%8F%D1%82%D0%BD%D0%B0-%D1%80%D0%BE%D0%BA%D0%BB%D1%8F', $urls->product('лятна-рокля'));
        $this->assertSame('/product-category/clothing/%D1%80%D0%BE%D0%BA%D0%BB%D0%B8', $urls->category('clothing/рокли'));
        $this->assertSame('/product-tag/new', $urls->tag('new'));
        $this->assertSame('/brand/acme', $urls->brand('acme'));
    }

    public function test_a_hostile_slug_cannot_break_out_of_its_segment(): void
    {
        $this->assertSame('/product/a%2Fb%3Fx%3D1%23y', (new StorefrontUrls())->product('a/b?x=1#y'));
    }

    /** @return array<string, array{0: string}> */
    public static function badPaths(): array
    {
        return [
            'empty' => [''],
            'only slashes' => ['///'],
            'empty segment' => ['a//b'],
            '9 segments' => ['a/b/c/d/e/f/g/h/i'],
            'segment of 201 characters' => [str_repeat('a', 201)],
            'space' => ['a b'],
            'dot dot' => ['../etc/passwd'],
            'dot' => ['a/./b'],
            'percent' => ['a%2Fb'],
            'query' => ['a?b=1'],
            'backslash' => ['a\\b'],
            'nul' => ["a\0b"],
            'newline' => ["a\nb"],
            'underscore' => ['a_b'],
            'angle bracket' => ['<script>'],
            'huge' => [str_repeat('a/', 100000)],
        ];
    }

    #[DataProvider('badPaths')]
    public function test_unacceptable_paths_are_refused(string $path): void
    {
        $this->assertNull((new StorefrontUrls())->pathSegments($path));
    }

    public function test_acceptable_paths_and_limits(): void
    {
        $urls = new StorefrontUrls();

        $this->assertSame(['clothing', 'dress'], $urls->pathSegments('clothing/dress'));
        $this->assertSame(['clothing', 'dress'], $urls->pathSegments('/clothing/dress/'));
        $this->assertSame(['лятна', 'рокля'], $urls->pathSegments('лятна/рокля'));
        $this->assertCount(8, $urls->pathSegments('a/b/c/d/e/f/g/h'));
        $this->assertSame([str_repeat('я', 200)], $urls->pathSegments(str_repeat('я', 200)));
        $this->assertNull($urls->pathSegments(str_repeat('я', 201)));
    }

    public function test_slug_validation(): void
    {
        $urls = new StorefrontUrls();

        $this->assertTrue($urls->isValidSlug('dress-2'));
        $this->assertTrue($urls->isValidSlug('рокля-каре'));
        $this->assertFalse($urls->isValidSlug(''));
        $this->assertFalse($urls->isValidSlug('a/b'));
        $this->assertFalse($urls->isValidSlug("dress\n"));
    }
}
