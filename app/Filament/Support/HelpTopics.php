<?php

namespace App\Filament\Support;

/**
 * The ONE registry of in-app help topics (Help 1). A topic is a Markdown file per language,
 * `resources/help/{locale}/{topic}.md`, and a label in `lang/*\/help.php` (`help.topics.{topic}`). Another
 * topic is one entry here plus its two files.
 *
 * RESERVED, NOT BUILT: a filter hook `admin.help.topics` so a merchant extension can add a topic; it is
 * recorded as a row in extensibility-coverage-design.md and nothing here calls it.
 */
final class HelpTopics
{
    public const DEFAULT = 'orders';

    /** @var list<string> */
    private const TOPICS = ['orders', 'shipping'];

    /** @return list<string> */
    public static function all(): array
    {
        return self::TOPICS;
    }

    /** Only a registered key is ever turned into a file path: nothing the visitor typed reaches the filesystem. */
    public static function has(?string $topic): bool
    {
        return $topic !== null && in_array($topic, self::TOPICS, true);
    }

    public static function label(string $topic): string
    {
        return __('help.topics.'.$topic);
    }

    /** The Markdown file of a topic in a locale, or null when it does not exist. */
    public static function path(string $topic, string $locale): ?string
    {
        if (! self::has($topic) || ! preg_match('/^[a-z]{2}(?:[_-][A-Za-z]{2})?$/', $locale)) {
            return null;
        }

        $path = resource_path("help/{$locale}/{$topic}.md");

        return is_file($path) ? $path : null;
    }
}
