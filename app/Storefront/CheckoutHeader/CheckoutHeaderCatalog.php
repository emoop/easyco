<?php

namespace App\Storefront\CheckoutHeader;

/**
 * THE SIMPLIFIED HEADER of the cart and the checkout (DS-2b, design-screen-design.md section 10): logo, a «Към магазина» link and an optional phone. ONE component with its own fields (the shared
 * FieldKinds), described here so that the validator, the admin panel and the Design core's registry are all built from the same list.
 *
 *   enabled        bool       default false: the shop keeps its full header on the cart and the checkout until the merchant switches this on and publishes
 *   logo_height_d  int 16-80  desktop logo height in px (default 28)
 *   logo_height_m  int 16-64  phone logo height in px (default 26)
 *   back_label     text <= 30 the link's text; empty = the shop's own words «Към магазина» (the storefront language file)
 *   back_url       url        a path in the shop or an https address (default "/")
 *   phone          phone      optional, an INTERNATIONAL number (+359 ...), printed as a `tel:` link
 *   bg, fg         colour     optional `#rrggbb`; none = the colours of the shop
 */
final class CheckoutHeaderCatalog
{
    public const ZONE = 'checkout_header';

    /** The one scope of this zone's stored values (design-screen-design.md section 4: `design.{zone}.{scope}.draft|published`). */
    public const SCOPE = 'global';

    public const BACK_LABEL_MAX = 30;

    /** @return array<string, array<string, mixed>> */
    public static function fields(): array
    {
        return [
            'enabled' => ['kind' => 'bool', 'default' => false],
            'logo_height_d' => ['kind' => 'int', 'min' => 16, 'max' => 80, 'default' => 28],
            'logo_height_m' => ['kind' => 'int', 'min' => 16, 'max' => 64, 'default' => 26],
            'back_label' => ['kind' => 'text', 'max' => self::BACK_LABEL_MAX, 'default' => ''],
            'back_url' => ['kind' => 'url', 'default' => '/'],
            'phone' => ['kind' => 'phone', 'default' => ''],
            'bg' => ['kind' => 'color', 'default' => null],
            'fg' => ['kind' => 'color', 'default' => null],
        ];
    }

    /** @return array<string, mixed> the stored value of a feature that was never set up: off, with every default */
    public static function defaults(): array
    {
        return [
            'enabled' => false, 'logo_height_d' => 28, 'logo_height_m' => 26, 'back_label' => '', 'back_url' => '/', 'phone' => '', 'bg' => null, 'fg' => null,
        ];
    }
}
