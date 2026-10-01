<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A refusal of the order edit dialog's own "add a product" half (stage
 * 4b-ii): the variation the merchant picked cannot be added to this order
 * at all, so nothing was written and the submission stops before
 * OrderEditor::apply() is ever reached.
 *
 * WHY A DEDICATED TYPE rather than InvalidArgumentException: this is the
 * one refusal on this page a merchant can trigger by a legitimately stale
 * form (the modal was open while someone archived the product), and it
 * deserves its own translated sentence — the same reason
 * ReturnExceedsRemainingQuantityException and StaleOrderEditException each
 * have one, rather than being flattened into the generic "could not be
 * processed" body.
 *
 * THE MESSAGES ARE TECHNICAL (for logs and failed assertions); the
 * merchant-facing wording lives in lang/{en,bg}/orders.php under
 * orders.actions.edit_add_unavailable_body — one sentence for every case,
 * because to the merchant they are one fact.
 */
final class OrderAddLineRefusedException extends RuntimeException
{
    public static function variationNotFound(string $variationId): self
    {
        return new self(
            "The variation \"{$variationId}\" being added to the order no longer exists."
        );
    }

    public static function variationNotSellable(string $variationId): self
    {
        return new self(
            "The variation \"{$variationId}\" being added is not effectively purchasable — its status is not active, ".
            'or it is marked as not purchasable: the same gate the storefront\'s own add-to-cart applies.'
        );
    }

    public static function productNotSellable(string $variationId): self
    {
        return new self(
            "The product owning variation \"{$variationId}\" being added is not active (draft or archived), ".
            'so it cannot be sold onto an order.'
        );
    }
}
