<?php

namespace App\Services;

use App\Settings\StoreLocale;
use EasyCo\Shipping\Contracts\SettlementNameNormalizer;
use Illuminate\Contracts\Container\Container;

/**
 * Chooses the SettlementNameNormalizer for the store's locale
 * (shipping-domain-design.md §4, stage 3a).
 *
 * The choice is made by looking the locale up in the CONTAINER, under the name
 * `shipping.settlement_normalizer.<locale>` — there is no list of locales here,
 * so an extension package supports a new language by binding its own
 * implementation under that name, without editing core. The locale is tried
 * whole first ("pt_br"), then by its language ("pt"); `bg` and `bg_BG` / `bg-BG`
 * therefore both reach the Bulgarian normalizer. Anything with no binding —
 * including an unknown or empty locale — gets `shipping.settlement_normalizer.neutral`
 * (owner decision D3: an unknown locale is never a refusal).
 *
 * The locale comes from StoreLocale, not from App::getLocale(): the `api`
 * middleware group never applies the store locale.
 */
final class SettlementNormalizerResolver
{
    public const BINDING_PREFIX = 'shipping.settlement_normalizer.';

    public function __construct(
        private readonly Container $container,
        private readonly StoreLocale $storeLocale,
    ) {
    }

    public function forCurrentLocale(): SettlementNameNormalizer
    {
        return $this->forLocale($this->storeLocale->current());
    }

    public function forLocale(string $locale): SettlementNameNormalizer
    {
        $whole = strtolower(str_replace('-', '_', trim($locale)));
        $language = explode('_', $whole)[0];

        foreach (array_unique([$whole, $language]) as $candidate) {
            if (preg_match('/^[a-z0-9_]+$/D', $candidate) === 1 && $this->container->bound(self::BINDING_PREFIX.$candidate)) {
                return $this->container->make(self::BINDING_PREFIX.$candidate);
            }
        }

        return $this->container->make(self::BINDING_PREFIX.'neutral');
    }
}
