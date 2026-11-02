<?php

namespace App\Filament\Design;

use Illuminate\Support\HtmlString;

/**
 * HINT-1: the explanation of a Design screen field as an info icon next to its label (resources/views/filament/components/info-hint.blade.php) instead of a paragraph under the field. The text is the same lang
 * text, escaped by the view; the icon button and the bubble are linked by `aria-describedby`, so a screen reader still reads it. Use it as `->afterLabel(InfoHint::make($text, $name))`.
 */
final class InfoHint
{
    private static int $counter = 0;

    /** The icon and its bubble for one field, ready for Filament's `afterLabel()`. */
    public static function make(string $text, string $field): HtmlString
    {
        // an id is unique on the page (the same field name can sit in two panels)
        $id = 'ds-hint-'.preg_replace('/[^a-z0-9_-]/i', '', $field).'-'.(++self::$counter);

        return new HtmlString(view('filament.components.info-hint', ['text' => $text, 'id' => $id])->render());
    }
}
