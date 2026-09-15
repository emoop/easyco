<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\ProductTemplate;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductTemplateTest extends TestCase
{
    public function test_construction_with_all_null_optionals_succeeds(): void
    {
        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');

        $this->assertNull($template->id());
        $this->assertSame('Basic Shoe Template', $template->name());
        $this->assertNull($template->brandId());
        $this->assertNull($template->seasonId());
        $this->assertNull($template->productGroupId());
        $this->assertSame([], $template->categoryIds());
        $this->assertSame([], $template->tagIds());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProductTemplate(id: null, name: '');
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');
        $template->assignId('1');

        $this->assertSame('1', $template->id());

        $this->expectException(LogicException::class);
        $template->assignId('2');
    }

    public function test_rename_changes_the_name(): void
    {
        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');

        $template->rename('Premium Shoe Template');

        $this->assertSame('Premium Shoe Template', $template->name());
    }

    public function test_rename_reuses_the_constructors_own_empty_name_validation(): void
    {
        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');

        $this->expectException(InvalidArgumentException::class);
        $template->rename('');
    }

    public function test_change_defaults_replaces_all_five_at_once(): void
    {
        $template = new ProductTemplate(id: null, name: 'Basic Shoe Template');

        $template->changeDefaults(
            brandId: '10',
            seasonId: '20',
            productGroupId: '30',
            categoryIds: ['1', '2'],
            tagIds: ['5'],
        );

        $this->assertSame('10', $template->brandId());
        $this->assertSame('20', $template->seasonId());
        $this->assertSame('30', $template->productGroupId());
        $this->assertSame(['1', '2'], $template->categoryIds());
        $this->assertSame(['5'], $template->tagIds());
    }
}
