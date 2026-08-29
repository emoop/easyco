<?php

namespace EasyCo\Media;

use EasyCo\Media\Contracts\VariationMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;

/**
 * Write-time check for the max-media-per-variation limit — see
 * media-domain-design.md §6. Framework-agnostic: takes the limit as a
 * plain int via the constructor rather than reading config() itself,
 * mirroring EasyCo\Pricing\DefaultCurrency's "config is read at the
 * Laravel wiring boundary only" principle — only MediaServiceProvider
 * touches config().
 */
final class VariationMediaCountGuard
{
    public function __construct(
        private readonly VariationMediaRepository $variationMediaRepository,
        private readonly int $maxMediaCount,
    ) {
    }

    /**
     * >= is deliberate, not > : at exactly maxMediaCount already
     * attached, the next attach is rejected; at maxMediaCount - 1, the
     * next attach lands exactly on the limit and is allowed.
     */
    public function assertCanAttach(string $variationId): void
    {
        $currentCount = $this->variationMediaRepository->countByVariationId($variationId);

        if ($currentCount >= $this->maxMediaCount) {
            throw MediaLimitExceededException::forVariation($variationId, $currentCount, $this->maxMediaCount);
        }
    }
}
