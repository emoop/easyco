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
     * @param  array<string, mixed>  $settings  the validated settings of the `purchase` component (PB-1.1: `axes` = the merchant's order of the groups)
     * @return array{pickers: bool, groups: list<array{name: string, type: string, values: list<array{label: string, colour: ?string}>}>, table: list<array{id: string, v: list<int>, o: int, q: int}>, orderable: int, json: string}
     */
    public static function build(ProductPage $product, array $settings = []): array
    {
        $groups = [];
        $table = [];
        $pickers = false;
        $usable = $product->type === 'variable' && $product->options !== [] && count($product->options) === count($product->optionDisplay);
        $options = $product->options;
        $display = $product->optionDisplay;

        // PB-1.1: the merchant's order, applied BEFORE any index is built (a group index is a position in this order): the listed axes the product has, then the others in the page's own order
        if ($usable && is_array($settings['axes'] ?? null) && $settings['axes'] !== []) {
            $order = self::order(array_map(static fn (array $d): mixed => $d['id'] ?? null, $display), $settings['axes']);
            $options = array_map(static fn (int $i): array => $product->options[$i], $order);
            $display = array_map(static fn (int $i): array => $product->optionDisplay[$i], $order);
        }

        if ($usable) {
            $names = array_column($options, 'name');
            $usable = count(array_unique($names)) === count($names);
        }

        if ($usable) {
            foreach ($options as $i => $option) {
                $shown = $display[$i];
                $type = (string) ($shown['displayType'] ?? 'dropdown');
                $pickers = $pickers || in_array($type, ['swatch', 'buttons'], true);
                $values = [];

                foreach ($option['values'] as $label) {
                    $colour = $type === 'swatch' ? ($shown['colours'][$label] ?? null) : null;
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
     * The positions of the product's axes in the merchant's order: the axes named in `$wanted` first (an id the product does not have is ignored, a repeated one counts once), then the rest as they were. An axis
     * is never dropped: the result is always a permutation of all positions.
     *
     * @param  list<mixed>  $ids  the definition id of each axis, in the page's own order (null = not known)
     * @param  list<mixed>  $wanted
     * @return list<int>
     */
    public static function order(array $ids, array $wanted): array
    {
        $out = [];

        foreach ($wanted as $id) {
            $position = is_int($id) ? array_search($id, $ids, true) : false;

            if ($position !== false && ! in_array($position, $out, true)) {
                $out[] = $position;
            }
        }

        foreach (array_keys($ids) as $position) {
            in_array($position, $out, true) || $out[] = $position;
        }

        return $out;
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
