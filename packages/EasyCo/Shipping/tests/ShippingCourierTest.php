<?php

namespace EasyCo\Shipping\Tests;

use EasyCo\Shipping\Enums\ShippingDeliveryType;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Exceptions\InvalidShippingMethodException;
use EasyCo\Shipping\Rating\RateLine;
use EasyCo\Shipping\Rating\RateRequest;
use EasyCo\Shipping\Rating\ShippingRateCalculator;
use EasyCo\Shipping\ShippingCourier;
use EasyCo\Shipping\ShippingMethod;
use PHPUnit\Framework\TestCase;

/**
 * Stage 5f: the courier and delivery type of a method are optional DISPLAY facts used for grouping. They change no
 * price and no rule, every existing caller is unchanged (both are trailing optional arguments), and the group key and
 * the grouping live in ONE pure helper.
 */
final class ShippingCourierTest extends TestCase
{
    private function method(?string $courier = null, ?ShippingDeliveryType $type = null, ShippingMethodKind $kind = ShippingMethodKind::FLAT): ShippingMethod
    {
        return ShippingMethod::create(
            '1', 'To office', $kind, 0, true, $kind === ShippingMethodKind::FLAT ? 500 : null, [], null,
            $kind === ShippingMethodKind::CARRIER ? 'econt' : null, in_array($type, [ShippingDeliveryType::OFFICE, ShippingDeliveryType::LOCKER], true), \EasyCo\Shipping\Enums\ShippingClassMode::REPLACE, $courier, $type,
        );
    }

    public function test_the_delivery_type_is_an_enum_of_four_values(): void
    {
        $this->assertSame(['address', 'office', 'locker', 'other'], array_map(fn (ShippingDeliveryType $type): string => $type->value, ShippingDeliveryType::cases()));
    }

    public function test_both_default_to_none_for_every_existing_caller(): void
    {
        $method = ShippingMethod::create('1', 'Flat', ShippingMethodKind::FLAT, 0, true, 100);

        $this->assertNull($method->courier());
        $this->assertNull($method->deliveryType());

        $restored = ShippingMethod::reconstituteFromStorage('1', '1', 'Flat', ShippingMethodKind::FLAT, 0, true, 100, [], null, null, false);
        $this->assertNull($restored->courier());
        $this->assertNull($restored->deliveryType());
    }

    public function test_the_courier_is_trimmed_and_an_empty_one_is_none(): void
    {
        $this->assertSame('Еконт', $this->method('  Еконт ')->courier());
        $this->assertNull($this->method('')->courier());
        $this->assertNull($this->method("  \t ")->courier());
        $this->assertSame(ShippingDeliveryType::OFFICE, $this->method('Econt', ShippingDeliveryType::OFFICE)->deliveryType());
    }

