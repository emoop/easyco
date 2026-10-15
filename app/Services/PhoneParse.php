<?php

namespace App\Services;

/** A VALID phone number as PhoneNumbers read it: its E.164 form, the region it belongs to, the libphonenumber type name and whether it is a fixed line. */
final class PhoneParse
{
    public function __construct(
        public readonly string $e164,
        public readonly string $region,
        public readonly string $type,
        public readonly bool $isFixedLine,
    ) {
    }
}
