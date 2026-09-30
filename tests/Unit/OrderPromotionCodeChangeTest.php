<?php

namespace Tests\Unit;

use App\Services\OrderPromotionCodeChange;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * order-editing-design.md §1/§7 — the three-state promotion-code change:
 * unchanged, set (with a code) and removed are three DISTINCT states, which
 * is the whole reason it is not a bare nullable string.
 */
class OrderPromotionCodeChangeTest extends TestCase
{
    public function test_each_factory_is_exactly_one_state(): void
    {
        $unchanged = OrderPromotionCodeChange::unchanged();
        $set = OrderPromotionCodeChange::set('SUMMER');
        $removed = OrderPromotionCodeChange::removed();

        $this->assertSame([true, false, false], [$unchanged->isUnchanged(), $unchanged->isSet(), $unchanged->isRemoved()]);
        $this->assertSame([false, true, false], [$set->isUnchanged(), $set->isSet(), $set->isRemoved()]);
        $this->assertSame([false, false, true], [$removed->isUnchanged(), $removed->isSet(), $removed->isRemoved()]);
    }

    public function test_set_carries_its_code(): void
    {
        $this->assertSame('SUMMER', OrderPromotionCodeChange::set('SUMMER')->code());
    }

    public function test_set_refuses_a_blank_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderPromotionCodeChange::set('  ');
    }

    public function test_code_is_undefined_for_unchanged_and_removed(): void
    {
        foreach ([OrderPromotionCodeChange::unchanged(), OrderPromotionCodeChange::removed()] as $change) {
            try {
                $change->code();
                $this->fail('code() must be refused unless the change is set().');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
