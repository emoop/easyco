<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Season;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeasonTest extends TestCase
{
    public function test_valid_construction_succeeds_and_getters_return_what_was_passed(): void
    {
        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');

        $this->assertNull($season->id());
        $this->assertSame('Spring/Summer 2026', $season->name());
        $this->assertSame('spring-summer-2026', $season->slug());
    }

    public function test_empty_name_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Season(id: null, name: '', slug: 'spring-summer-2026');
    }

    public function test_a_cyrillic_slug_is_accepted(): void
    {
        $season = new Season(id: null, name: 'Пролет-Лято 2026', slug: 'пролет-лято-2026');

        $this->assertSame('пролет-лято-2026', $season->slug());
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slug_formats_are_rejected(string $invalidSlug): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Season(id: null, name: 'Spring/Summer 2026', slug: $invalidSlug);
    }

    /** @return array<string, array{0: string}> */
    public static function invalidSlugs(): array
    {
        return [
            'uppercase Latin' => ['Spring-Summer-2026'],
            'uppercase Cyrillic' => ['Пролет-Лято-2026'],
            'spaces' => ['spring summer 2026'],
            'leading hyphen' => ['-spring-summer-2026'],
            'trailing hyphen' => ['spring-summer-2026-'],
            'consecutive hyphens' => ['spring--summer-2026'],
            'slash' => ['spring/summer-2026'],
            'question mark' => ['spring?summer-2026'],
            'hash' => ['spring#summer-2026'],
            'percent' => ['spring%20summer-2026'],
            'ampersand' => ['spring&summer-2026'],
            'empty string' => [''],
        ];
    }

    public function test_id_can_only_be_assigned_once(): void
    {
        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');
        $season->assignId('1');

        $this->assertSame('1', $season->id());

        $this->expectException(LogicException::class);
        $season->assignId('2');
    }

    public function test_rename_changes_the_name(): void
    {
        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');

        $season->rename('Spring/Summer 2027');

        $this->assertSame('Spring/Summer 2027', $season->name());
    }

    public function test_rename_reuses_the_constructors_own_empty_name_validation(): void
    {
        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');

        $this->expectException(InvalidArgumentException::class);
        $season->rename('');
    }

    public function test_change_slug_changes_the_slug(): void
    {
        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');

        $season->changeSlug('spring-summer-2027');

        $this->assertSame('spring-summer-2027', $season->slug());
    }

    public function test_change_slug_reuses_the_constructors_own_slug_format_validation(): void
    {
        $season = new Season(id: null, name: 'Spring/Summer 2026', slug: 'spring-summer-2026');

        $this->expectException(InvalidArgumentException::class);
        $season->changeSlug('Not A Valid Slug');
    }
}
