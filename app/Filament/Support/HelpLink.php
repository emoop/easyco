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
 * ONE line can carry SEVERAL anchors (`group()`): a form that used to stack five identical "Help: how this works →"
 * lines now shows one line — the first link keeps that label, and each further link is named by the heading of its
 * own section in the help file of the current locale ("Видове методи за доставка"). Same anchors, same URLs, same
 * markup, same new tab: nothing new to click, only one line instead of five.
 *
 * An action's anchor is `action-` + its name with hyphens (`mark_as_received` -> `action-mark-as-received`,
 * `removeLine` -> `action-remove-line`); the anchors are a stable contract with resources/help/{locale}/*.md,
 * and a test fails when an order action has no section.
 */
final class HelpLink
{
    /** @var array<string, array<string, string>> Each help file's section headings, keyed "locale|topic". */
    private static array $headings = [];

    public static function anchor(string $actionName): string
    {
        return 'action-'.str_replace('_', '-', Str::snake($actionName));
    }

    public static function url(string $actionName, string $topic = HelpTopics::DEFAULT): string
    {
        return Help::getUrl(['topic' => $topic]).'#'.self::anchor($actionName);
    }

    public static function component(string $actionName, string $topic = HelpTopics::DEFAULT): Text
    {
        return Text::make(self::html($actionName, $topic))->color('gray')->size('sm');
    }

    /** The same muted link as plain, escaped HTML (for a page that has no schema: a subheading, a blade). */
    public static function html(string $actionName, string $topic = HelpTopics::DEFAULT): HtmlString
    {
        return new HtmlString(self::link($actionName, $topic, __('help.link')));
    }

    /**
     * ONE line for SEVERAL anchors, in the order given: the FIRST link carries the label every help line has
     * (`help.link`, so the line still reads exactly as it did), and every further anchor follows after " · " as a
     * link named by the heading of its own section in the help file of the current locale. An anchor with no
     * heading in that file (or a topic or locale with no file at all) is labelled `help.link` too — the line is
     * never shorter and never an error.
     *
     * @param  list<string>  $actionNames
     */
    public static function group(array $actionNames, string $topic = HelpTopics::DEFAULT): Text
    {
        return Text::make(self::groupHtml($actionNames, $topic))->color('gray')->size('sm');
    }

    /**
     * The same one line as plain, escaped HTML (for a page that has no schema).
     *
     * @param  list<string>  $actionNames
     */
    public static function groupHtml(array $actionNames, string $topic = HelpTopics::DEFAULT): HtmlString
    {
        $headings = self::headings($topic, app()->getLocale());
        $links = [];

        foreach (array_values($actionNames) as $index => $actionName) {
            // The first link IS the line's label; a later one is named by the section it opens.
            $label = $index === 0 ? __('help.link') : ($headings[self::anchor($actionName)] ?? __('help.link'));

            $links[] = self::link($actionName, $topic, $label);
        }

        return new HtmlString(implode(' · ', $links));
    }

    /** One anchor as the `<a>` every help line is made of: the markup lives here, and nowhere else. */
    private static function link(string $actionName, string $topic, string $label): string
    {
        return '<a href="'.e(self::url($actionName, $topic)).'" target="_blank" rel="noopener noreferrer" class="help-link" style="text-decoration: underline;">'
            .e($label).'</a>';
    }

    /**
     * The heading of every `## Title {#anchor}` line of a topic's file, keyed by anchor — read at most once per
     * (locale, topic) per process, from the file itself: no DB and no cache store, because this runs while a form
     * is being built. A missing topic, a missing file for the locale, or an anchor that simply has no heading here
     * all mean the same thing (no heading), so the caller keeps its fallback label. Nothing here can throw.
     *
     * @return array<string, string>
     */
    private static function headings(string $topic, string $locale): array
    {
        $key = $locale.'|'.$topic;

        if (! isset(self::$headings[$key])) {
            // The @ is deliberate: the file could disappear between the resolver's own check and this read, and a
            // PHP warning would surface as an exception on a form — "no heading" is the only failure allowed here.
            $markdown = HelpTopics::path($topic, $locale);
            $markdown = $markdown === null ? false : @file_get_contents($markdown);

            if (! is_string($markdown)) {
                $markdown = '';
            }

            preg_match_all('/^#{2,4} +(.*?) *\{#([a-z0-9-]+)\} *$/m', $markdown, $matches, PREG_SET_ORDER);

            $found = [];

            foreach ($matches as $match) {
                $found[$match[2]] = trim($match[1]);
            }

            self::$headings[$key] = $found;
        }

        return self::$headings[$key];
    }

    /**
     * A dialog's schema with the help line at its bottom.
     *
     * @param  array<int, \Filament\Schemas\Components\Component>  $schema
     * @return array<int, \Filament\Schemas\Components\Component>
     */
    public static function append(array $schema, string $actionName, string $topic = HelpTopics::DEFAULT): array
    {
        return [...$schema, self::component($actionName, $topic)];
    }
}
