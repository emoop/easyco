<?php

namespace App\Storefront\Design;

use App\Storefront\Footer\FooterCatalog;
use App\Storefront\Header\HeaderCatalog;
use InvalidArgumentException;

/**
 * THE COMPONENT DESCRIPTORS of the Design core (DS-1, design-screen-design.md section 5): one shape for every component of every zone,
 *
 *   { label, icon, zones, supports, limits, requires_route, fields, view, builtin }
 *
 * The header's elements and the footer's blocks are two COMPONENT SETS of it. The direction of the delegation: the registry READS the catalogs (HeaderCatalog::all(), FooterCatalog::all()),
 * which keep their hooks (`storefront.header.elements`, `storefront.footer.blocks`), their "a built-in that is replaced or removed makes the filter result ignored, logged once" rule and their
 * extension-schema limits exactly as they were, so no existing caller or test changes. The registry only maps what they return into the one descriptor shape (a few lines per zone); the
 * other direction would have meant moving both hooks and both rules.
 */
final class ComponentRegistry
{
    public const ZONES = ['header', 'footer', 'checkout_header', 'product_summary'];

    /**
     * The component set of a zone, by type.
     *
     * @return array<string, array{label: string, icon: string, zones: list<string>, supports: list<string>, limits: array<string, int>, requires_route: ?string, fields: array<string, array<string, mixed>>, view: string, builtin: bool}>
     */
    public static function for(string $zone): array
    {
        return match ($zone) {
            'header' => self::header(),
            'footer' => self::footer(),
            'checkout_header' => self::checkoutHeader(),
            'product_summary' => self::productSummary(),
            default => throw new InvalidArgumentException('Unknown zone: '.mb_substr($zone, 0, 40)),
        };
    }

    /** @return array<string, array<string, mixed>> */
    private static function header(): array
    {
        $out = [];

        foreach (HeaderCatalog::all() as $type => $descriptor) {
            $zones = ['header.desktop', 'header.mobile'];

            if (in_array($type, HeaderCatalog::DRAWER_TYPES, true)) {
                $zones[] = 'header.mobile.drawer';
            }

            $out[$type] = [
                'label' => $descriptor['label'], 'icon' => $descriptor['builtin'] ? $type : 'puzzle', 'zones' => $zones, 'supports' => [],
                'limits' => in_array($type, HeaderCatalog::ONCE_PER_LAYOUT, true) ? ['max_per_layout' => 1] : [],
                'requires_route' => HeaderCatalog::FEATURE_ROUTES[$type] ?? null,
                'fields' => $descriptor['schema'], 'view' => $descriptor['view'], 'builtin' => $descriptor['builtin'],
            ];
        }

        return $out;
    }

    /** DS-2b: the simplified checkout header is ONE component with its own fields (no hook: nothing for an extension to add). @return array<string, array<string, mixed>> */
    private static function checkoutHeader(): array
    {
        return ['checkout_header' => [
            'label' => 'design.zones.checkout', 'icon' => 'checkout_header', 'zones' => ['checkout_header'], 'supports' => [], 'limits' => ['max_per_layout' => 1], 'requires_route' => null,
            'fields' => \App\Storefront\CheckoutHeader\CheckoutHeaderCatalog::fields(), 'view' => 'storefront.partials.checkout-header', 'builtin' => true,
        ]];
    }

    /** SC-1: the components of the product page's right column (a closed list, no hook). @return array<string, array<string, mixed>> */
    private static function productSummary(): array
    {
        $out = [];

        foreach (\App\Storefront\ProductSummary\ProductSummaryCatalog::all() as $type => $descriptor) {
            $out[$type] = [
                'label' => $descriptor['label'], 'icon' => $type, 'zones' => ['summary.main'], 'supports' => [],
                'limits' => ['max_per_layout' => $descriptor['max']], 'requires_route' => null,
                'fields' => $descriptor['schema'], 'view' => $descriptor['view'], 'builtin' => true,
            ];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private static function footer(): array
    {
        $out = [];

        foreach (FooterCatalog::all() as $type => $descriptor) {
            $out[$type] = [
                'label' => $descriptor['label'], 'icon' => $descriptor['builtin'] ? $type : 'puzzle', 'zones' => ['footer.upper'], 'supports' => [], 'limits' => [],
                'requires_route' => $type === 'newsletter' ? FooterCatalog::NEWSLETTER_ROUTE : null,
                'fields' => $descriptor['schema'], 'view' => $descriptor['view'], 'builtin' => $descriptor['builtin'],
            ];
        }

        return $out;
    }
}
