<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * A rule of the merchant's per-country invoice configuration refused the details (order-context-design.md 9.3, 9.7): thrown by
 * InvoiceRequestChecker BEFORE the placement transaction opens, so nothing was written. It carries the dotted field (`invoice.tax_id`) and the
 * ready, translated sentence (which names the field by the CONFIGURED label); nothing the customer typed is ever in either.
 */
final class InvoiceDetailsInvalidException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        public readonly string $sentence,
    ) {
        parent::__construct('The invoice details were refused: '.$field);
    }
}
