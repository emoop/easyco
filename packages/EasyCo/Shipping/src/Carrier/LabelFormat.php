<?php

namespace EasyCo\Shipping\Carrier;

/** Label paper (courier-integration-design.md Q7: A4 is the default for every courier; the others are per-courier alternatives). */
enum LabelFormat: string
{
    case A4 = 'a4';
    case A6 = 'a6';
    case A4_4XA6 = 'a4_4xa6';
}
