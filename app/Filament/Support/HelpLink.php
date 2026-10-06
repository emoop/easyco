<?php

namespace App\Filament\Support;

use App\Filament\Pages\Help;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The ONE helper behind every "Help: how this works →" line of the order page's dialogs (Help 1): the anchor of
 * an action's section in the help, the URL, and the muted schema component that links to it in a NEW tab
 * (`target="_blank" rel="noopener noreferrer"`). It adds a line and nothing else — no field, rule or behaviour.
 *
 * An action's anchor is `action-` + its name with hyphens (`mark_as_received` -> `action-mark-as-received`,
 * `removeLine` -> `action-remove-line`); the anchors are a stable contract with resources/help/{locale}/*.md,
 * and a test fails when an order action has no section.
 */
final class HelpLink
{
    public static function anchor(string $actionName): string
    {
        return 'action-'.str_replace('_', '-', Str::snake($actionName));
    }

    public static function url(string $actionName, string $topic = HelpTopics::DEFAULT): string
    {
        return Help::getUrl(['topic' => $topic]).'#'.self::anchor($actionName);
    }

    public static function component(string $actionName): Text
    {
        $link = '<a href="'.e(self::url($actionName)).'" target="_blank" rel="noopener noreferrer" class="help-link" style="text-decoration: underline;">'
            .e(__('help.link')).'</a>';

        return Text::make(new HtmlString($link))->color('gray')->size('sm');
    }

    /**
     * A dialog's schema with the help line at its bottom.
     *
     * @param  array<int, \Filament\Schemas\Components\Component>  $schema
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function append(array $schema, string $actionName): array
    {
        return [...$schema, self::component($actionName)];
    }
}
