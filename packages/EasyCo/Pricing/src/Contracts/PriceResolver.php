<?php

namespace EasyCo\Pricing\Contracts;

/**
 * Customer-facing price resolution contract — see pricing-domain-design.md
 * §4.1. This, PriceContext/PriceQuote, CostPriceProvider, and the
 * already-public Money/Currency/Price are the entire public surface other
 * domains may depend on. PriceList/PriceListItem/PriceRule and their
 * persistence are internal to this package.
 */
interface PriceResolver
{
    public function resolve(PriceContext $context): PriceQuote;
}
