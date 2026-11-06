<?php

namespace App\Storefront\Sections;

use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * P4b: the texts a product has for the sections of «Секции на продукта» (the table `product_section_texts`: one row per product and section key). A small read / write model NEXT TO the catalog: the domain's
 * `Product` is not touched. The product forms' «Информация» tab loads the texts with forForm() and saves them with save() from the SAME save of the product (inside its transaction).
 *
 * P4c: a row also carries `hidden`: the section is switched off for THIS product («Показвай на този продукт»). Only a row that differs from the default is stored: shown + no text = no row; hidden + no text = a row with
 * an empty body; a text + shown = a row; hidden and a text coexist (switching back on keeps the text). A row of a section that is no longer defined stays in the table and is ignored (the storefront asks only for the
 * keys it has).
 */
final class SectionTexts
{
    /** The keys of the sections of the PUBLISHED «Секции на продукта» that take a product text (`product` | `product_or_global`): the rich-text fields of the product form's «Информация» tab. @return list<string> */
    public static function formKeys(): array
    {
        return array_values(array_map(static fn (array $section): string => $section['key'], array_filter(SectionStore::values()['sections'], static fn (array $section): bool => SectionSet::usesProductText($section['source']))));
    }

    /** P4c: the keys of ALL the published sections: each has the «Показвай на този продукт» switch in the «Информация» tab. @return list<string> */
    public static function switchKeys(): array
    {
        return array_values(array_map(static fn (array $section): string => $section['key'], SectionStore::values()['sections']));
    }

    /**
     * The texts of a product by section key: ONE query.
     *
     * @return array<string, string>
     */
    public static function forProduct(int $productId): array
    {
        return self::rowsForProduct($productId)['texts'];
    }

    /**
     * The texts AND the switches of a product: ONE query (what the storefront page reads, and the form loads).
     *
     * @return array{texts: array<string, string>, hidden: array<string, true>}
     */
    public static function rowsForProduct(int $productId): array
    {
        $out = ['texts' => [], 'hidden' => []];

        if ($productId < 1) {
            return $out;
        }

        foreach (DB::table('product_section_texts')->where('product_id', $productId)->get(['section_key', 'body', 'hidden']) as $row) {
            (string) $row->body === '' || $out['texts'][(string) $row->section_key] = (string) $row->body;
            (bool) $row->hidden && $out['hidden'][(string) $row->section_key] = true;
        }

        return $out;
    }

    /**
     * The «Информация» tab's values for a product: `section_texts` (key => html) and `section_shown` (key => bool, ON unless the row says hidden), for the published sections only.
     *
     * @return array{section_texts: array<string, string>, section_shown: array<string, bool>}
     */
    public static function forForm(int $productId): array
    {
        $rows = self::rowsForProduct($productId);
        $shown = [];

        foreach (self::switchKeys() as $key) {
            $shown[$key] = ! isset($rows['hidden'][$key]);
        }

        return ['section_texts' => $rows['texts'], 'section_shown' => $shown];
    }

    /**
     * Saves the texts of the sections in `$keys` and the switches of the sections in `$switchKeys` (only those: the rows of other sections are not touched). A key that is not a section key is ignored; an empty text
     * with the switch on deletes the row; a value that is the same as the stored one changes nothing (and logs nothing).
     *
     * @param  array<string, mixed>  $texts  section key => the editor's html
     * @param  list<string>  $keys  the keys of the sections that take a product text
     * @param  array<string, mixed>  $shown  section key => the switch (true = shown on this product)
     * @param  list<string>  $switchKeys  the keys of the sections that have the switch
     */
    public static function save(int $productId, array $texts, array $keys, array $shown = [], array $switchKeys = []): void
    {
        $keys = array_values(array_filter(array_unique($keys), [SectionSet::class, 'isKey']));
        $keys = array_values(array_filter($keys, static fn (string $key): bool => array_key_exists($key, $texts)));
        $switchKeys = array_values(array_filter(array_unique($switchKeys), [SectionSet::class, 'isKey']));
        $switchKeys = array_values(array_filter($switchKeys, static fn (string $key): bool => array_key_exists($key, $shown)));
        $all = array_values(array_unique([...$keys, ...$switchKeys]));

        if ($productId < 1 || $all === []) {
            return;
        }

        $stored = [];

        foreach (DB::table('product_section_texts')->where('product_id', $productId)->whereIn('section_key', $all)->get(['section_key', 'body', 'hidden']) as $row) {
            $stored[(string) $row->section_key] = ['body' => (string) $row->body, 'hidden' => (bool) $row->hidden];
        }

        $now = now();
        $logger = app(ActivityLogger::class);

        foreach ($all as $key) {
            $oldBody = $stored[$key]['body'] ?? '';
            $oldHidden = $stored[$key]['hidden'] ?? false;

            $body = $oldBody;

            if (in_array($key, $keys, true)) {
                $body = is_string($texts[$key]) ? trim($texts[$key]) : '';
                $body = SectionResolver::isEmpty($body) ? '' : $body;
            }

            $hidden = in_array($key, $switchKeys, true) ? ! self::on($shown[$key]) : $oldHidden;

            if ($body === $oldBody && $hidden === $oldHidden) {
                continue;
            }

            if ($body === '' && ! $hidden) {
                DB::table('product_section_texts')->where('product_id', $productId)->where('section_key', $key)->delete();
            } elseif (! isset($stored[$key])) {
                DB::table('product_section_texts')->insert(['product_id' => $productId, 'section_key' => $key, 'body' => $body, 'hidden' => $hidden, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('product_section_texts')->where('product_id', $productId)->where('section_key', $key)->update(['body' => $body, 'hidden' => $hidden, 'updated_at' => $now]);
            }

            $body === $oldBody || $logger->logFieldChanged('product', (string) $productId, 'section:'.$key, $oldBody === '' ? null : $oldBody, $body === '' ? null : $body);
            $hidden === $oldHidden || $logger->logFieldChanged('product', (string) $productId, 'section_hidden:'.$key, $oldHidden ? '1' : null, $hidden ? '1' : null);
        }
    }

    /** The switch's value: the form gives a bool; a string / number is read like a checkbox. */
    private static function on(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
