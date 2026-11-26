<?php

namespace EasyCo\Shipping\Carrier;

use EasyCo\Shipping\Exceptions\InvalidCarrierDataException;

/** A printable label: the PDF bytes. Never printed in a dump. */
final class LabelDocument
{
    public function __construct(public readonly string $pdf)
    {
        if ($pdf === '') {
            throw InvalidCarrierDataException::field(self::class, 'pdf', 'must not be empty');
        }
    }

    public function __debugInfo(): array
    {
        return ['bytes' => strlen($this->pdf)];
    }
}
