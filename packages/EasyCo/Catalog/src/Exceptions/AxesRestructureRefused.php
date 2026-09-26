<?php

namespace EasyCo\Catalog\Exceptions;

use EasyCo\Catalog\Enums\AxesRestructureRefusal;
use RuntimeException;

/**
 * Thrown — or carried inside the impact report — when the axis-restructure
 * flow cannot carry a proposed axis re-declaration out
 * (catalog-domain-design.md §3.19.8 C). It wraps, rather than replaces, the
 * domain's own answers: `Product::declareVariationAxes()` is the ONE place
 * that decides whether a set may be declared (its structural rules and
 * §3.17's R1–R5 guard), and this exception only adds what the merchant-facing
 * surface needs — a reason code to localise, and the product's name.
 *
 * `detail` KEEPS THE DOMAIN'S OWN SENTENCE where the domain is the only thing
 * that knows the answer (a structurally refused set: only the domain knows
 * WHICH rule failed, and §3.19.8 D's posture is that a refusal is shown
 * rather than paraphrased). It is null for PLAN_CHANGED: there the merchant's
 * actionable fact is simply that the product changed and nothing was done,
 * and the guard's own message would name live variation ids — developer-
 * facing detail that has no place in a merchant's sentence.
 *
 * The exception's OWN message stays English on purpose, like
 * VariationNotDeletableException's and ProductNotDeletableException's: it is
 * what logs, exception dumps and support tickets carry, while
 * App\Services\AxesRestructureRefusalMessage renders the merchant's locale.
 */
final class AxesRestructureRefused extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly AxesRestructureRefusal $reason,
        public readonly string $productName,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    public static function becauseTheAxesCannotBeDeclared(string $productName, string $detail): self
    {
        return new self(
            "The proposed variation axes for \"{$productName}\" cannot be declared: {$detail}",
            AxesRestructureRefusal::INVALID_AXES,
            $productName,
            $detail,
        );
    }

    /**
     * The plan the merchant confirmed is not the plan that would now be
     * executed — the variation set changed, whichever of the two detection
     * points noticed it (the fingerprint comparison, or the domain guard
     * refusing the re-declaration after the deletions).
     */
    public static function becauseThePlanChanged(string $productName): self
    {
        return new self(
            "Product \"{$productName}\" changed since its axis change was confirmed, so the confirmed plan is no ".
            'longer the plan that would be executed. Nothing was changed.',
            AxesRestructureRefusal::PLAN_CHANGED,
            $productName,
        );
    }
}
