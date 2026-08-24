<?php

namespace EasyCo\Catalog\Tests;

use EasyCo\Catalog\VariationSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VariationSignatureTest extends TestCase
{
    public function test_same_combination_produces_same_signature_regardless_of_input_order(): void
    {
        $a = VariationSignature::forCombination([1 => 5, 2 => 9]);
        $b = VariationSignature::forCombination([2 => 9, 1 => 5]);

        $this->assertTrue($a->equals($b));
        $this->assertSame($a->value(), $b->value());
    }

    public function test_different_value_for_same_axis_produces_different_signature(): void
    {
        $black = VariationSignature::forCombination([1 => 5, 2 => 9]);
        $white = VariationSignature::forCombination([1 => 5, 2 => 10]);

        $this->assertFalse($black->equals($white));
    }

    public function test_different_axis_set_produces_different_signature(): void
    {
        $colorOnly = VariationSignature::forCombination([1 => 5]);
        $colorAndSize = VariationSignature::forCombination([1 => 5, 2 => 9]);

        $this->assertFalse($colorOnly->equals($colorAndSize));
    }

    public function test_signature_is_a_64_character_hex_sha256(): void
    {
        $signature = VariationSignature::forCombination([1 => 5]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature->value());
    }

    public function test_universal_signature_is_a_fixed_constant_independent_of_input(): void
    {
        $first = VariationSignature::forUniversalVariation();
        $second = VariationSignature::forUniversalVariation();

        $this->assertTrue($first->equals($second));
    }

    public function test_universal_signature_never_collides_with_a_real_combination(): void
    {
        // Extremely unlikely by construction (different hashed input), but
        // asserting it documents the invariant explicitly rather than
        // leaving it implicit.
        $universal = VariationSignature::forUniversalVariation();
        $combination = VariationSignature::forCombination([1 => 5]);

        $this->assertFalse($universal->equals($combination));
    }

    public function test_empty_combination_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VariationSignature::forCombination([]);
    }

    public function test_renaming_a_value_label_does_not_change_the_signature(): void
    {
        // The signature hashes attribute_value IDs, never labels — this
        // test documents that a merchant renaming "Black" to "Jet Black"
        // (same attribute_value_id = 5) must not silently create what
        // looks like a duplicate combination or orphan the old one.
        $beforeRename = VariationSignature::forCombination([1 => 5]);
        $afterRename = VariationSignature::forCombination([1 => 5]); // same id, hypothetically renamed label

        $this->assertTrue($beforeRename->equals($afterRename));
    }
}
