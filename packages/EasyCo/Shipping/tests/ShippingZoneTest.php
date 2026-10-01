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
        $zone = ShippingZone::create(' София-град ', 3, ['BG'], ['София', '1000']);

        $this->assertSame('София-град', $zone->name());
        $this->assertSame(3, $zone->sortOrder());
        $this->assertSame(['BG'], $zone->countryCodes());
        $this->assertSame(['София', '1000'], $zone->settlementPatterns(), 'a numeric-looking pattern stays a string');
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

    public function test_an_empty_settlement_pattern_list_normalizes_to_null(): void
    {
        $this->assertNull(ShippingZone::create('Zone', 0, ['BG'], [])->settlementPatterns());
        $this->assertNull(ShippingZone::create('Zone', 0, ['BG'], null)->settlementPatterns());
    }

    public function test_settlement_patterns_are_trimmed(): void
    {
        $this->assertSame(['София', 'Пловдив'], ShippingZone::create('Zone', 0, ['BG'], ['  София ', "Пловдив\n"])->settlementPatterns());
    }

    /** @return array<string, array{array<mixed>}> */
    public static function invalidPatternLists(): array
    {
        return [
            'blank entry' => [['София', '   ']],
            'empty entry' => [['']],
            'non-string entry' => [['София', 7]],
            'duplicate entry' => [['София', 'София']],
            'duplicate after trimming' => [['София', ' София ']],
        ];
    }

    /** @param array<mixed> $patterns */
    #[DataProvider('invalidPatternLists')]
    public function test_a_bad_settlement_pattern_list_is_rejected(array $patterns): void
    {
        $this->expectException(InvalidShippingZoneException::class);

        ShippingZone::create('Zone', 0, ['BG'], $patterns);
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
        $zone = ShippingZone::create('Zone', 1, ['BG'], ['София']);

        try {
            $zone->update('Renamed', 2, ['bg']);
            $this->fail('the lowercase country code should have been rejected');
        } catch (InvalidShippingZoneException) {
        }

        $this->assertSame('Zone', $zone->name());
        $this->assertSame(1, $zone->sortOrder());
        $this->assertSame(['BG'], $zone->countryCodes());
        $this->assertSame(['София'], $zone->settlementPatterns());
    }
}
