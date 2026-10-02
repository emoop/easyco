<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Exceptions\InvalidShippingZoneException;
use EasyCo\Shipping\ShippingZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShippingZoneTest extends TestCase
{
    public function test_a_valid_zone_keeps_its_fields(): void
    {
        $zone = ShippingZone::create(' София-град ', 3, ['BG'], ['София', 'гр. София'], ['1000', '1111']);

        $this->assertSame('София-град', $zone->name());
        $this->assertSame(3, $zone->sortOrder());
        $this->assertSame(['BG'], $zone->countryCodes());
        $this->assertSame(['София', 'гр. София'], $zone->settlementNames(), 'names are kept as entered, "гр. София" included');
        $this->assertSame(['1000', '1111'], $zone->postcodes(), 'a numeric postcode stays a string');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidCountryCodes(): array
    {
        return [
            'lowercase' => ['bg'],
            'mixed case' => ['Bg'],
            'one letter' => ['B'],
            'three letters' => ['BGR'],
            'digits' => ['B1'],
            'empty string' => [''],
            'trailing newline' => ["BG\n"],
            'not a string' => [12],
        ];
    }

    #[DataProvider('invalidCountryCodes')]
    public function test_a_country_code_not_two_uppercase_letters_is_rejected(mixed $code): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', 0, [$code]);
    }

    public function test_an_empty_country_list_is_rejected(): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', 0, []);
    }

    public function test_a_duplicate_country_code_is_rejected(): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', 0, ['BG', 'RO', 'BG']);
    }

    public function test_country_codes_are_not_checked_against_an_official_list(): void
    {
        $this->assertSame(['ZZ'], ShippingZone::create('Zone', 0, ['ZZ'])->countryCodes());
    }

    public function test_empty_name_and_postcode_lists_normalize_to_null(): void
    {
        $this->assertNull(ShippingZone::create('Zone', 0, ['BG'], [], [])->settlementNames());
        $this->assertNull(ShippingZone::create('Zone', 0, ['BG'], [], [])->postcodes());
        $this->assertNull(ShippingZone::create('Zone', 0, ['BG'], null, null)->settlementNames());
        $this->assertNull(ShippingZone::create('Zone', 0, ['BG'], null, null)->postcodes());
    }

    public function test_settlement_names_are_trimmed_but_otherwise_kept_as_entered(): void
    {
        $this->assertSame(
            ['София', 'гр. Пловдив', 'с. Бояна'],
            ShippingZone::create('Zone', 0, ['BG'], ['  София ', "гр. Пловдив\n", 'с. Бояна'])->settlementNames(),
        );
    }

    /** @return array<string, array{array<mixed>}> */
    public static function invalidNameLists(): array
    {
        return [
            'blank entry' => [['София', '   ']],
            'empty entry' => [['']],
            'non-string entry' => [['София', 7]],
            'duplicate entry' => [['София', 'София']],
            'duplicate after trimming' => [['София', ' София ']],
        ];
    }

    /** @param array<mixed> $names */
    #[DataProvider('invalidNameLists')]
    public function test_a_bad_settlement_name_list_is_rejected(array $names): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', 0, ['BG'], $names);
    }

    public function test_postcodes_are_trimmed_stripped_of_all_whitespace_and_uppercased(): void
    {
        $zone = ShippingZone::create('Zone', 0, ['GB'], null, ['sw1a 1aa', '  1000 ', "ec1a\t1bb", 'sw 1a  2aa', "1\u{00A0}000-A"]);

        $this->assertSame(['SW1A1AA', '1000', 'EC1A1BB', 'SW1A2AA', '1000-A'], $zone->postcodes());
    }

    /** @return array<string, array{array<mixed>}> */
    public static function invalidPostcodeLists(): array
    {
        return [
            'invalid character' => [['10#00']],
            'dot' => [['1000.']],
            'cyrillic letters' => [['СОФИЯ']],
            'too short' => [['1']],
            'too long' => [['1234567890123']],
            'blank' => [['   ']],
            'non-string' => [[1000]],
            'duplicate' => [['1000', '1000']],
            'duplicate after normalization' => [['sw1a 1aa', 'SW1A1AA']],
        ];
    }

    /** @param array<mixed> $postcodes */
    #[DataProvider('invalidPostcodeLists')]
    public function test_a_bad_postcode_list_is_rejected(array $postcodes): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', 0, ['BG'], null, $postcodes);
    }

    public function test_postcode_length_limits_are_two_to_twelve(): void
    {
        $this->assertSame(['10', '123456789012'], ShippingZone::create('Zone', 0, ['BG'], null, ['10', '123456789012'])->postcodes());
    }

    public function test_an_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('  ', 0, ['BG']);
    }

    public function test_a_negative_sort_order_is_rejected(): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', -1, ['BG']);
    }

    public function test_a_rejected_update_leaves_the_zone_untouched(): void
    {
        $zone = ShippingZone::create('Zone', 1, ['BG'], ['София'], ['1000']);

        try {
            $zone->update('Renamed', 2, ['bg']);
            $this->fail('the lowercase country code should have been rejected');
        } catch (InvalidShippingZoneException) {
        }

        $this->assertSame('Zone', $zone->name());
        $this->assertSame(1, $zone->sortOrder());
        $this->assertSame(['BG'], $zone->countryCodes());
        $this->assertSame(['София'], $zone->settlementNames());
        $this->assertSame(['1000'], $zone->postcodes());
    }
}
