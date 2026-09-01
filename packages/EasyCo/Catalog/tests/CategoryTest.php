<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Category;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');

        $this->assertNull($category->id());
        $this->assertNull($category->parentId());
        $this->assertSame('Shoes', $category->name());
        $this->assertSame('shoes', $category->slug());
    }

    public function test_a_non_null_parent_id_is_returned_as_passed(): void
    {
        $category = new Category(id: null, parentId: '7', name: 'Running Shoes', slug: 'running-shoes');

        $this->assertSame('7', $category->parentId());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Category(id: null, parentId: null, name: '', slug: 'shoes');
    }

    public function test_a_cyrillic_slug_is_accepted(): void
    {
        $category = new Category(id: null, parentId: null, name: 'Обувки', slug: 'обувки');

        $this->assertSame('обувки', $category->slug());
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slug_formats_are_rejected(string $invalidSlug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Category(id: null, parentId: null, name: 'Shoes', slug: $invalidSlug);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidSlugs(): array
    {
        return [
            'uppercase Latin' => ['Nike-Air-Max'],
            'uppercase Cyrillic' => ['Червена-Рокля'],
            'spaces' => ['nike air max'],
            'leading hyphen' => ['-nike-air-max'],
            'trailing hyphen' => ['nike-air-max-'],
            'consecutive hyphens' => ['nike--air-max'],
            'slash' => ['nike/air-max'],
            'question mark' => ['nike?air-max'],
            'hash' => ['nike#air-max'],
            'percent' => ['nike%20air-max'],
            'ampersand' => ['nike&air-max'],
            'empty string' => [''],
        ];
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $category->assignId('1');

        $this->assertSame('1', $category->id());

        $this->expectException(LogicException::class);
        $category->assignId('2');
    }
}
