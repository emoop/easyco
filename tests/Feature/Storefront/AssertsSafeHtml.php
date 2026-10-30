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

            // FT-1: the footer's payment marks (`sf-pay-icon`) are drawn with plain shapes and a word, so they may also hold rect / circle / text; the same attribute rules apply.
            if ($name === 'svg' && $this->isAPaymentMark($element)) {
                continue;
            }

            // CD-1b / CT-3: the category's filters are ONE plain GET form (method get, a relative action, no token) and a colour swatch is a validated #rrggbb circle: each accepted only as exactly that
            if ($name === 'svg' && $this->isASwatch($element)) {
                continue;
            }

            if ($name === 'form' && $this->isTheFiltersForm($element)) {
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

    /** A payment mark of the footer: class `sf-pay-icon`, aria-hidden, only shape / text children with presentation attributes (no style, href, event handler). */
    private function isAPaymentMark(DOMElement $svg): bool
    {
        if (trim($svg->getAttribute('class')) !== 'sf-pay-icon' || $svg->getAttribute('aria-hidden') !== 'true') {
            return false;
        }

        foreach ($svg->attributes as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), ['class', 'xmlns', 'viewbox', 'width', 'height', 'aria-hidden', 'focusable'], true)) {
                return false;
            }
        }

        $shapes = ['rect' => ['x', 'y', 'width', 'height', 'rx', 'fill', 'stroke'], 'circle' => ['cx', 'cy', 'r', 'fill', 'fill-opacity'], 'path' => ['d', 'fill'],
            'text' => ['x', 'y', 'text-anchor', 'font-size', 'font-weight', 'font-style', 'fill']];

        foreach ($svg->getElementsByTagName('*') as $child) {
            $allowed = $shapes[strtolower($child->nodeName)] ?? null;

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

    /** CD-1b: the filters form of a category: class `sf-filters-form`, method get, a relative action, and no other attribute (no token, no handler). */
    private function isTheFiltersForm(DOMElement $form): bool
    {
        if (trim($form->getAttribute('class')) !== 'sf-filters-form' || strtolower($form->getAttribute('method')) !== 'get' || ! str_starts_with($form->getAttribute('action'), '/')) {
            return false;
        }

        foreach ($form->attributes as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), ['class', 'method', 'action'], true)) {
                return false;
            }
        }

        return true;
    }

    /** CT-3: a filter swatch: class `sf-fv-circle`, aria-hidden, ONE <circle> whose fill is a plain #rrggbb. */
    private function isASwatch(DOMElement $svg): bool
    {
        if (trim($svg->getAttribute('class')) !== 'sf-fv-circle' || $svg->getAttribute('aria-hidden') !== 'true') {
            return false;
        }

        foreach ($svg->attributes as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), ['class', 'width', 'height', 'viewbox', 'aria-hidden', 'focusable'], true)) {
                return false;
            }
        }

        $children = $svg->getElementsByTagName('*');

        if ($children->length !== 1 || strtolower($children->item(0)->nodeName) !== 'circle') {
            return false;
        }

        foreach ($children->item(0)->attributes as $attribute) {
            if (! in_array(strtolower($attribute->nodeName), ['cx', 'cy', 'r', 'fill'], true)) {
                return false;
            }
        }

        return preg_match('/A#[0-9a-f]{6}z/', $children->item(0)->getAttribute('fill')) === 1;
    }

    private function isAKnownIcon(DOMElement $svg): bool
    {
        $class = ' '.$svg->getAttribute('class').' ';

        $known = false;

        // The CLOSED set of decorative icons the storefront prints (bag, hamburger, close, search/account/wishlist, the eight social glyphs).
        foreach (['sf-cart-icon', 'sf-menu-icon', 'sf-close-icon', 'sf-hdr-icon', 'sf-social-icon', 'sf-ftrigger-icon', 'sf-ftrigger-arrow'] as $icon) {
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
