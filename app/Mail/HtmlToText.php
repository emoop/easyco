<?php

namespace App\Mail;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Derives the plain-text part from the FINAL sanitised HTML (mail-design.md §5.5), so the two parts
 * can never disagree. Links are kept as `text (url)`; list items as `- item`; table rows as one line of
 * cells separated by two spaces.
 */
final class HtmlToText
{
    private const BLOCK = ['p', 'ul', 'ol', 'blockquote', 'table', 'h1', 'h2', 'h3', 'h4', 'hr'];

    public static function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);
            $text = $body === null ? '' : self::node($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $text = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text)."\n";
    }

    private static function node(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return (string) preg_replace('/\s+/u', ' ', $node->nodeValue ?? '');
        }

        if (! $node instanceof DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);
        $inner = '';

        foreach ($node->childNodes as $child) {
            $inner .= self::node($child);
        }

        return match (true) {
            $name === 'br' => "\n",
            $name === 'hr' => "\n\n--------\n\n",
            $name === 'a' => self::link($node, trim($inner)),
            $name === 'li' => '- '.trim($inner)."\n",
            $name === 'tr' => trim($inner)."\n",
            in_array($name, ['td', 'th'], true) => trim($inner).'  ',
            in_array($name, self::BLOCK, true) => "\n\n".trim($inner)."\n\n",
            default => $inner,
        };
    }

    private static function link(DOMElement $element, string $text): string
    {
        $href = $element->getAttribute('href');

        if ($href === '' || $text === $href || 'mailto:'.$text === $href) {
            return $text;
        }

        return $text.' ('.$href.')';
    }
}
