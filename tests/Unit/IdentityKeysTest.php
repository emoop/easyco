<?php

namespace Tests\Unit;

use EasyCo\Order\IdentityKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B (cod-protection-design.md section 2): the ONE normaliser. Matching and blocking only work if the same
 * person typing the same thing in different ways still matches, so every rule is pinned by a table of cases.
 */
class IdentityKeysTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function phones(): array
    {
        return [
            'national, with spaces' => ['0888 123 456', '888123456'],
            'international +' => ['+359888123456', '888123456'],
            'international 00' => ['00359888123456', '888123456'],
            'with dashes and brackets' => ['(0888) 123-456', '888123456'],
            'international with spaces' => ['+359 888 123456', '888123456'],
            'exactly nine digits' => ['888123456', '888123456'],
            'a landline' => ['02 987 65 43', '029876543'],
            'eight digits is too short' => ['8881234', null],
            'seven digits' => ['1234567', null],
            'letters only' => ['call me', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('phones')]
    public function test_a_phone_is_its_last_nine_digits_or_nothing(?string $phone, ?string $key): void
    {
        $this->assertSame($key, IdentityKeys::phone($phone));
    }

    public function test_the_three_ways_to_write_the_same_number_are_one_key(): void
    {
        $this->assertSame(IdentityKeys::phone('0888 123 456'), IdentityKeys::phone('+359888123456'));
        $this->assertSame(IdentityKeys::phone('0888 123 456'), IdentityKeys::phone('00359888123456'));
        $this->assertNotSame(IdentityKeys::phone('0888 123 456'), IdentityKeys::phone('0888 123 457'));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function emails(): array
    {
        return [
            'lower-cased' => ['Maria.Petrova@Gmail.COM', 'maria.petrova@gmail.com'],
            'trimmed' => ['  a@b.bg  ', 'a@b.bg'],
            'gmail dots are NOT stripped' => ['m.p@gmail.com', 'm.p@gmail.com'],
            'plus tags are NOT stripped' => ['m+shop@gmail.com', 'm+shop@gmail.com'],
            'empty' => ['   ', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('emails')]
    public function test_an_email_is_trimmed_and_lower_cased_and_nothing_cleverer(?string $email, ?string $key): void
    {
        $this->assertSame($key, IdentityKeys::email($email));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function addresses(): array
    {
        return [
            'an IPv4 address exactly' => ['203.0.113.5', '203.0.113.5'],
            'an IPv4 address with spaces' => [' 203.0.113.5 ', '203.0.113.5'],
            'an IPv6 address is its /64 prefix' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3:8d3::'],
            'the same /64, another host part' => ['2001:db8:85a3:8d3:ffff:ffff:ffff:ffff', '2001:db8:85a3:8d3::'],
            'an IPv6 address written in full' => ['2001:0db8:85a3:08d3:0000:0000:0000:0001', '2001:db8:85a3:8d3::'],
            'an IPv4-mapped IPv6 address is the IPv4 address' => ['::ffff:203.0.113.5', '203.0.113.5'],
            'not an address' => ['not-an-ip', null],
            'a half address' => ['203.0.113', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('addresses')]
    public function test_an_ip_is_exact_for_ipv4_and_a_slash_64_for_ipv6(?string $ip, ?string $key): void
    {
        $this->assertSame($key, IdentityKeys::ip($ip));
    }

    public function test_two_addresses_in_one_slash_64_are_equal_and_different_ones_are_not(): void
    {
        $this->assertSame(IdentityKeys::ip('2001:db8:1:1::1'), IdentityKeys::ip('2001:db8:1:1:aaaa::2'));
        $this->assertNotSame(IdentityKeys::ip('2001:db8:1:1::1'), IdentityKeys::ip('2001:db8:1:2::1'), 'a different /64');
        $this->assertNotSame(IdentityKeys::ip('203.0.113.5'), IdentityKeys::ip('203.0.113.6'), 'IPv4 is exact');
    }

    public function test_a_name_is_lower_case_with_collapsed_spaces(): void
    {
        $this->assertSame('ivan petrov', IdentityKeys::name('Ivan', 'PETROV'));
        $this->assertSame('ivan petrov', IdentityKeys::name('  Ivan  ', "  Petrov\t"));
        $this->assertSame('иван петров', IdentityKeys::name('Иван', 'ПЕТРОВ'), 'any script');
        $this->assertSame('ivan', IdentityKeys::name('Ivan', null));
        $this->assertNull(IdentityKeys::name(' ', ''));
        $this->assertNull(IdentityKeys::name(null, null));
    }

    public function test_the_street_place_ignores_case_spaces_and_punctuation(): void
    {
        $base = IdentityKeys::place(false, 'BG', 'Sofia', 'Vitosha Blvd 1', null, null);

        $this->assertSame(40, strlen((string) $base));
        $this->assertSame($base, IdentityKeys::place(false, 'bg', ' SOFIA ', 'vitosha blvd. 1', null, null));
        $this->assertSame($base, IdentityKeys::place(false, 'BG', 'Sofia', 'Vitosha   Blvd, 1', null, null));
        $this->assertSame($base, IdentityKeys::place(false, 'BG', 'Sofia', 'Vitosha-Blvd 1!', null, null));
        $this->assertNotSame($base, IdentityKeys::place(false, 'BG', 'Sofia', 'Vitosha Blvd 2', null, null), 'another number');
        $this->assertNotSame($base, IdentityKeys::place(false, 'BG', 'Plovdiv', 'Vitosha Blvd 1', null, null), 'another city');
        $this->assertNotSame($base, IdentityKeys::place(false, 'RO', 'Sofia', 'Vitosha Blvd 1', null, null), 'another country');
    }

    public function test_a_pickup_place_is_the_carrier_and_the_points_text(): void
    {
        $base = IdentityKeys::place(true, 'BG', 'Sofia', null, 'econt', 'Econt Office, Mladost 1');

        $this->assertSame($base, IdentityKeys::place(true, 'BG', 'Plovdiv', 'ignored', 'ECONT', 'econt office  mladost 1'), 'the city and the street fields are not part of a pick-up key');
        $this->assertNotSame($base, IdentityKeys::place(true, 'BG', 'Sofia', null, 'speedy', 'Econt Office, Mladost 1'), 'another carrier');
        $this->assertNotSame($base, IdentityKeys::place(true, 'BG', 'Sofia', null, 'econt', 'Econt Office, Mladost 2'), 'another office');
        $this->assertNotSame(IdentityKeys::place(false, 'econt', 'x', 'Econt Office, Mladost 1', null, null), $base, 'a street and a pick-up never collide');
    }

    public function test_a_place_without_its_identifying_text_has_no_key(): void
    {
        $this->assertNull(IdentityKeys::place(false, 'BG', 'Sofia', '  ', null, null), 'no address line: the key would match a whole city');
        $this->assertNull(IdentityKeys::place(false, 'BG', 'Sofia', '...', null, null));
        $this->assertNull(IdentityKeys::place(true, 'BG', 'Sofia', null, 'econt', null));
    }

    public function test_the_row_form_gives_the_same_keys_as_the_parts(): void
    {
        $row = (object) [
            'email' => 'A@B.bg', 'phone' => '+359 888 123 456', 'recipient_first_name' => 'Ana', 'recipient_last_name' => 'Ivanova', 'delivery_type' => 'street_address',
            'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha 1', 'carrier_code' => null, 'pickup_point_address' => null,
        ];

        $this->assertSame([
            'phone_key' => '888123456', 'email_key' => 'a@b.bg', 'name_key' => 'ana ivanova', 'place_key' => IdentityKeys::place(false, 'BG', 'Sofia', 'Vitosha 1', null, null),
        ], IdentityKeys::forRow($row));
        $this->assertSame(IdentityKeys::forRow($row), IdentityKeys::forRow((array) $row));
    }
}