    public function test_a_hundred_characters_are_accepted_and_a_hundred_and_one_are_not(): void
    {
        $this->assertSame(100, mb_strlen($this->method(str_repeat('я', 100))->courier()));

        $this->expectException(InvalidShippingMethodException::class);
        $this->method(str_repeat('я', 101));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notPlain')]
    public function test_a_courier_must_be_a_single_line_of_plain_text(string $courier): void
    {
        $this->expectException(InvalidShippingMethodException::class);
        $this->method($courier);
    }

    /** @return array<string, array{string}> */
    public static function notPlain(): array
    {
        return [
            'newline' => ["Eco\nnt"],
            'tab inside' => ["Eco\tnt"],
            'NUL' => ["Eco\x00nt"],
            'bell' => ["Eco\x07nt"],
            'DEL' => ["Eco\x7Fnt"],
            'bidi override' => ["Eco\u{202E}nt"],
            'bidi isolate' => ["Eco\u{2066}nt"],
        ];
    }

    public function test_every_kind_may_carry_a_courier_and_a_type_including_carrier(): void
    {
        foreach ([ShippingMethodKind::FLAT, ShippingMethodKind::FREE, ShippingMethodKind::PER_CLASS, ShippingMethodKind::CARRIER] as $kind) {
            $method = ShippingMethod::create(
                '1', 'M', $kind, 0, true, $kind === ShippingMethodKind::FLAT || $kind === ShippingMethodKind::PER_CLASS ? 100 : null, [], null,
                $kind === ShippingMethodKind::CARRIER ? 'econt' : null, true, \EasyCo\Shipping\Enums\ShippingClassMode::REPLACE, 'Econt', ShippingDeliveryType::LOCKER,
            );

            $this->assertSame('Econt', $method->courier(), $kind->name);
            $this->assertSame(ShippingDeliveryType::LOCKER, $method->deliveryType(), $kind->name);
        }
    }

    public function test_update_rewrites_both_and_a_rejected_update_leaves_the_entity_as_it_was(): void
    {
        $method = $this->method('Econt', ShippingDeliveryType::OFFICE);

        $method->update('To address', ShippingMethodKind::FLAT, 0, true, 500, [], null, null, false, \EasyCo\Shipping\Enums\ShippingClassMode::REPLACE, 'Speedy', ShippingDeliveryType::ADDRESS);
        $this->assertSame(['Speedy', ShippingDeliveryType::ADDRESS], [$method->courier(), $method->deliveryType()]);

        try {
            $method->update('X', ShippingMethodKind::FLAT, 0, true, 500, [], null, null, false, \EasyCo\Shipping\Enums\ShippingClassMode::REPLACE, "Bad\nname", null);
            $this->fail('should be refused');
        } catch (InvalidShippingMethodException) {
        }

        $this->assertSame(['Speedy', ShippingDeliveryType::ADDRESS, 'To address'], [$method->courier(), $method->deliveryType(), $method->name()]);
    }

    public function test_the_courier_and_type_change_no_price(): void
    {
        $plain = $this->method();
        $typed = $this->method('Econt', ShippingDeliveryType::LOCKER);
        $request = new RateRequest('EUR', 1000, [new RateLine(null, 1)]);
        $calculator = new ShippingRateCalculator();

        $this->assertSame($calculator->rateFor($plain, $request)->amountMinor(), $calculator->rateFor($typed, $request)->amountMinor());
        $this->assertTrue($typed->requiresPickupPoint(), 'a locker method is pickup-only (stage 6a label x scope rule); it still prices exactly like the plain one');
    }

    // ---- the group key and the grouping --------------------------------------------------------------------

    public function test_the_group_key_ignores_case_and_surrounding_spaces_including_cyrillic(): void
    {
        $this->assertSame('еконт', ShippingCourier::key('Еконт'));
        $this->assertSame(ShippingCourier::key('Еконт'), ShippingCourier::key('  еконт '));
        $this->assertSame(ShippingCourier::key('Speedy'), ShippingCourier::key('SPEEDY'));
        $this->assertNotSame(ShippingCourier::key('Speedy'), ShippingCourier::key('Speed y'));
        $this->assertNull(ShippingCourier::key(null));
        $this->assertNull(ShippingCourier::key(''));
        $this->assertNull(ShippingCourier::key('   '));
    }

    public function test_grouping_keeps_the_order_shows_the_first_name_and_collects_the_ungrouped_last(): void
    {
        $items = [
            ['id' => 'a', 'courier' => 'Еконт'],
            ['id' => 'b', 'courier' => null],
            ['id' => 'c', 'courier' => 'Speedy'],
            ['id' => 'd', 'courier' => ' еконт '],
            ['id' => 'e', 'courier' => ''],
            ['id' => 'f', 'courier' => 'SPEEDY'],
        ];

        $groups = ShippingCourier::group($items, fn (array $item): ?string => $item['courier']);

        $this->assertSame(['Еконт', 'Speedy', null], array_column($groups, 'courier'), 'in order of the first method; the display name is the first one');
        $this->assertSame([['a', 'd'], ['c', 'f'], ['b', 'e']], array_map(fn (array $group): array => array_column($group['items'], 'id'), $groups));
    }

    public function test_grouping_without_a_courier_is_one_ungrouped_entry_and_nothing_gives_none(): void
    {
        $groups = ShippingCourier::group([['courier' => null], ['courier' => null]], fn (array $item): ?string => $item['courier']);

        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]['courier']);
        $this->assertCount(2, $groups[0]['items']);
        $this->assertSame([], ShippingCourier::group([], fn ($item) => null));
    }

    public function test_the_display_name_puts_the_courier_before_the_name_only_when_there_is_one(): void
    {
        $this->assertSame('Econt – To office', ShippingCourier::displayName('Econt', 'To office'));
        $this->assertSame('Econt – To office', ShippingCourier::displayName(' Econt ', 'To office'));
        $this->assertSame('To office', ShippingCourier::displayName(null, 'To office'));
        $this->assertSame('To office', ShippingCourier::displayName('  ', 'To office'));
    }
}
