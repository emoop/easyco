<?php

namespace Tests\Unit\Mail;

use App\Mail\MailErrors;
use App\Mail\MailHeader;
use PHPUnit\Framework\TestCase;

class MailErrorSanitiseTest extends TestCase
{
    public function test_literal_secrets_are_removed_wherever_they_occur_case_insensitively(): void
    {
        $text = MailErrors::sanitise('login bob with HUNTER2PASS failed; hunter2pass rejected', ['hunter2pass', 'bob']);

        $this->assertStringNotContainsStringIgnoringCase('hunter2pass', $text);
        $this->assertStringNotContainsString('bob', $text);
        $this->assertStringContainsString('failed', $text);
    }

    public function test_urls_with_userinfo_and_email_addresses_are_removed(): void
    {
        $text = MailErrors::sanitise('Connection to smtp://user:pw@mail.example.com:587/path failed for someone@example.com');

        $this->assertStringNotContainsString('smtp://', $text);
        $this->assertStringNotContainsString('user:pw', $text);
        $this->assertStringNotContainsString('someone@example.com', $text);
        $this->assertStringContainsString('failed', $text);
    }

    public function test_credential_pairs_and_long_token_runs_are_removed(): void
    {
        $text = MailErrors::sanitise('rejected password=abc123 and api_key: sk_live_0123 with AUTH QWxhZGRpbjpvcGVuIHNlc2FtZTEyMzQ1Njc4OTA= and Bearer eyJhbGciOiJIUzI1NiJ9abcdefghijklmnop');

        $this->assertStringNotContainsString('abc123', $text);
        $this->assertStringNotContainsString('sk_live_0123', $text);
        $this->assertStringNotContainsString('QWxhZGRpbjpvcGVuIHNlc2FtZTEyMzQ1Njc4OTA', $text);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $text);
    }

    public function test_the_human_reason_survives(): void
    {
        $text = MailErrors::sanitise('Expected response code "235" but got code "535", with message "535 5.7.8 Username and Password not accepted."');

        $this->assertStringContainsString('535 5.7.8 Username and Password not accepted', $text);
    }

    public function test_the_result_is_one_header_safe_line_of_bounded_length(): void
    {
        $text = MailErrors::sanitise("line one\r\nBcc: x\n".str_repeat('word ', 200), [], 120);

        $this->assertStringNotContainsString("\n", $text);
        $this->assertStringNotContainsString("\r", $text);
        $this->assertLessThanOrEqual(120, mb_strlen($text));
    }

    public function test_a_very_short_secret_is_not_used_to_shred_the_message(): void
    {
        $this->assertSame('an error in a mailbox', MailErrors::sanitise('an error in a mailbox', ['a']));
    }

    public function test_is_plain_refuses_control_and_bidi_characters_and_accepts_unicode_letters(): void
    {
        foreach (["a\r\nb", "a\0b", "a\tb", "a\u{202E}b", "a\u{2066}b", "a\u{200B}b", "a\u{FEFF}b", "a\u{2028}b", "a\x85b"] as $bad) {
            $this->assertFalse(MailHeader::isPlain($bad), json_encode($bad));
        }

        $this->assertTrue(MailHeader::isPlain('Магазин Иван & Син — "Café" 🛒'));
        $this->assertFalse(MailHeader::isPlain("\xFF\xFE bad utf8"), 'invalid UTF-8 is refused too');
    }
}
