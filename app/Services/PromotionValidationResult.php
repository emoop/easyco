<?php

namespace App\Services;

/**
 * The outcome of PromotionValidator::validate() — see that class's own
 * docblock for the full set of machine-stable reason() codes.
 */
final class PromotionValidationResult
{
    /** @param string[] $applicableVariationIds */
    private function __construct(
        private readonly bool $isValid,
        private readonly ?string $reason,
        private readonly array $applicableVariationIds,
    ) {
    }

    /** @param string[] $applicableVariationIds */
    public static function valid(array $applicableVariationIds): self
    {
        return new self(isValid: true, reason: null, applicableVariationIds: $applicableVariationIds);
    }

    public static function invalid(string $reason): self
    {
        return new self(isValid: false, reason: $reason, applicableVariationIds: []);
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Which cart lines the discount should actually apply to — only
     * meaningful when isValid() is true; always empty otherwise.
     *
     * @return string[]
     */
    public function applicableVariationIds(): array
    {
        return $this->applicableVariationIds;
    }
}
