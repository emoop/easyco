<?php

namespace Tests\Unit;

use App\Services\ClientClaimedPickupKindSource;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\ShippingMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Shipping stage 5g-2a: ShippingMethod::servesKind() (design 9.3.3, decision Q4) and the claimed-kind source (9.3.7). */
final class PickupKindDomainTest extends TestCase
{
    private function method(?Type $label, Scope $scope): ShippingMethod
    {
        return ShippingMethod::create('1', 'M', ShippingMethodKind::FLAT, 0, true, 500, [], null, null, false, ShippingClassMode::REPLACE, null, $label, $scope);
    }

    /** @return array<string, array{?Type, Scope, bool, ?Type, bool}> label, scope, isPickup, kind, expected */
    public static function matrix(): array
    {
        return [
            'address-only: an address' => [null, Scope::ADDRESS, false, null, true],
            'address-only: an unspecified pickup' => [null, Scope::ADDRESS, true, null, false],
            'address-only: an office' => [null, Scope::ADDRESS, true, Type::OFFICE, false],
            'pickup-only: an address' => [null, Scope::PICKUP, false, null, false],
            'pickup-only: unspecified pickup' => [null, Scope::PICKUP, true, null, true],
            'pickup-only: an office' => [null, Scope::PICKUP, true, Type::OFFICE, true],
            'pickup-only: a locker' => [null, Scope::PICKUP, true, Type::LOCKER, true],
            'any: an address' => [null, Scope::ANY, false, null, true],
            'any: unspecified pickup' => [null, Scope::ANY, true, null, true],
            'any: a locker' => [null, Scope::ANY, true, Type::LOCKER, true],
            'office label: an office' => [Type::OFFICE, Scope::PICKUP, true, Type::OFFICE, true],
            'office label: a LOCKER (Q4)' => [Type::OFFICE, Scope::PICKUP, true, Type::LOCKER, false],
            'office label: unspecified pickup' => [Type::OFFICE, Scope::PICKUP, true, null, true],
            'locker label: an office (Q4)' => [Type::LOCKER, Scope::PICKUP, true, Type::OFFICE, false],
            'locker label: a locker' => [Type::LOCKER, Scope::PICKUP, true, Type::LOCKER, true],
            'address label: an address' => [Type::ADDRESS, Scope::ADDRESS, false, null, true],
            'a stated address kind on a pickup point is never served' => [null, Scope::ANY, true, Type::ADDRESS, false],
        ];
    }

    #[DataProvider('matrix')]
    public function test_serves_kind(?Type $label, Scope $scope, bool $isPickup, ?Type $kind, bool $expected): void
    {
        $this->assertSame($expected, $this->method($label, $scope)->servesKind($kind, $isPickup));
    }

    public function test_serves_pickup_point_is_still_there_and_unchanged(): void
    {
        $this->assertTrue($this->method(Type::OFFICE, Scope::PICKUP)->servesPickupPoint(true));
        $this->assertFalse($this->method(Type::OFFICE, Scope::PICKUP)->servesPickupPoint(false));
        $this->assertTrue($this->method(null, Scope::ANY)->servesPickupPoint(false));
    }

    public function test_the_claimed_kind_source_returns_office_or_locker_only(): void
    {
        $source = new ClientClaimedPickupKindSource();

        $this->assertSame(Type::OFFICE, $source->kindFor('econt', 'o-1', 'office'));
        $this->assertSame(Type::LOCKER, $source->kindFor('econt', 'o-1', 'locker'));

        foreach ([null, 'address', 'other', 'nowhere', ''] as $claimed) {
            $this->assertNull($source->kindFor('econt', 'o-1', $claimed), var_export($claimed, true));
        }
    }
}
