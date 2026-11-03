<?php

namespace App\Content\HtmlBlocks;

use App\Storefront\Support\DescriptionSanitizer;
use DOMElement;

/**
 * The filter of a «Снимка с текст» block (HB-5), used ONLY for a block that is exactly a card (HtmlBlockSanitizer::render recognises its box: `<div class="sf-hb sf-hb-{id} sf-pc …">`), so every other block
 * keeps the filter it always had (a `div` or a `class` in a text block is still removed). The same DOM walker, a NARROW allow-list:
 *
 *   elements    div a br                (anything else is unwrapped or dropped as DescriptionSanitizer does)
 *   div         `class` (every token `sf-` + letters, digits, dashes), `role` only `img`, `aria-label` (plain text, at most 200 characters), `aria-hidden` only `true`
 *   a           `class` (as above), `href` (the rule of every link of the shop), `aria-label`, and `rel="noopener nofollow"`
 *   never       style, id, on*, data-*, any other role / attribute
 *
 * The attributes are written in ONE fixed order (class, role, aria-label, aria-hidden / class, href, aria-label, rel), so cleaning a second time changes nothing.
 */
final class HtmlBlockCardSanitizer extends DescriptionSanitizer
{
    public const MAX_LABEL = 200;

    protected const ALLOWED = ['div' => [], 'a' => ['href'], 'br' => []];

    protected static function filterAttributes(DOMElement $element, string $name): void
    {
        $values = [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $values[strtolower($attribute->nodeName)] = (string) $attribute->nodeValue;
            $element->removeAttribute($attribute->nodeName);
        }

        if ($name === 'br') {
            return;
        }

        $classes = self::classes($values['class'] ?? '');
        $classes !== '' && $element->setAttribute('class', $classes);

        if ($name === 'a') {
            $href = static::safeHref($values['href'] ?? '');
            $href !== null && $element->setAttribute('href', $href);
        }

        if ($name === 'div' && ($values['role'] ?? '') === 'img') {
            $element->setAttribute('role', 'img');
        }

        $label = self::label($values['aria-label'] ?? '');
        $label !== '' && $element->setAttribute('aria-label', $label);

        if ($name === 'div' && ($values['aria-hidden'] ?? '') === 'true') {
            $element->setAttribute('aria-hidden', 'true');
        }

        $name === 'a' && $element->setAttribute('rel', 'noopener nofollow');
    }

    /** Only tokens of OUR classes survive; the others are dropped one by one. */
    private static function classes(string $value): string
    {
        $kept = [];

        foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
            preg_match('/\Asf-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $token) === 1 && strlen($token) <= 40 && $kept[] = $token;
        }

        return implode(' ', array_slice($kept, 0, 20));
    }

    private static function label(string $value): string
    {
        $value = trim($value);

        return preg_match('/[\x00-\x1F\x7F-\x9F]/u', $value) === 1 ? '' : mb_substr($value, 0, self::MAX_LABEL);
    }
}
