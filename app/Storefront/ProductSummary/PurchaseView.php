<?php

namespace App\Storefront\ProductSummary;

use App\Storefront\ReadModels\ProductPage;

/**
 * PB-1: what the `purchase` partial needs, built ONLY from what the product page already loaded (no query): the attribute groups to print as pickers (colour circles, size buttons), the variation table the
 * script uses to grey out combinations and to find the chosen variation, and the three button texts.
 *
 * The table is printed as ONE escaped `data-` attribute with ids and flags only: `{a: [[value index per group] , ...]}` is not used; a variation is `{id, v: [value index per group], o: 1|0, q: max quantity}`. The
 * group and value order is the page's own `options` order, so an index is a position in `$product->options` (no text travels in the data).
 */
final class PurchaseView
{
    /** The counter's own ceiling. */
    public const MAX_QUANTITY = 99;

    /**
     * @return array{pickers: bool, groups: list<array{name: string, type: string, values: list<array{label: string, colour: ?string}>}>, table: list<array{id: string, v: list<int>, o: int, q: int}>, orderable: int, json: string}
     */
    public static function build(ProductPage $product): array
    {
        $groups = [];
        $table = [];
        $pickers = false;
        $usable = $product->type === 'variable' && $product->options !== [] && count($product->options) === count($product->optionDisplay);

        if ($usable) {
            $names = array_column($product->options, 'name');
            $usable = count(array_unique($names)) === count($names);
        }

        if ($usable) {
            foreach ($product->options as $i => $option) {
                $display = $product->optionDisplay[$i];
                $type = (string) ($display['displayType'] ?? 'dropdown');
                $pickers = $pickers || in_array($type, ['swatch', 'buttons'], true);
                $values = [];

                foreach ($option['values'] as $label) {
                    $colour = $type === 'swatch' ? ($display['colours'][$label] ?? null) : null;
                    $values[] = ['label' => (string) $label, 'colour' => is_string($colour) && preg_match('/\A#[0-9a-f]{6}\z/', $colour) === 1 ? $colour : null];
                }

                $groups[] = ['name' => (string) $option['name'], 'type' => $type, 'values' => $values];
            }

            foreach ($product->variations as $variation) {
                $index = [];

                foreach ($groups as $g => $group) {
                    $found = null;

                    foreach ($variation->attributes as $attribute) {
                        if ($attribute['name'] === $group['name']) {
                            $position = array_search($attribute['value'], array_column($group['values'], 'label'), true);
                            $found = $position === false ? null : $position;
                        }
                    }

                    $index[$g] = $found;
                }

                // a variation the groups cannot place (a missing attribute) makes the pickers unsafe: the plain select stays as today's page had it
                if (in_array(null, $index, true)) {
                    $pickers = false;
                    $table = [];
                    break;
                }

                $table[] = ['id' => (string) $variation->id, 'v' => $index, 'o' => $variation->purchasable && $variation->inStock ? 1 : 0, 'q' => max(0, min(self::MAX_QUANTITY, $variation->maxQuantity))];
            }
        }

        if ($usable && $pickers && $table === []) {
            $pickers = false;
        }

        $orderable = 0;

        foreach ($product->variations as $variation) {
            $variation->purchasable && $variation->inStock && $orderable++;
        }

        if (! $pickers) {
            $groups = [];
            $table = [];

            // without pickers the table still carries the quantity limits (the dropdown stays today's select)
            foreach ($product->variations as $variation) {
                $table[] = ['id' => (string) $variation->id, 'v' => [], 'o' => $variation->purchasable && $variation->inStock ? 1 : 0, 'q' => max(0, min(self::MAX_QUANTITY, $variation->maxQuantity))];
            }
        }

        return [
            'pickers' => $pickers,
            'groups' => $groups,
            'table' => $table,
            'orderable' => $orderable,
            'json' => (string) json_encode(['pickers' => $pickers, 'groups' => count($groups), 'variations' => $table], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * The three texts, the merchant's or (when empty) the shop's own wording in the page's language.
     *
     * @param  array<string, mixed>  $settings  the validated settings of the `purchase` component
     * @return array{label: string, choose: string, sold_out: string}
     */
    public static function labels(array $settings): array
    {
        $text = static fn (string $key, string $fallback): string => is_string($settings[$key] ?? null) && trim($settings[$key]) !== '' ? $settings[$key] : (string) __($fallback);

        return [
            'label' => $text('label', 'storefront.add_to_cart'),
            'choose' => $text('label_choose', 'storefront.purchase.choose'),
            'sold_out' => $text('label_sold_out', 'storefront.purchase.sold_out'),
        ];
    }
}
