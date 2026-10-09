<?php

namespace Tests\Unit\Mail;

use App\Mail\Iban;
use PHPUnit\Framework\TestCase;

class IbanTest extends TestCase
{
    public function test_well_known_valid_ibans_pass_in_any_spacing_and_case(): void
    {
        foreach (['BG80BNBG96611020345678', 'bg80 bnbg 9661 1020 3456 78', 'DE89370400440532013000', 'GB82 WEST 1234 5698 7654 32', 'NL91ABNA0417164300'] as $iban) {
            $this->assertTrue(Iban::isValid($iban), $iban);
        }
    }

    public function test_a_changed_digit_a_bad_length_and_a_bad_shape_fail(): void
    {
        foreach (['BG80BNBG96611020345679', 'BG80BNBG9661102034567', 'BG8ABNBG96611020345678', '8080BNBG96611020345678', '', 'BG80', "BG80BNBG96611020345678\n; DROP"] as $iban) {
            $this->assertFalse(Iban::isValid($iban), $iban);
        }
    }

    public function test_normalize_and_format(): void
    {
        $this->assertSame('BG80BNBG96611020345678', Iban::normalize(' bg80 bnbg-9661 1020 3456 78 '));
        $this->assertSame('BG80 BNBG 9661 1020 3456 78', Iban::format('BG80BNBG96611020345678'));
    }
}
