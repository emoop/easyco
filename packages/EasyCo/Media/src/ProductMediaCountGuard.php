<?php

namespace EasyCo\Media;

use EasyCo\Media\Contracts\ProductMediaRepository;
use EasyCo\Media\Exceptions\MediaLimitExceededException;

/**
 * Write-time check for the max-media-per-product limit — see
 * media-domain-design.md §6. Framework-agnostic: takes the limit as a
 * plain int via the constructor rather than reading config() itself,
 * mirroring EasyCo\Pricing\DefaultCurrency's "config is read at the
 * Laravel wiring boundary only" principle — only MediaServiceProvider
 * touches config().
 */
final class ProductMediaCountGuard
{
    public function __construct(
        private readonly ProductMediaRepository $productMediaRepository,
        private readonly int $maxMediaCount,
    ) {
    }

    /**
     * >= is deliberate, not > : at exactly maxMediaCount already
     * attached, the next attach is rejected; at maxMediaCount - 1, the
     * next attach lands exactly on the limit and is allowed.
     */
    public function assertCanAttach(string $productId): void
    {
        $currentCount = $this->productMediaRepository->countByProductId($productId);

        if ($currentCount >= $this->maxMediaCount) {
            throw MediaLimitExceededException::forProduct($productId, $currentCount, $this->maxMediaCount);
        }
    }
}
