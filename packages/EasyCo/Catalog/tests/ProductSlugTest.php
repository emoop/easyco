<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\Product;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers Product::slug() as a first-class domain concept — see
 * catalog-domain-design.md's slug section. Slugs are validated but NOT
 * forced to ASCII/Latin: any lowercase Unicode script is accepted,
 * following WordPress's sanitize_title() philosophy. ASCII
 * transliteration is a separate, optional, future hook-listener concern,
 * not domain validation — see Product::assertValidSlug()'s docblock.
 */
final class ProductSlugTest extends TestCase
{
    public function test_a_cyrillic_slug_is_accepted(): void
    {
        $product = Product::createSimple('Червена рокля', 'SKU-1', 'червена-рокля');

        $this->assertSame('червена-рокля', $product->slug());
    }

    public function test_a_turkish_diacritic_slug_is_accepted(): void
    {
        $product = Product::createSimple('Kırmızı Elbise', 'SKU-1', 'kırmızı-elbise');

        $this->assertSame('kırmızı-elbise', $product->slug());
    }

    #[DataProvider('invalidSlugs')]
    public function test_invalid_slug_formats_are_rejected(string $invalidSlug): void
    {
        $this->expectException(InvalidArgumentException::class);
        Product::createSimple('Nike Air Max', 'SKU-1', $invalidSlug);
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

    public function test_change_slug_updates_to_a_new_valid_value(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->changeSlug('nike-air-max-2026');

        $this->assertSame('nike-air-max-2026', $product->slug());
    }

    public function test_change_slug_rejects_an_invalid_new_value(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $this->expectException(InvalidArgumentException::class);
        $product->changeSlug('Invalid Slug!');
    }

    public function test_change_slug_leaves_the_slug_untouched_after_a_rejected_change(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        try {
            $product->changeSlug('--bad--');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame('nike-air-max', $product->slug(), 'a rejected change must leave the original slug untouched');
    }

    public function test_change_slug_with_the_exact_same_value_is_a_harmless_no_op(): void
    {
        $product = Product::createSimple('Nike Air Max', 'SKU-1', 'nike-air-max');

        $product->changeSlug('nike-air-max');

        $this->assertSame('nike-air-max', $product->slug());
    }
}
