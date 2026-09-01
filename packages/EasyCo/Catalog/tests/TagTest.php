<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Tag;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TagTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');

        $this->assertNull($tag->id());
        $this->assertSame('Summer', $tag->name());
        $this->assertSame('summer', $tag->slug());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Tag(id: null, name: '', slug: 'summer');
    }

    public function test_a_cyrillic_slug_is_accepted(): void
    {
        $tag = new Tag(id: null, name: 'Лято', slug: 'лято');

        $this->assertSame('лято', $tag->slug());
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slug_formats_are_rejected(string $invalidSlug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Tag(id: null, name: 'Summer', slug: $invalidSlug);
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
        $tag = new Tag(id: null, name: 'Summer', slug: 'summer');
        $tag->assignId('1');

        $this->assertSame('1', $tag->id());

        $this->expectException(LogicException::class);
        $tag->assignId('2');
    }
}
