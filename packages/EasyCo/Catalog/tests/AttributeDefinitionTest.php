<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\InvalidVariationAxisException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttributeDefinitionTest extends TestCase
{
    public function test_select_attribute_is_usable_as_variation_axis(): void
    {
        $color = new AttributeDefinition(id: '1', code: 'color', name: 'Color', type: AttributeType::SELECT);

        $color->assertUsableAsVariationAxis(); // does not throw
        $this->assertTrue(true);
    }

    #[DataProvider('nonSelectTypes')]
    public function test_non_select_attribute_is_rejected_as_variation_axis(AttributeType $type): void
    {
        $attribute = new AttributeDefinition(id: '1', code: 'material', name: 'Material', type: $type);

        $this->expectException(InvalidVariationAxisException::class);
        $attribute->assertUsableAsVariationAxis();
    }

    public static function nonSelectTypes(): array
    {
        return [
            [AttributeType::TEXT],
            [AttributeType::NUMBER],
            [AttributeType::BOOLEAN],
            [AttributeType::MULTISELECT],
        ];
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $attribute = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $attribute->assignId('9');

        $this->expectException(\LogicException::class);
        $attribute->assignId('10');
    }
}
