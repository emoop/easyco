<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use EasyCo\Catalog\VariationAxis;
use PHPUnit\Framework\TestCase;

final class VariationAxisTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_valid_axis_with_allowed_values(): void
    {
        $axis = $this->axis('1', 'color', ['5', '6']);

        $this->assertSame('1', $axis->attributeDefinitionId());
        $this->assertSame('color', $axis->attributeDefinitionCode());
        $this->assertTrue($axis->isAllowedValueId('5'));
        $this->assertTrue($axis->isAllowedValueId('6'));
        $this->assertFalse($axis->isAllowedValueId('999'));
        $this->assertSame(['5', '6'], $axis->allowedValueIds());
    }

    public function test_non_select_attribute_cannot_become_an_axis(): void
    {
        $material = new AttributeDefinition(id: '1', code: 'material', name: 'Material', type: AttributeType::TEXT);

        $this->expectException(InvalidVariationAxisException::class);
        new VariationAxis($material, []);
    }

    public function test_value_belonging_to_a_different_attribute_definition_is_rejected(): void
    {
        $color = new AttributeDefinition(id: '1', code: 'color', name: 'Color', type: AttributeType::SELECT);
        // This value claims to belong to definition "2" (Material), not "1" (Color).
        $wrongValue = new AttributeValue(id: '5', attributeDefinitionId: '2', value: 'Cotton');

        $this->expectException(InvalidVariationAxisException::class);
        new VariationAxis($color, [$wrongValue]);
    }

    public function test_axis_with_no_allowed_values_is_rejected(): void
    {
        $color = new AttributeDefinition(id: '1', code: 'color', name: 'Color', type: AttributeType::SELECT);

        $this->expectException(InvalidVariationAxisException::class);
        new VariationAxis($color, []);
    }

    public function test_unpersisted_attribute_definition_cannot_become_an_axis(): void
    {
        $color = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $value = new AttributeValue(id: '5', attributeDefinitionId: '1', value: 'Black');

        $this->expectException(\LogicException::class);
        new VariationAxis($color, [$value]);
    }
}
