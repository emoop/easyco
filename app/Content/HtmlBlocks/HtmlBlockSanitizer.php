<?php

namespace App\Content\HtmlBlocks;

use App\Storefront\Support\DescriptionSanitizer;
use DOMElement;

/**
 * The filter of an HTML block (MENU-2, menu-editor-v2-design.md 2): the description allow-list (same DOM walker, see DescriptionSanitizer) PLUS pictures.
 *
 *   added elements   img figure figcaption (br was already allowed)
 *   img              attributes `src`, `alt`, `width`, `height` only. `src` is our own `/storage/...` path or an https: address; width / height are whole
 *                    numbers from 1 to 4000; `alt` is cut at 200 characters. An `img` whose `src` is not acceptable is removed (a picture with no address is nothing).
 *   everything else  as DescriptionSanitizer: no style, class, id, on*, data-*, srcset; no iframe, script, svg, video, object; `javascript:` / `data:` links dropped.
 *
 * It runs on save AND on render (`render()` adds `loading="lazy" decoding="async"` to every picture), so a hand-edited database row is cleaned again.
 */
class HtmlBlockSanitizer extends DescriptionSanitizer
{
    public const MAX_DIMENSION = 4000;

    protected const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'svg', 'math', 'template', 'noscript', 'title', 'head', 'meta', 'link', 'base', 'audio', 'video',
        'source', 'canvas', 'picture', 'map', 'area', 'xml', 'plaintext', 'listing', 'xmp', 'image', 'bgsound',
    ];

    protected const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
        'a' => ['href'],
        'img' => ['src', 'alt', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
    ];

    /**
     * The sanitized HTML ready to print: pictures load lazily. HB-1: the wrapper of a STYLED block (`<div class="sf-hb sf-hb-{id}">…</div>`, added by HtmlBlockModel::renderMany) survives a second cleaning:
     * only that exact wrapper is recognised (its class is `sf-hb sf-hb-` and a number, nothing else) and what is INSIDE it is cleaned as always, so the call stays idempotent.
     */
    public static function render(?string $html): string
    {
        $html = (string) $html;

        if (preg_match('#\A<div class="sf-hb sf-hb-([1-9][0-9]{0,9})">(.*)</div>\z#s', $html, $m) === 1) {
            return HtmlBlockStyle::wrap((int) $m[1], static::renderInner($m[2]));
        }

        return static::renderInner($html);
    }

    private static function renderInner(string $html): string
    {
        return str_replace('<img ', '<img loading="lazy" decoding="async" ', static::clean($html));
    }

    protected static function filterAttributes(DOMElement $element, string $name): void
    {
        if ($name !== 'img') {
            parent::filterAttributes($element, $name);

            return;
        }

        $src = static::safeSource((string) $element->getAttribute('src'));

        if ($src === null) {
            $element->parentNode?->removeChild($element);

            return;
        }

        $alt = trim((string) $element->getAttribute('alt'));
        $kept = [];

        foreach (['width', 'height'] as $dimension) {
            $value = trim((string) $element->getAttribute($dimension));

            if (preg_match('/^[0-9]{1,4}$/', $value) === 1 && (int) $value >= 1 && (int) $value <= self::MAX_DIMENSION) {
                $kept[$dimension] = (string) (int) $value;
            }
        }

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $element->removeAttribute($attribute->nodeName);
        }

        $element->setAttribute('src', $src);
        $element->setAttribute('alt', mb_substr($alt, 0, 200));

        foreach ($kept as $dimension => $value) {
            $element->setAttribute($dimension, $value);
        }
    }

    /** The address of a link (the DescriptionSanitizer rule: https:, mailto: or a relative path; never javascript:, data:, http:, `//host`); public for the picture links of a block. */
    public static function safeLink(string $value): ?string
    {
        return static::safeHref($value);
    }

    /** Our own storage path or an https: address; nothing else (no protocol-relative, no data:, no backslash, no control characters, no `..`). */
    public static function safeSource(string $value): ?string
    {
        $url = trim($value);

        if ($url === '' || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7F-\x9F"\'<>\\`]/u', $url) === 1 || str_contains($url, '..')) {
            return null;
        }

        if (str_starts_with($url, '/storage/')) {
            return $url;
        }

        if (preg_match('#^https://[^/?\#]+/#i', $url) === 1 && parse_url($url, PHP_URL_HOST)) {
            return $url;
        }

        return null;
    }
}
