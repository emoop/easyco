<?php

namespace App\Storefront\Support;

use App\Services\PriceDisplayFormatter;
use EasyCo\Pricing\Money;

/** Minor units + currency code -> the shop's display string (symbol and position come from site settings). Views `@inject` it. */
final class PriceFormatter
{
    public function __construct(
        private readonly PriceDisplayFormatter $formatter,
    ) {
    }

    public function format(int $minor, string $currency): string
    {
        $money = Money::fromMinorUnits($minor, $currency);

        return $this->formatter->format($money->decimalValue(), $money->currency());
    }
}
