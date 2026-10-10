<?php

namespace App\Storefront\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * The storefront's ONE raw-output filter: a product description is edited in the admin's rich-text editor, so it is
 * HTML, and it is the only text the storefront prints unescaped. Everything it prints has first been rebuilt from
 * an ALLOW-LIST (a DOM walk, not a regex):
 *
 *   elements   p br strong em b i ul ol li h2 h3 h4 blockquote table thead tbody tr th td a
 *   attributes only `href` on `a` (nothing else anywhere: no style, class, id, on*, src, srcset, data-*)
 *   href       https:, mailto: or a RELATIVE url (no scheme, not protocol-relative); control and invisible characters
 *              are removed BEFORE the scheme is read, so `java&#x09;script:` cannot hide; javascript:, data:, vbscript:
 *              and every other scheme are refused and the attribute dropped
 *   links      every `a` gets rel="noopener nofollow"
 *   dropped with their content   script style iframe frame object embed applet form input button textarea select option svg
 *              math template noscript title head meta link base audio video source canvas img picture map area xml
 *   other unknown elements are unwrapped (their text is kept); comments, processing instructions and CDATA are removed.
 *
 * Text nodes are re-serialised by the DOM, so a `<` or `&` that is text stays escaped text.
 */
final class DescriptionSanitizer
{
    private const DROP = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'svg', 'math', 'template', 'noscript', 'title', 'head', 'meta', 'link', 'base', 'audio', 'video',
        'source', 'canvas', 'img', 'picture', 'map', 'area', 'xml', 'plaintext', 'listing', 'xmp', 'image', 'bgsound',
    ];

    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'h2' => [], 'h3' => [], 'h4' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
        'a' => ['href'],
    ];

    private const MAX_LENGTH = 200_000;

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '' || strlen($html) > self::MAX_LENGTH) {
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

            self::walk($body);

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

    private static function walk(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText && $node->nodeType === XML_TEXT_NODE) {
                continue;
            }

            if (! $node instanceof DOMElement) {
                $parent->removeChild($node);

                continue;
            }

            $name = strtolower($node->nodeName);

            if (in_array($name, self::DROP, true)) {
                $parent->removeChild($node);

                continue;
            }

            self::walk($node);

            if (! array_key_exists($name, self::ALLOWED)) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            self::filterAttributes($node, $name);
        }
    }

    private static function filterAttributes(DOMElement $element, string $name): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributeName = strtolower($attribute->nodeName);

            if ($name === 'a' && $attributeName === 'href') {
                $safe = self::safeHref((string) $attribute->nodeValue);

                if ($safe === null) {
                    $element->removeAttribute($attribute->nodeName);
                } else {
                    $element->setAttribute($attribute->nodeName, $safe);
                }

                continue;
            }

            $element->removeAttribute($attribute->nodeName);
        }

        if ($name === 'a') {
            $element->setAttribute('rel', 'noopener nofollow');
        }
    }

    private static function safeHref(string $value): ?string
    {
        // Whitespace, control and invisible characters first: the scheme is read from what a browser would read.
        $url = (string) preg_replace('/[\x00-\x20\x7F-\x9F\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $value);

        if ($url === '' || strlen($url) > 2000) {
            return null;
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match) === 1) {
            return in_array(strtolower($match[1]), ['https', 'mailto'], true) ? $url : null;
        }

        // Protocol-relative (//host) and backslash tricks (/\host) point at another origin: not relative.
        if (str_starts_with($url, '//') || str_contains($url, '\\')) {
            return null;
        }

        return $url;
    }
}
