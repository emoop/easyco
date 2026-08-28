<?php

namespace EasyCo\Pricing;

/**
 * Deterministic, normalized signature of a PriceList's exact scope
 * condition set — see pricing-persistence-domain-design.md §4.7.
 *
 * WHY THIS EXISTS:
 * "Two lists with the exact identical scope condition set must not
 * exist twice at the same priority" needs a single comparable value the
 * database can put a UNIQUE INDEX on (`UNIQUE(priority, scope_signature)`
 * on `pricing_price_lists`). The source data (which scope rows belong
 * to a list) lives in a *child* table (pricing_price_list_scopes), not
 * as inline columns on pricing_price_lists — so it cannot be a
 * MySQL/Postgres STORED GENERATED COLUMN (those can only read other
 * columns in the same row). Instead this class computes the signature
 * in the application layer, deterministically, and the caller persists
 * it as a plain column alongside the list. Direct precedent, mirrored
 * structurally on purpose: `Catalog\VariationSignature` — same
 * private-constructor / named-static-factory / value()/equals() shape,
 * same sha256-of-a-sorted-canonical-string algorithm.
 *
 * ONE DELIBERATE DEPARTURE FROM VariationSignature, DOCUMENTED HERE
 * EXPLICITLY: `VariationSignature::forCombination([])` throws — the
 * "no axes" case is never routed through it at all, because Catalog's
 * own caller always knows in advance which case applies (a SIMPLE
 * product's Universal variation vs. a VARIABLE product's real
 * combination) and calls `forUniversalVariation()` directly. Here, the
 * future PriceList repository will simply have *some*
 * `PriceListScope[]` collection for a given list — sometimes empty
 * (§3: "Zero scopes = applies universally"), sometimes not — without
 * knowing in advance which case it is. So `forScopes([])` does NOT
 * throw: it delegates internally to `forUniversalScope()`, and both
 * that direct call and the empty-array path through `forScopes()`
 * produce an IDENTICAL signature — verified by
 * PriceListSignatureTest::test_for_scopes_with_an_empty_array_matches_for_universal_scope_directly().
 */
final class PriceListSignature
{
    /**
     * Fixed signature for a PriceList with zero scope conditions (§3:
     * "Zero scopes = applies universally"). Using a constant (rather
     * than an empty-array hash — which would, incidentally, produce the
     * exact same value here, since forScopes([]) delegates to this
     * method) means `UNIQUE(priority, scope_signature)` itself already
     * enforces "at most one universally-applying list per priority",
     * mirroring VariationSignature::UNIVERSAL's own side effect on
     * `UNIQUE(product_id, attribute_signature)`.
     */
    private const UNIVERSAL = 'universal';

    private function __construct(
        private readonly string $value,
    ) {
    }

    public static function forUniversalScope(): self
    {
        return new self(self::hash(self::UNIVERSAL));
    }

    /**
     * @param PriceListScope[] $scopes May be empty — delegates to
     *   forUniversalScope() rather than throwing (see class docblock).
     *   Duplicate (scopeType, scopeReferenceId) pairs are deduplicated
     *   before hashing: a PriceList with an accidentally-duplicated
     *   scope row and one without it represent a functionally identical
     *   condition (§4.1's AND logic is unchanged by a duplicate — a
     *   condition ANDed with itself is still just that one condition),
     *   so they must produce the same signature. PriceListScope itself
     *   deliberately does not guard against this — see that class's own
     *   "explicitly not this class's job" docblock note — so this is
     *   the one place that actually has visibility across the whole
     *   collection to normalize it.
     */
    public static function forScopes(array $scopes): self
    {
        if ($scopes === []) {
            return self::forUniversalScope();
        }

        $canonicalPairs = [];
        foreach ($scopes as $scope) {
            $canonicalPairs[$scope->scopeType()->value.':'.$scope->scopeReferenceId()] = true;
        }

        // Deduplicated (array keys are unique by construction above) and
        // sorted alphabetically for determinism regardless of input
        // order — mirrors VariationSignature::forCombination()'s ksort()
        // step, adapted to a composite string key instead of a numeric
        // attribute_definition_id.
        $canonical = array_keys($canonicalPairs);
        sort($canonical, SORT_STRING);

        return new self(self::hash(implode('|', $canonical)));
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    private static function hash(string $canonical): string
    {
        return hash('sha256', $canonical);
    }
}
