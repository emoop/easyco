<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Product;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProductDescriptiveAttributeTest extends TestCase
{
    use BuildsVariationAxes;

    public function test_descriptive_attributes_defaults_to_an_empty_array(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');

        $this->assertSame([], $product->descriptiveAttributes());
    }

    public function test_set_descriptive_attribute_for_text_succeeds_and_reflects_in_descriptive_attributes(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $material = new AttributeDefinition(id: '1', code: 'material', name: 'Material', type: AttributeType::TEXT);

        $product->setDescriptiveAttribute($material, 'Cotton');

        $this->assertSame(['1' => 'Cotton'], $product->descriptiveAttributes());
    }

    public function test_set_descriptive_attribute_for_number_succeeds(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $voltage = new AttributeDefinition(id: '1', code: 'voltage', name: 'Voltage', type: AttributeType::NUMBER);

        $product->setDescriptiveAttribute($voltage, '220');

        $this->assertSame(['1' => '220'], $product->descriptiveAttributes());
    }

    public function test_set_descriptive_attribute_for_boolean_succeeds(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $waterproof = new AttributeDefinition(id: '1', code: 'waterproof', name: 'Waterproof', type: AttributeType::BOOLEAN);

        $product->setDescriptiveAttribute($waterproof, '1');

        $this->assertSame(['1' => '1'], $product->descriptiveAttributes());
    }

    public function test_set_descriptive_attribute_for_select_with_a_real_attribute_value_succeeds(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $color = $this->selectAttribute('1', 'color');
        $black = $this->attributeValue('5', '1', 'Black');

        $product->setDescriptiveAttribute($color, $black);

        $this->assertSame(['1' => $black], $product->descriptiveAttributes());
    }

    public function test_set_descriptive_attribute_for_multiselect_always_throws(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $season = new AttributeDefinition(id: '1', code: 'season', name: 'Season', type: AttributeType::MULTISELECT);

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($season, 'Spring');
    }

    public function test_set_descriptive_attribute_for_a_definition_currently_used_as_this_products_variation_axis_throws(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $color = $this->selectAttribute('1', 'color');
        $black = $this->attributeValue('5', '1', 'Black');

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($color, $black);
    }

    public function test_set_descriptive_attribute_for_select_with_a_plain_string_throws(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $color = $this->selectAttribute('1', 'color');

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($color, 'Black');
    }

    public function test_set_descriptive_attribute_for_text_with_an_attribute_value_throws(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $material = new AttributeDefinition(id: '1', code: 'material', name: 'Material', type: AttributeType::TEXT);
        $someValue = $this->attributeValue('5', '1', 'Cotton');

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($material, $someValue);
    }

    public function test_set_descriptive_attribute_for_number_with_an_attribute_value_throws(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $voltage = new AttributeDefinition(id: '1', code: 'voltage', name: 'Voltage', type: AttributeType::NUMBER);
        $someValue = $this->attributeValue('5', '1', '220');

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($voltage, $someValue);
    }

    public function test_set_descriptive_attribute_for_boolean_with_an_attribute_value_throws(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $waterproof = new AttributeDefinition(id: '1', code: 'waterproof', name: 'Waterproof', type: AttributeType::BOOLEAN);
        $someValue = $this->attributeValue('5', '1', '1');

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($waterproof, $someValue);
    }

    public function test_set_descriptive_attribute_for_select_with_an_attribute_value_belonging_to_a_different_definition_throws(): void
    {
        $product = Product::createSimple('Plain Shirt', 'SKU-1', 'plain-shirt');
        $color = $this->selectAttribute('1', 'color');
        $wrongValue = $this->attributeValue('5', '2', 'Black'); // belongs to definition "2", not "1"

        $this->expectException(InvalidArgumentException::class);
        $product->setDescriptiveAttribute($color, $wrongValue);
    }

    public function test_has_variation_axis_is_false_for_a_definition_never_declared_as_an_axis(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $color = $this->selectAttribute('1', 'color');

        $this->assertFalse($product->hasVariationAxis($color));
    }

    public function test_has_variation_axis_is_true_for_a_declared_axis(): void
    {
        $product = Product::createVariable('T-Shirt', 'SKU-1', 't-shirt');
        $product->declareVariationAxes([$this->axis('1', 'color', ['5', '6'])]);
        $color = $this->selectAttribute('1', 'color');

        $this->assertTrue($product->hasVariationAxis($color));
    }
}
