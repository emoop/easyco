<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Brand;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrandTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $this->assertNull($brand->id());
        $this->assertSame('Nike', $brand->name());
        $this->assertSame('nike', $brand->slug());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Brand(id: null, name: '', slug: 'nike');
    }

    public function test_a_cyrillic_slug_is_accepted(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'найк');

        $this->assertSame('найк', $brand->slug());
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slug_formats_are_rejected(string $invalidSlug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Brand(id: null, name: 'Nike', slug: $invalidSlug);
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
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brand->assignId('1');

        $this->assertSame('1', $brand->id());

        $this->expectException(LogicException::class);
        $brand->assignId('2');
    }

    public function test_rename_changes_the_name(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $brand->rename('Nike Inc.');

        $this->assertSame('Nike Inc.', $brand->name());
    }

    public function test_rename_reuses_the_constructors_own_empty_name_validation(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $this->expectException(InvalidArgumentException::class);
        $brand->rename('');
    }

    public function test_change_slug_changes_the_slug(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $brand->changeSlug('nike-inc');

        $this->assertSame('nike-inc', $brand->slug());
    }

    public function test_change_slug_reuses_the_constructors_own_slug_format_validation(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $this->expectException(InvalidArgumentException::class);
        $brand->changeSlug('Not A Valid Slug');
    }

    public function test_a_brand_has_no_logo_by_default(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $this->assertNull($brand->logoMediaAssetId());
    }

    public function test_set_logo_with_an_empty_id_throws(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $this->expectException(InvalidArgumentException::class);
        $brand->setLogo('');
    }

    public function test_set_logo_with_a_real_id_succeeds(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');

        $brand->setLogo('42');

        $this->assertSame('42', $brand->logoMediaAssetId());
    }

    public function test_remove_logo_sets_it_back_to_null(): void
    {
        $brand = new Brand(id: null, name: 'Nike', slug: 'nike');
        $brand->setLogo('42');

        $brand->removeLogo();

        $this->assertNull($brand->logoMediaAssetId());
    }
}
