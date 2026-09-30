<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use EasyCo\Order\Enums\OrderStatus;
use ReflectionMethod;
use Tests\TestCase;

/**
 * OrderResource's colored status badges (mirroring ProductResource's own
 * status column precedent) — one shared OrderResource::statusColor() behind
 * both the table column and the infolist entry, never two copies of the
 * match. statusColor() is private, so it's reached the same way any other
 * private-helper-with-real-business-meaning test in this suite would:
 * ReflectionMethod, invoked directly.
 */
class OrderStatusColorTest extends TestCase
{
    /** Filament's built-in palette keys — a color outside this set is not a real Filament color. */
    private const VALID_FILAMENT_COLORS = ['gray', 'success', 'danger', 'warning', 'info', 'primary'];

    private function statusColor(OrderStatus $status): string
    {
        $method = new ReflectionMethod(OrderResource::class, 'statusColor');
        $method->setAccessible(true);

        return $method->invoke(null, $status->value);
    }

    public function test_every_order_status_resolves_to_a_real_filament_color(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $color = $this->statusColor($status);

            $this->assertContains(
                $color,
                self::VALID_FILAMENT_COLORS,
                "OrderResource::statusColor() returned '{$color}' for {$status->value}, not a real Filament palette color."
            );
        }
    }

    public function test_the_expected_color_per_status(): void
    {
        $this->assertSame('gray', $this->statusColor(OrderStatus::PLACED));
        $this->assertSame('gray', $this->statusColor(OrderStatus::CONFIRMED));
        $this->assertSame('gray', $this->statusColor(OrderStatus::SHIPPED));
        $this->assertSame('success', $this->statusColor(OrderStatus::DELIVERED));
        $this->assertSame('danger', $this->statusColor(OrderStatus::CANCELLED));
        $this->assertSame('warning', $this->statusColor(OrderStatus::REFUNDED));
    }

    /**
     * Proves the "one shared implementation, both places agree" requirement
     * directly against the real source, not just inferred from the reflection
     * calls above: both the table column and the infolist entry must call the
     * SAME static::statusColor($state) — never a second inline match.
     */
    public function test_the_table_column_and_infolist_entry_both_call_the_one_shared_helper(): void
    {
        $source = file_get_contents((new \ReflectionClass(OrderResource::class))->getFileName());

        $this->assertSame(
            1,
            substr_count($source, 'private static function statusColor('),
            'exactly one statusColor() implementation must exist.'
        );

        $this->assertSame(
            2,
            substr_count($source, 'static::statusColor($state)'),
            'static::statusColor($state) must be called from exactly two places: the table column and the infolist entry.'
        );
    }
}
