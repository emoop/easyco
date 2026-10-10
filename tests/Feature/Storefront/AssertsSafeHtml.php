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
}
