<?php

namespace Tests\Unit;

use EasyCo\Catalog\Enums\MeasureUnit;
use EasyCo\Catalog\Exceptions\InvalidMeasurementValueException;
use EasyCo\Catalog\Measure;
use EasyCo\Catalog\MeasurementNumber;
use EasyCo\Catalog\MeasurementTemplate;
use EasyCo\Catalog\MeasurementValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** MEAS-2 D: the number rules (one helper), the closed unit list and the pure domain classes. No framework, no database. */
class MeasurementNumberTest extends TestCase
{
    /** @return array<string, array{0: string|int|float, 1: int}> */
    public static function good(): array
    {
        return [
            'whole' => ['95', 9500],
            'dot' => ['95.5', 9550],
            'comma' => ['95,5', 9550],
            'two decimals' => ['95.25', 9525],
            'leading zero fraction' => ['0.05', 5],
            'smallest' => ['0.01', 1],
            'largest' => ['999999.99', 99_999_999],
            'padded' => ['  42  ', 4200],
            'int' => [7, 700],
            'float' => [95.5, 9550],
            'trailing zero' => ['95.50', 9550],
        ];
    }

    #[DataProvider('good')]
    public function test_it_accepts(string|int|float $raw, int $hundredths): void
    {
        $this->assertSame($hundredths, MeasurementNumber::parse($raw));
    }

    /** @return array<string, array{0: string}> */
    public static function bad(): array
    {
        return [
            'letters' => ['abc'],
            'negative' => ['-1'],
            'zero' => ['0'],
            'zero decimals' => ['0.00'],
            'three decimals' => ['1.234'],
            'thousands separator' => ['1 000'],
            'two separators' => ['1,000.5'],
            'exponent' => ['1e3'],
            'plus sign' => ['+5'],
            'too big' => ['1000000'],
            'just over' => ['999999.995'],
            'bare dot' => ['.5'],
            'trailing dot' => ['5.'],
        ];
    }

    #[DataProvider('bad')]
    public function test_it_refuses(string $raw): void
    {
        $this->expectException(InvalidMeasurementValueException::class);

        MeasurementNumber::parse($raw);
    }

    public function test_empty_means_no_value(): void
    {
        $this->assertNull(MeasurementNumber::parse(null));
        $this->assertNull(MeasurementNumber::parse(''));
        $this->assertNull(MeasurementNumber::parse('   '));
    }

    public function test_hundredths_out_of_range_are_refused(): void
    {
        foreach ([0, -5, 100_000_000] as $value) {
            try {
                MeasurementNumber::assertInRange($value);
                $this->fail("{$value} should be refused");
            } catch (InvalidMeasurementValueException $e) {
                $this->assertSame('out_of_range', $e->reason);
            }
        }
    }

    public function test_it_formats_with_trimmed_zeros_and_the_locale_mark(): void
    {
        $this->assertSame('90', MeasurementNumber::format(9000));
        $this->assertSame('95,5', MeasurementNumber::format(9550, 'bg'));
        $this->assertSame('95.5', MeasurementNumber::format(9550, 'en'));
        $this->assertSame('95,25', MeasurementNumber::format(9525, 'bg'));
        $this->assertSame('0,05', MeasurementNumber::format(5, 'bg'));
        $this->assertSame('0.05', MeasurementNumber::format(5, 'en'));
        $this->assertSame('999999.99', MeasurementNumber::format(99_999_999, 'en'));
    }

    public function test_the_round_trip_is_exact_for_every_typed_form(): void
    {
        foreach (['95', '95,5', '95.25', '0,01', '999999,99', '1,1'] as $typed) {
            $hundredths = MeasurementNumber::parse($typed);

            $this->assertSame(
                str_replace('.', ',', $typed),
                MeasurementNumber::format($hundredths, 'bg'),
                "round trip of {$typed}",
            );
            $this->assertSame($hundredths, MeasurementNumber::parse(MeasurementNumber::format($hundredths, 'bg')));
        }
    }

    public function test_the_unit_list_is_closed(): void
    {
        $this->assertSame(['mm', 'cm', 'm', 'in', 'ft', 'g', 'kg'], MeasureUnit::codes());
        $this->assertSame(MeasureUnit::FT, Measure::create('Length', 'ft')->unit());

        foreach (['km', 'lb', 'CM', '', 'cm ', 'G'] as $unit) {
            try {
                Measure::create('Length', $unit);
                $this->fail("unit {$unit} should be refused");
            } catch (InvalidMeasurementValueException $e) {
                $this->assertSame('unknown_unit', $e->reason);
            }
        }
    }

    public function test_a_measure_name_is_trimmed_and_bounded(): void
    {
        $this->assertSame('Chest', Measure::create('  Chest ', 'cm')->name());

        foreach (['', '   ', str_repeat('x', 121)] as $name) {
            try {
                Measure::create($name, 'cm');
                $this->fail('a bad name should be refused');
            } catch (InvalidMeasurementValueException $e) {
                $this->assertSame('invalid_name', $e->reason);
            }
        }

        $this->assertSame(120, mb_strlen(Measure::create(str_repeat('я', 120), 'cm')->name()));
    }

    public function test_a_template_keeps_its_order_and_refuses_duplicates_and_more_than_twenty(): void
    {
        $template = MeasurementTemplate::create('Blouses', ['3', '1', '2']);
        $this->assertSame(['3', '1', '2'], $template->measureIds());

        $template->setMeasureIds(['2', '3']);
        $this->assertSame(['2', '3'], $template->measureIds());

        $this->expectException(InvalidMeasurementValueException::class);
        MeasurementTemplate::create('Dup', ['1', '1']);
    }

    public function test_a_template_with_21_measures_is_refused(): void
    {
        $this->expectException(InvalidMeasurementValueException::class);
        MeasurementTemplate::create('Many', array_map('strval', range(1, 21)));
    }

    public function test_a_value_object_is_range_checked_and_formats(): void
    {
        $value = MeasurementValue::fromInput('5', '7', '95,5');
        $this->assertSame(9550, $value->hundredths);
        $this->assertSame('95.5', $value->formatted('en'));
        $this->assertNull(MeasurementValue::fromInput('5', '7', ''));

        $this->expectException(InvalidMeasurementValueException::class);
        new MeasurementValue('5', '7', 0);
    }
}
