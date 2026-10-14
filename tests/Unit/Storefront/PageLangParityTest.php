<?php

namespace Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;

/** The Bulgarian and the English `pages.php` carry exactly the same keys (Bulgarian is the master, English must not lag behind). */
class PageLangParityTest extends TestCase
{
    /** @return list<string> */
    private function keys(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            is_array($value) ? array_push($keys, ...$this->keys($value, $prefix.$key.'.')) : $keys[] = $prefix.$key;
        }

        sort($keys);

        return $keys;
    }

    public function test_bulgarian_and_english_have_the_same_keys_and_no_empty_text(): void
    {
        $bg = require dirname(__DIR__, 3).'/lang/bg/pages.php';
        $en = require dirname(__DIR__, 3).'/lang/en/pages.php';

        $this->assertSame($this->keys($bg), $this->keys($en));

        foreach ([$bg, $en] as $lines) {
            array_walk_recursive($lines, fn ($text, $key) => $this->assertNotSame('', trim((string) $text), (string) $key));
        }
    }
}
