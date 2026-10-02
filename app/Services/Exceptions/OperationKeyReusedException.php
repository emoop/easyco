<?php

namespace App\Services\Exceptions;

use RuntimeException;

/**
 * The same operation key was submitted again with DIFFERENT contents (other
 * lines, amounts, shipping, deduction, channel or action). A repeat of the same
 * key AND the same contents is a double submit and returns the first result; a
 * changed one is not the same operation, and silently returning the first
 * result would hide that the merchant's new decision was ignored
 * (shipping-domain-design.md §7.2.3, owner decision R1a-4).
 */
final class OperationKeyReusedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('orders.operation_key_reused'));
    }
}
