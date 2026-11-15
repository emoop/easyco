<?php

namespace App\Storefront\Seo;

/**
 * SEO-1: the share-card tags and the JSON-LD block of a page's <head>, as ONE string of whole lines (or '' when the page has none).
 *
 * The layout prints it through a single `@php echo … @endphp` line, not through Blade directives: a directive prints its own indentation and
 * newlines, which would change the bytes of every page that has a share card. Printed as '', it changes nothing at all.
 * Only a page with a canonical that is not noindex gets anything (never a filtered listing, a 404, a preview, the cart, the checkout or a search).
 */
final class SeoHead
{
    /** @param  array<string, mixed>  $seo  the `seo` view variable */
    public static function render(array $seo): string
    {
        if (($seo['canonical'] ?? null) === null || ($seo['noindex'] ?? false)) {
            return '';
        }

        $out = '';

        foreach (($seo['og']['property'] ?? []) as $property => $content) {
            $out .= '    <meta property="'.e($property).'" content="'.e($content)."\">\n";
        }

        foreach (($seo['og']['name'] ?? []) as $name => $content) {
            $out .= '    <meta name="'.e($name).'" content="'.e($content)."\">\n";
        }

        // the JSON is encoded with JSON_HEX_* flags (StructuredData::encode): the one raw part of the head
        if (($seo['ld'] ?? null) !== null) {
            $out .= '    <script type="application/ld+json">'.$seo['ld'].'</script>'."\n";
        }

        return $out;
    }
}
