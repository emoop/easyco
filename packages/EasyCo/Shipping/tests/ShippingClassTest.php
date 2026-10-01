<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Exceptions\InvalidShippingClassException;
use EasyCo\Shipping\ShippingClass;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShippingClassTest extends TestCase
{
    public function test_a_valid_class_keeps_its_code_verbatim_and_trims_its_name(): void
    {
        $class = ShippingClass::create('  Bulky  ', 'bulky-items_2');

        $this->assertSame('Bulky', $class->name());
        $this->assertSame('bulky-items_2', $class->code());
        $this->assertNull($class->id());
    }

    /** @return array<string, array{string}> */
    public static function invalidCodes(): array
    {
        return [
            'uppercase' => ['Bulky'],
            'contains a space' => ['big items'],
            'leading separator' => ['-bulky'],
            'trailing separator' => ['bulky-'],
            'doubled separator' => ['bulky--items'],
            'empty' => [''],
            'over 64 characters' => [str_repeat('a', 65)],
            'trailing newline' => ["bulky\n"],
            'non-ascii letter' => ['обемисти'],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_a_code_in_the_wrong_format_is_rejected_never_normalized(string $code): void
    {
        $this->expectException(InvalidShippingClassException::class);

        ShippingClass::create('Bulky', $code);
    }

    public function test_a_code_of_exactly_64_characters_is_accepted(): void
    {
        $this->assertSame(64, strlen(ShippingClass::create('Long', str_repeat('a', 64))->code()));
    }

    public function test_an_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidShippingClassException::class);

        ShippingClass::create("   \t ", 'bulky');
    }

    public function test_a_name_over_the_length_limit_is_rejected(): void
    {
        $this->expectException(InvalidShippingClassException::class);

        ShippingClass::create(str_repeat('я', ShippingClass::NAME_MAX_LENGTH + 1), 'bulky');
    }

    public function test_an_empty_description_becomes_null(): void
    {
        $this->assertNull(ShippingClass::create('Bulky', 'bulky', '  ')->description());
        $this->assertSame('Large parcels', ShippingClass::create('Bulky', 'bulky', ' Large parcels ')->description());
    }

    public function test_code_has_no_setter_and_name_and_description_can_change(): void
    {
        $class = ShippingClass::create('Bulky', 'bulky', 'old');

        $class->rename(' Oversized ');
        $class->describe('');

        $this->assertSame('Oversized', $class->name());
        $this->assertNull($class->description());
        $this->assertSame('bulky', $class->code());
        $this->assertFalse(method_exists($class, 'setCode') || method_exists($class, 'changeCode'));
    }

    public function test_assign_id_is_a_one_time_operation(): void
    {
        $class = ShippingClass::create('Bulky', 'bulky');
        $class->assignId('1');

        $this->expectException(LogicException::class);

        $class->assignId('2');
    }
}
