<?php

namespace Tests\Unit\Mail;

use App\Mail\MailHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MailHeaderTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function injectionAttempts(): array
    {
        return [
            'CRLF then a Bcc header' => ["Hello\r\nBcc: spy@example.com"],
            'bare LF' => ["Hello\nBcc: spy@example.com"],
            'bare CR' => ["Hello\rBcc: spy@example.com"],
            'NUL byte' => ["Hello\0Bcc: spy@example.com"],
            'vertical tab and form feed' => ["Hello\x0B\x0CWorld"],
            'Unicode line separator' => ["Hello\u{2028}World"],
            'right-to-left override' => ["Hello\u{202E}dlroW"],
            'bidi isolate' => ["Hello\u{2066}World\u{2069}"],
            'zero-width and BOM' => ["Hel\u{200B}lo\u{FEFF}"],
            'C1 control' => ["Hello\u{0085}World"],
        ];
    }

    #[DataProvider('injectionAttempts')]
    public function test_clean_leaves_a_single_header_safe_line(string $input): void
    {
        $clean = MailHeader::clean($input);

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F-\x9F\x{2028}\x{2029}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $clean);
        $this->assertStringStartsWith('Hel', $clean);
    }

    public function test_clean_collapses_whitespace_trims_and_cuts_to_the_limit(): void
    {
        $this->assertSame('a b c', MailHeader::clean("  a \t\t b   c  "));
        $this->assertSame(180, mb_strlen(MailHeader::clean(str_repeat('я', 500))));
        $this->assertSame('abc', MailHeader::clean('abcdef', 3));
        $this->assertSame('Български', MailHeader::clean('Български'));
    }

    public function test_clean_scrubs_invalid_utf8_instead_of_failing(): void
    {
        $this->assertIsString(MailHeader::clean("bad\xC3\x28bytes"));
    }

    /** @return array<string, array{string}> */
    public static function badAddresses(): array
    {
        return [
            'empty' => [''],
            'second recipient by comma' => ['a@example.com,b@example.com'],
            'second recipient by semicolon' => ['a@example.com;b@example.com'],
            'display name form' => ['Evil <a@example.com>'],
            'CRLF smuggling' => ["a@example.com\r\nBcc: b@example.com"],
            'LF smuggling' => ["a@example.com\nBcc: b@example.com"],
            'NUL' => ["a@example.com\0"],
            'space inside' => ['a b@example.com'],
            'two at signs' => ['a@b@example.com'],
            'quoted local part' => ['"a b"@example.com'],
            'non-ascii' => ['а@example.com'],
            'no domain' => ['a@'],
            'too long' => [str_repeat('a', 250).'@example.com'],
        ];
    }

    #[DataProvider('badAddresses')]
    public function test_address_refuses_anything_but_one_plain_address(string $input): void
    {
        $this->assertNull(MailHeader::address($input));
    }

    public function test_address_accepts_a_plain_address_and_trims_it(): void
    {
        $this->assertSame('first.last+tag@example.co.uk', MailHeader::address('  first.last+tag@example.co.uk '));
    }
}
