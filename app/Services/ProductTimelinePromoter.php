<?php

namespace App\Services;

use App\Services\Exceptions\CannotPromoteArchivedProductException;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Product;
use RuntimeException;

/**
 * "Move to front" / "undo" — the merchant-facing product timeline
 * promotion operation, app layer (a merchandising operation, not a
 * Product domain invariant — mirrors DuplicateProduct's own "app-layer
 * orchestration, not a Product method" precedent for the SAME reason:
 * Product::promote()/unpromote() already exist and do the real work,
 * this class is composition — resolve, guard, mutate, persist, log).
 *
 * $at IS AN EXPLICIT, REQUIRED PARAMETER on promote() — never an
 * internal now() — the same convention this codebase's own
 * CheckoutOrchestrator::place(CheckoutInput $input, DateTimeImmutable
 * $placedAt) already documents ("trivially testable with a fixed
 * instant"). unpromote() needs no such parameter: it always resets to
 * the Product's own createdAt, a value already fixed at construction.
 *
 * Each REAL change is recorded through App\Services\ActivityLogger's
 * real field-change API (field 'timeline_at', old -> new, both as
 * DATE_ATOM strings — logFieldChanged() takes plain ?string values, no
 * DateTimeImmutable overload). unpromote() on an already-un-promoted
 * product is a genuine no-op: no repository save(), no log entry —
 * checked via Product::isPromoted() itself before touching anything,
 * so a merchant clicking "Undo promote" twice in a row (a stale button
 * still visible, a double click) never produces a spurious log entry
 * or an unnecessary write.
 *
 * Promoting an ARCHIVED product is refused
 * (CannotPromoteArchivedProductException) — an archived product has no
 * merchant-facing timeline position worth moving. This check lives HERE,
 * not inside Product::promote() itself: it is a merchandising/display
 * policy ("why this is refused"), not a data-integrity invariant about
 * the timeline mechanics themselves — Product::promote()'s own docblock
 * is explicit that the entity "knows only *when*, never *why*."
 */
final class ProductTimelinePromoter
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    public function promote(string $productId, DateTimeImmutable $at): void
    {
        $product = $this->findOrFail($productId);

        if ($product->status() === ProductStatus::ARCHIVED) {
            throw new CannotPromoteArchivedProductException($productId);
        }

        $oldTimelineAt = $product->timelineAt();

        $product->promote($at);

        $this->products->save($product);

        $this->activityLogger->logFieldChanged(
            'product',
            $productId,
            'timeline_at',
            $oldTimelineAt->format(\DATE_ATOM),
            $product->timelineAt()->format(\DATE_ATOM)
        );
    }

    public function unpromote(string $productId): void
    {
        $product = $this->findOrFail($productId);

        if (! $product->isPromoted()) {
            return;
        }

        $oldTimelineAt = $product->timelineAt();

        $product->unpromote();

        $this->products->save($product);

        $this->activityLogger->logFieldChanged(
            'product',
            $productId,
            'timeline_at',
            $oldTimelineAt->format(\DATE_ATOM),
            $product->timelineAt()->format(\DATE_ATOM)
        );
    }

    private function findOrFail(string $productId): Product
    {
        $product = $this->products->findById($productId);

        if ($product === null) {
            throw new RuntimeException("Product \"{$productId}\" could not be found.");
        }

        return $product;
    }
}
