<?php

namespace Tests\Unit\Storefront\Account;

use App\Storefront\Account\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ACC-3a: where a login may send the browser: a same-origin relative path or the account page, nothing else. */
class SafeRedirectTest extends TestCase
{
    /** @return array<string, array{0: mixed}> */
    public static function accepted(): array
    {
        return [
            'a path' => ['/account/x'],
            'the account' => ['/account'],
            'the root' => ['/'],
            'a query' => ['/product/shirt?size=m&x=1'],
            'a fragment' => ['/account#orders'],
            'an encoded slash is only text' => ['/%2F%2Fevil.com'],
        ];
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejected(): array
    {
        return [
            'protocol-relative' => ['//evil.com'],
            'protocol-relative with a path' => ['//evil.com/account'],
            'an absolute url' => ['https://evil.com'],
            'an absolute url with a path' => ['https://evil.com/account'],
            'a backslash second' => ['/\\evil.com'],
            'a backslash later' => ['/account\\..\\x'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,x'],
            'a relative word' => ['account'],
            'empty' => [''],
            'null' => [null],
            'an array' => [['/account']],
            'a tab in the middle (a browser would strip it and read //)' => ["/\t/evil.com"],
            'a newline in the middle' => ["/\n/evil.com"],
            'a space' => ['/ /evil.com'],
            'a null byte' => ["/\0/x"],
            'too long' => ['/'.str_repeat('a', 2000)],
        ];
    }

    #[DataProvider('accepted')]
    public function test_a_same_origin_relative_path_is_kept(string $path): void
    {
        $this->assertSame($path, SafeRedirect::path($path));
    }

    #[DataProvider('rejected')]
    public function test_anything_else_is_the_account_page(mixed $value): void
    {
        $this->assertSame('/account', SafeRedirect::path($value));
    }
}
