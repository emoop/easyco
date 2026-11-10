<?php

namespace App\Services\MassEdit;

use RuntimeException;

/**
 * P6d: a mass edit that may not run. `$reason` is one of the keys under `product_mass.refused` (none, unfiltered, too_many, unknown_value, field_off, group_required).
 */
final class MassEditRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Mass edit refused: '.$reason);
    }
}
