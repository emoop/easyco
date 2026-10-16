<?php

namespace Tests\Feature\Storefront;

use DOMDocument;
use DOMElement;

/** Parses a response body and asserts it carries no ACTIVE content: scripts, frames, svg, event handlers, style attributes, javascript:/data: urls. */
trait AssertsSafeHtml
{
    private function assertNoActiveContent(string $html, string $context = ''): void
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $problems = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            $name = strtolower($element->nodeName);

            // S3-V / S3-V2: the ONLY inline svgs the layout prints are two decorative icons, the bag (`sf-cart-icon`) and the hamburger (`sf-menu-icon`): each
            // is accepted only as exactly that (aria-hidden, children are plain <path d=...> or <line> and nothing else, no event/href/style attribute).
            if ($name === 'svg' && $this->isAKnownIcon($element)) {
                continue;
            }

            if (in_array($name, ['script', 'iframe', 'frame', 'object', 'embed', 'svg', 'math', 'form', 'style', 'base', 'applet'], true)) {
                $problems[] = "<{$name}> element";
            }

            foreach ($element->attributes as $attribute) {
                $attr = strtolower($attribute->nodeName);
                $value = strtolower(preg_replace('/[\x00-\x20]+/', '', $attribute->nodeValue));

                if (str_starts_with($attr, 'on')) {
                    $problems[] = "{$attr} attribute on <{$name}>";
                }

                if ($attr === 'style') {
                    $problems[] = "style attribute on <{$name}>";
                }

                if (in_array($attr, ['href', 'src', 'action', 'formaction', 'xlink:href'], true) && (str_starts_with($value, 'javascript:') || str_starts_with($value, 'data:') || str_starts_with($value, 'vbscript:'))) {
                    $problems[] = "{$attr}={$value} on <{$name}>";
                }
            }
        }

        $this->assertSame([], $problems, "active content in the page {$context}");
    }

    private function isAKnownIcon(DOMElement $svg): bool
    {
        $class = ' '.$svg->getAttribute('class').' ';

        $known = false;

        // The CLOSED set of decorative icons the storefront prints (bag, hamburger, close, search/account/wishlist, the eight social glyphs).
        foreach (['sf-cart-icon', 'sf-menu-icon', 'sf-close-icon', 'sf-hdr-icon', 'sf-social-icon'] as $icon) {
            $known = $known || str_contains($class, ' '.$icon.' ');
        }

        if (! $known || $svg->getAttribute('aria-hidden') !== 'true') {
            return false;
        }

        foreach ($svg->attributes as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), ['class', 'xmlns', 'viewbox', 'width', 'height', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'aria-hidden', 'focusable'], true)) {
                return false;
            }
        }

        foreach ($svg->getElementsByTagName('*') as $child) {
            $tag = strtolower($child->nodeName);
            $allowed = $tag === 'path' ? ['d'] : ($tag === 'line' ? ['x1', 'y1', 'x2', 'y2'] : null);

            if ($allowed === null) {
                return false;
            }

            foreach ($child->attributes as $attribute) {
                if (! in_array(strtolower($attribute->nodeName), $allowed, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
