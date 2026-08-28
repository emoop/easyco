<?php

namespace EasyCo\Pricing\Tests;

use EasyCo\Pricing\Enums\PriceListScopeType;
use EasyCo\Pricing\PriceListScope;
use EasyCo\Pricing\PriceListSignature;
use PHPUnit\Framework\TestCase;

final class PriceListSignatureTest extends TestCase
{
    private function scope(PriceListScopeType $type, string $referenceId): PriceListScope
    {
        return new PriceListScope(id: null, priceListId: '', scopeType: $type, scopeReferenceId: $referenceId);
    }

    public function test_same_scopes_produce_same_signature_regardless_of_input_order(): void
    {
        $brand = $this->scope(PriceListScopeType::BRAND, 'guess');
        $attribute = $this->scope(PriceListScopeType::ATTRIBUTE_VALUE, 'summer-2026');

        $a = PriceListSignature::forScopes([$brand, $attribute]);
        $b = PriceListSignature::forScopes([$attribute, $brand]);

        $this->assertTrue($a->equals($b));
        $this->assertSame($a->value(), $b->value());
    }

    public function test_different_scope_reference_id_for_same_scope_type_produces_different_signature(): void
    {
        $guess = PriceListSignature::forScopes([$this->scope(PriceListScopeType::BRAND, 'guess')]);
        $nike = PriceListSignature::forScopes([$this->scope(PriceListScopeType::BRAND, 'nike')]);

        $this->assertFalse($guess->equals($nike));
    }

    public function test_different_scope_type_for_same_reference_id_produces_different_signature(): void
    {
        $brand = PriceListSignature::forScopes([$this->scope(PriceListScopeType::BRAND, '42')]);
        $category = PriceListSignature::forScopes([$this->scope(PriceListScopeType::CATEGORY, '42')]);

        $this->assertFalse($brand->equals($category));
    }

    public function test_different_number_of_scopes_produces_different_signature(): void
    {
        $brandOnly = PriceListSignature::forScopes([$this->scope(PriceListScopeType::BRAND, 'guess')]);
        $brandAndAttribute = PriceListSignature::forScopes([
            $this->scope(PriceListScopeType::BRAND, 'guess'),
            $this->scope(PriceListScopeType::ATTRIBUTE_VALUE, 'summer-2026'),
        ]);

        $this->assertFalse($brandOnly->equals($brandAndAttribute));
    }

    public function test_signature_is_a_64_character_hex_sha256(): void
    {
        $signature = PriceListSignature::forScopes([$this->scope(PriceListScopeType::BRAND, 'guess')]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature->value());
    }

    public function test_universal_scope_signature_is_a_fixed_constant_independent_of_input(): void
    {
        $first = PriceListSignature::forUniversalScope();
        $second = PriceListSignature::forUniversalScope();

        $this->assertTrue($first->equals($second));
    }

    public function test_universal_scope_signature_never_collides_with_a_real_scope_set(): void
    {
        // Extremely unlikely by construction (different hashed input), but
        // asserting it documents the invariant explicitly rather than
        // leaving it implicit.
        $universal = PriceListSignature::forUniversalScope();
        $real = PriceListSignature::forScopes([$this->scope(PriceListScopeType::BRAND, 'guess')]);

        $this->assertFalse($universal->equals($real));
    }

    public function test_for_scopes_with_an_empty_array_matches_for_universal_scope_directly(): void
    {
        // Deliberate departure from VariationSignature::forCombination(),
        // which throws on an empty array instead — see this class's own
        // docblock for why. Both paths to "no scope conditions" must be
        // indistinguishable at the signature level.
        $viaEmptyArray = PriceListSignature::forScopes([]);
        $direct = PriceListSignature::forUniversalScope();

        $this->assertTrue($viaEmptyArray->equals($direct));
        $this->assertSame($direct->value(), $viaEmptyArray->value());
    }

    public function test_a_duplicated_scope_pair_produces_the_same_signature_as_if_it_appeared_once(): void
    {
        $once = PriceListSignature::forScopes([
            $this->scope(PriceListScopeType::BRAND, 'guess'),
        ]);

        $duplicated = PriceListSignature::forScopes([
            $this->scope(PriceListScopeType::BRAND, 'guess'),
            $this->scope(PriceListScopeType::BRAND, 'guess'),
        ]);

        $this->assertTrue($once->equals($duplicated));
        $this->assertSame($once->value(), $duplicated->value());
    }
}
