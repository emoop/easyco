<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\AttributeValue;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AttributeValueTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $value = new AttributeValue(id: null, attributeDefinitionId: '1', value: 'Black', sortOrder: 3);

        $this->assertNull($value->id());
        $this->assertSame('1', $value->attributeDefinitionId());
        $this->assertSame('Black', $value->value());
        $this->assertSame(3, $value->sortOrder());
    }

    public function test_sort_order_defaults_to_zero(): void
    {
        $value = new AttributeValue(id: null, attributeDefinitionId: '1', value: 'Black');

        $this->assertSame(0, $value->sortOrder());
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $value = new AttributeValue(id: null, attributeDefinitionId: '1', value: 'Black');
        $value->assignId('1');

        $this->assertSame('1', $value->id());

        $this->expectException(LogicException::class);
        $value->assignId('2');
    }

    public function test_rename_changes_the_value(): void
    {
        $value = new AttributeValue(id: null, attributeDefinitionId: '1', value: 'Black');

        $value->rename('Jet Black');

        $this->assertSame('Jet Black', $value->value());
    }

    public function test_change_sort_order_changes_it(): void
    {
        $value = new AttributeValue(id: null, attributeDefinitionId: '1', value: 'Black', sortOrder: 0);

        $value->changeSortOrder(5);

        $this->assertSame(5, $value->sortOrder());
    }

    /**
     * The constructor validates nothing about sortOrder (not even
     * non-negativity), so changeSortOrder() doesn't invent validation
     * it doesn't have either — a negative value is accepted, same as
     * construction would accept it.
     */
    public function test_change_sort_order_accepts_a_negative_number_since_the_constructor_does_too(): void
    {
        $value = new AttributeValue(id: null, attributeDefinitionId: '1', value: 'Black');

        $value->changeSortOrder(-1);

        $this->assertSame(-1, $value->sortOrder());
    }
}
