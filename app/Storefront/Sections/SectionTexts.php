<?php

namespace App\Storefront\Sections;

use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * P4b: the texts a product has for the sections of «Секции на продукта» (the table `product_section_texts`: one row per product and section key). A small read / write model NEXT TO the catalog: the domain's
 * `Product` is not touched. The product forms' «Информация» tab loads the texts with forProduct() and saves them with save() from the SAME save of the product (inside its transaction).
 *
 * An empty text is no row. A text of a section that is no longer defined stays in the table and is not shown (the storefront asks only for the keys it has).
 */
final class SectionTexts
{
    /** The keys of the sections of the PUBLISHED «Секции на продукта» that take a product text (`product` | `product_or_global`): the fields of the product form's «Информация» tab. @return list<string> */
    public static function formKeys(): array
    {
        return array_values(array_map(static fn (array $section): string => $section['key'], array_filter(SectionStore::values()['sections'], static fn (array $section): bool => SectionSet::usesProductText($section['source']))));
    }

    /**
     * The texts of a product by section key: ONE query.
     *
     * @return array<string, string>
     */
    public static function forProduct(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }

        return DB::table('product_section_texts')->where('product_id', $productId)->pluck('body', 'section_key')->map(static fn (mixed $body): string => (string) $body)->all();
    }

    /**
     * Saves the texts of the sections in `$keys` (only those: the texts of other sections are not touched). A key that is not a section key is ignored; an empty text deletes the row; a text that is
     * the same as the stored one changes nothing (and logs nothing).
     *
     * @param  array<string, mixed>  $texts  section key => the editor's html
     * @param  list<string>  $keys  the keys of the sections that take a product text
     */
    public static function save(int $productId, array $texts, array $keys): void
    {
        $keys = array_values(array_filter(array_unique($keys), [SectionSet::class, 'isKey']));
        $keys = array_values(array_filter($keys, static fn (string $key): bool => array_key_exists($key, $texts)));

        if ($productId < 1 || $keys === []) {
            return;
        }

        $stored = DB::table('product_section_texts')->where('product_id', $productId)->whereIn('section_key', $keys)->pluck('body', 'section_key')->all();
        $now = now();
        $logger = app(ActivityLogger::class);

        foreach ($keys as $key) {
            $body = is_string($texts[$key]) ? trim($texts[$key]) : '';
            $body = SectionResolver::isEmpty($body) ? '' : $body;
            $old = isset($stored[$key]) ? (string) $stored[$key] : '';

            if ($body === $old) {
                continue;
            }

            if ($body === '') {
                DB::table('product_section_texts')->where('product_id', $productId)->where('section_key', $key)->delete();
            } elseif ($old === '') {
                DB::table('product_section_texts')->insert(['product_id' => $productId, 'section_key' => $key, 'body' => $body, 'created_at' => $now, 'updated_at' => $now]);
            } else {
                DB::table('product_section_texts')->where('product_id', $productId)->where('section_key', $key)->update(['body' => $body, 'updated_at' => $now]);
            }

            $logger->logFieldChanged('product', (string) $productId, 'section:'.$key, $old === '' ? null : $old, $body === '' ? null : $body);
        }
    }
}
