<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\ProductGroup;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductGroupTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');

        $this->assertNull($group->id());
        $this->assertSame('shoes', $group->code());
        $this->assertSame('Обувки', $group->name());
    }

    public function test_empty_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductGroup(id: null, code: '', name: 'Обувки');
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductGroup(id: null, code: 'shoes', name: '');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');
        $group->assignId('1');

        $this->assertSame('1', $group->id());

        $this->expectException(LogicException::class);
        $group->assignId('2');
    }

    public function test_rename_changes_the_name(): void
    {
        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');

        $group->rename('Обувки и ботуши');

        $this->assertSame('Обувки и ботуши', $group->name());
    }

    public function test_rename_reuses_the_constructors_own_empty_name_validation(): void
    {
        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');

        $this->expectException(InvalidArgumentException::class);
        $group->rename('');
    }

    public function test_code_stays_unchanged_after_rename(): void
    {
        $group = new ProductGroup(id: null, code: 'shoes', name: 'Обувки');

        $group->rename('Обувки и ботуши');

        $this->assertSame('shoes', $group->code());
    }
}
