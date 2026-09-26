<?php

namespace App\Services;

use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Product;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE place a product's own status changes — the status field on its edit
 * page and the products list's bulk archive/publish actions both go through this
 * class, so the domain call, the save, the §3.19.7 media cleanup and the
 * activity-log entry cannot drift between the two surfaces.
 *
 * WHY THIS EXISTS AT ALL: archiving a product was never a one-line operation.
 * It is a domain transition (`Product::archive()`), a repository save, a real
 * on-disk media cleanup and a logged field change — four steps whose ORDER
 * matters (the cleanup must run after the media sync of the same submission,
 * see EditProduct::updateProduct()'s own comment), and a bulk action that
 * re-implemented them would be a second, subtly different archive.
 *
 * TWO ENTRY POINTS, DELIBERATELY:
 *
 *  - `archive()`/`publish()` are the WHOLE operation for a product this class may
 *    own end to end: load the aggregate fresh, transition, save, and clean up
 *    after an archive — each in its OWN transaction (one product per transaction,
 *    so one product's failure cannot take another's write with it), with the same
 *    `attempts: 3` deadlock retry the rest of this codebase uses on writes.
 *  - `applyStatus()` is the SAME status logic for a caller that already owns the
 *    aggregate and the write: EditProduct's update mutates a dozen other fields
 *    and saves ONCE, so it cannot hand the status over to a method that saves for
 *    itself. It runs no transaction and no save, and it logs exactly what the
 *    standalone operations log — one implementation of the rule, two callers with
 *    different write ownership.
 *
 * THE DOMAIN DECIDES WHAT IS ALLOWED, NEVER THIS CLASS: `publish()`'s own guard
 * (a VARIABLE product with no non-ARCHIVED STANDARD variation) throws
 * CannotPublishEmptyVariableProductException, and that exception is what a bulk
 * action reports per product. This class adds no rules of its own — it sequences
 * the ones the domain already has, which is also why a DRAFT -> DRAFT or
 * ARCHIVED -> ARCHIVED "transition" is a silent no-op here (no log entry, no
 * write), exactly as it is on the edit page today.
 */
final class ProductStatusChanger
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {
    }

    /**
     * Any status -> ARCHIVED, the whole operation for one product.
     *
     * @throws InvalidArgumentException When the product does not exist.
     */
    public function archive(string $productId): void
    {
        $product = $this->requireProduct($productId);

        DB::transaction(function () use ($product): void {
            $this->applyStatus($product, ProductStatus::ARCHIVED);

            $this->products->save($product);

            // §3.19.7's cleanup — the same call, in the same relative position,
            // that the edit page makes when its status field moves to Archived.
            $this->cleanArchivedMedia((string) $product->id());
        }, attempts: 3);
    }

    /**
     * DRAFT/ARCHIVED -> ACTIVE, the whole operation for one product.
     *
     * @throws \EasyCo\Catalog\Exceptions\CannotPublishEmptyVariableProductException When the domain's own publish guard refuses it.
     * @throws InvalidArgumentException When the product does not exist.
     */
    public function publish(string $productId): void
    {
        $product = $this->requireProduct($productId);

        DB::transaction(function () use ($product): void {
            $this->applyStatus($product, ProductStatus::ACTIVE);

            $this->products->save($product);
        }, attempts: 3);
    }

    /**
     * The status half of the operation, on an aggregate the CALLER owns and will
     * save — the log entry plus the domain's own transition, nothing else.
     *
     * NO TRANSACTION AND NO SAVE, deliberately: the caller may be the edit page,
     * whose submission writes every other field of the product in the same
     * request and saves once. Idempotent: an unchanged status logs nothing and
     * calls nothing, which is what makes "archive an already-archived product" a
     * safe no-op inside a bulk selection.
     */
    public function applyStatus(Product $product, ProductStatus $newStatus): void
    {
        $oldStatus = $product->status();

        if ($oldStatus === $newStatus) {
            return;
        }

        // Logged BEFORE the transition, so a domain refusal (publish()'s own
        // guard) is the only thing that can leave a log entry without a status
        // change — and that entry rolls back with the caller's transaction.
        app(ActivityLogger::class)->logFieldChanged(
            'product',
            $product->id(),
            'status',
            $oldStatus->value,
            $newStatus->value,
        );

        match ($newStatus) {
            ProductStatus::ACTIVE => $product->publish(),
            ProductStatus::ARCHIVED => $product->archive(),
            ProductStatus::DRAFT => $product->markAsDraft(),
        };
    }

    /**
     * Whether a move from $from to $to must run the archived-media cleanup — the
     * RULE, in one place, for a caller that has to run the cleanup itself later in
     * its own write (the edit page defers it deliberately: running it before its
     * media sync would re-create assets pointing at files the cleanup just
     * deleted, see that method's own comment).
     */
    public function requiresArchivedMediaCleanup(ProductStatus $from, ProductStatus $to): bool
    {
        return $from !== $to && $to === ProductStatus::ARCHIVED;
    }

    /** The cleanup itself, so the rule above and this call live together. */
    public function cleanArchivedMedia(string $productId): void
    {
        app(ArchiveProductMediaCleaner::class)->clean($productId);
    }

    private function requireProduct(string $productId): Product
    {
        $product = $this->products->findByIdWithVariations($productId);

        if ($product === null) {
            throw new InvalidArgumentException("Product \"{$productId}\" does not exist.");
        }

        return $product;
    }
}
