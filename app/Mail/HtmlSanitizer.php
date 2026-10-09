<?php

namespace App\Mail;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The final allow-list sanitizer (mail-design.md §5.4, step 4): the safety net behind the Markdown
 * converter and the variable substitution. Whatever came before, nothing outside the allow-list leaves.
 *
 *  - Elements not in the list are UNWRAPPED (their text stays); dangerous ones (script, style, iframe, form, svg, ...)
 *    are removed WITH their content; comments and processing instructions are removed.
 *  - Attributes not in the list are removed. There is no `style`, no `class`, no `id`, no event attribute.
 *  - `href` is accepted only as `https:` or `mailto:`; a root-relative path is expanded against the shop URL;
 *    everything else (javascript:, data:, vbscript:, protocol-relative, bare relative, fragments) drops the attribute.
 *    Whitespace and control characters inside a URL are removed BEFORE the scheme is read, so `java\tscript:` and
 *    entity-encoded schemes (already decoded by the parser) are judged as what they really are.
 *  - There is deliberately no `img` in M1 (images arrive with campaigns).
 *
 * Idempotent: sanitising its own output changes nothing.
 */
final class HtmlSanitizer
{
    /** Removed together with everything inside them. */
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'svg', 'math', 'template', 'noscript', 'title', 'head', 'meta', 'link', 'base', 'audio', 'video',
        'source', 'canvas', 'img', 'picture', 'map', 'area', 'xml', 'plaintext', 'listing', 'xmp',
    ];

    /** Allowed element => allowed attributes. */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [], 'hr' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'a' => ['href'],
        'table' => ['width', 'border', 'cellpadding', 'cellspacing', 'role'],
        'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [],
        'th' => ['align', 'colspan', 'rowspan', 'valign'],
        'td' => ['align', 'colspan', 'rowspan', 'valign'],
    ];

    public static function clean(string $html, string $baseUrl): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);

            if ($body === null) {
                return '';
            }

            self::walk($body, rtrim($baseUrl, '/'));

            $out = '';

            foreach (iterator_to_array($body->childNodes) as $child) {
                $out .= $document->saveHTML($child);
            }

            return trim($out);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function walk(DOMNode $parent, string $baseUrl): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText) {
                continue;
            }

            if (! $node instanceof DOMElement) {
                // Comments, CDATA, processing instructions, entity references: gone.
                $parent->removeChild($node);

                continue;
            }

            $name = strtolower($node->nodeName);

            if (in_array($name, self::DROP, true)) {
                $parent->removeChild($node);

                continue;
            }

            self::walk($node, $baseUrl);

            if (! array_key_exists($name, self::ALLOWED)) {
                // Unknown element: keep its (already cleaned) children, drop the wrapper.
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            self::filterAttributes($node, $name, $baseUrl);
        }
    }

    private static function filterAttributes(DOMElement $element, string $name, string $baseUrl): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributeName = strtolower($attribute->nodeName);

            if (! in_array($attributeName, self::ALLOWED[$name], true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            $value = self::attributeValue($name, $attributeName, $attribute->nodeValue ?? '', $baseUrl);

            if ($value === null) {
                $element->removeAttribute($attribute->nodeName);
            } else {
                $element->setAttribute($attribute->nodeName, $value);
            }
        }
    }

    private static function attributeValue(string $element, string $attribute, string $value, string $baseUrl): ?string
    {
        if ($attribute === 'href') {
            return self::safeUrl($value, $baseUrl);
        }

        $value = trim($value);

        return match ($attribute) {
            'width' => preg_match('/^[0-9]{1,4}%?$/', $value) === 1 ? $value : null,
            'border', 'cellpadding', 'cellspacing' => preg_match('/^[0-9]{1,2}$/', $value) === 1 ? $value : null,
            'colspan', 'rowspan' => preg_match('/^[1-9][0-9]?$/', $value) === 1 ? $value : null,
            'align' => in_array(strtolower($value), ['left', 'right', 'center'], true) ? strtolower($value) : null,
            'valign' => in_array(strtolower($value), ['top', 'middle', 'bottom'], true) ? strtolower($value) : null,
            'role' => $value === 'presentation' ? $value : null,
            default => null,
        };
    }

    private static function safeUrl(string $value, string $baseUrl): ?string
    {
        $url = (string) preg_replace('/[\x00-\x20\x7F-\x9F\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $value);

        if ($url === '' || strlen($url) > 2000) {
            return null;
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match) === 1) {
            return in_array(strtolower($match[1]), ['https', 'mailto'], true) ? $url : null;
        }

        // Root-relative only ("/path"); "//host" (protocol-relative) and bare relatives are refused.
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && $baseUrl !== '') {
            return $baseUrl.$url;
        }

        return null;
    }
}
