<?php

namespace Tests\Unit\Storefront;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/** bg and en move together: same keys, no empty strings, the same :placeholders. And the views print only keys that exist. */
class StorefrontLangParityTest extends TestCase
{
    public function test_storefront_lang_files_have_identical_keys_and_placeholders(): void
    {
        $en = Arr::dot(require dirname(__DIR__, 3).'/lang/en/storefront.php');
        $bg = Arr::dot(require dirname(__DIR__, 3).'/lang/bg/storefront.php');

        $this->assertSame(array_keys($en), array_keys($bg));

        foreach ($en as $key => $english) {
            $this->assertNotSame('', trim((string) $english), $key);
            $this->assertNotSame('', trim((string) $bg[$key]), $key);

            preg_match_all('/:[a-z_]+/', (string) $english, $enPlaceholders);
            preg_match_all('/:[a-z_]+/', (string) $bg[$key], $bgPlaceholders);
            $this->assertEqualsCanonicalizing($enPlaceholders[0], $bgPlaceholders[0], $key);
        }
    }

    public function test_every_key_a_storefront_view_prints_exists_and_is_used(): void
    {
        $en = require dirname(__DIR__, 3).'/lang/en/storefront.php';
        $used = [];

        foreach (glob(dirname(__DIR__, 3).'/resources/views/storefront/{,partials/}*.blade.php', GLOB_BRACE) as $file) {
            preg_match_all("/__\\('storefront\\.([a-z_]+)'/", (string) file_get_contents($file), $matches);
            $used = [...$used, ...$matches[1]];
        }

        $used = array_unique($used);

        $this->assertSame([], array_values(array_diff($used, array_keys($en))), 'a view prints a missing key');
    }
}
