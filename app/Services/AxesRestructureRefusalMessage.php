<?php

namespace App\Services;

use EasyCo\Catalog\Exceptions\AxesRestructureRefused;

/**
 * Turns an axes-restructure refusal into the sentence the MERCHANT reads, in
 * the merchant's own locale — the ONE place
 * `products.axes_restructure.refusal.*` is resolved, exactly as
 * VariationDeletionRefusalMessage and ProductDeletionRefusalMessage are for
 * their own refusals (every refusal notification goes through it, so the same
 * refusal cannot be worded two ways, or in two locales).
 *
 * WHY NOT ON THE EXCEPTION ITSELF: the exception lives in `EasyCo\Catalog`, a
 * domain package that must not know about app-layer translation keys (its own
 * docblock states this). The domain carries the facts — reason, product name,
 * and the domain's own detail sentence — and this is where they become UI
 * text.
 *
 * `detail` IS PASSED THROUGH, DELIBERATELY: for INVALID_AXES only the domain
 * knows which structural rule failed (the same attribute declared twice, an
 * axis with no values, an attribute that cannot be an axis), and §3.19.8 D's
 * posture is that a refusal is shown rather than paraphrased into something
 * vague. The key for STALE_PLAN takes no detail at all, because the
 * actionable fact there is simply that the product changed and nothing was
 * done.
 */
final class AxesRestructureRefusalMessage
{
    public static function for(AxesRestructureRefused $refusal): string
    {
        return __("products.axes_restructure.refusal.{$refusal->reason->value}", [
            'product' => $refusal->productName,
            'detail' => (string) $refusal->detail,
        ]);
    }
}
