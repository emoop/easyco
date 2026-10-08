<?php

namespace App\Services;

/**
 * What the shipping-class form (or any other caller) asks for, as plain values. ShippingClassWriter validates all of
 * it again; this class decides nothing. The code is read only on create — an update that names a different code is
 * refused (a class's code never changes).
 */
final class ShippingClassInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly ?string $description = null,
    ) {
    }
}
