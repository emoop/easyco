<?php

namespace App\Storefront\Content;

/**
 * P3: ALL the listing-page templates of the shop, as one value: the shop-wide `style` (P3.1: the cloud shape and the title look) and, per kind (`categories` | `brands` | `seasons` | `tags`) the DEFAULT template (null = none: today's page) and a map subject id => the subject's OWN
 * template. Pure (no query): which subjects exist is passed in by the store.
 *
 *   {style: {cloud_shape: site|pill, title_style: lines|plain}, categories: {default: Template|null, own: {"12": Template}}, brands: {…}, seasons: {…}, tags: {…}}
 *
 * The own map is keyed by the subject's id; a key that is not a plain positive whole number is dropped on read and refused on save, and so is a key of a subject that does not exist (the page cannot be asked
 * for, and «Страници със собствени настройки» must not list a ghost).
 *
 * @phpstan-import-type Template from ContentTemplate
 *
 * @phpstan-type Style array{cloud_shape: string, title_style: string}
 * @phpstan-type Set array<string, mixed>  (`style` => Style, a kind => array{default: Template|null, own: array<int, Template>})
 */
final class ContentSet
{
    /** the closed lists of the shop-wide style; the FIRST value of each is the default */
    public const CLOUD_SHAPES = ['site', 'pill'];

    public const TITLE_STYLES = ['lines', 'plain'];

    /** @return Style the default look: the cloud like the side panel's buttons, the title with lines */
    public static function defaultStyle(): array
    {
        return ['cloud_shape' => self::CLOUD_SHAPES[0], 'title_style' => self::TITLE_STYLES[0]];
    }

    /** The style read leniently: anything that is not in its closed list is the default. @return Style */
    public static function style(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'cloud_shape' => in_array($value['cloud_shape'] ?? null, self::CLOUD_SHAPES, true) ? $value['cloud_shape'] : self::CLOUD_SHAPES[0],
            'title_style' => in_array($value['title_style'] ?? null, self::TITLE_STYLES, true) ? $value['title_style'] : self::TITLE_STYLES[0],
        ];
    }

    /** @return Set */
    public static function blank(): array
    {
        $set = ['style' => self::defaultStyle()];

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            $set[$kind] = ['default' => null, 'own' => []];
        }

        return $set;
    }

    /** @param  Set  $set */
    public static function isBlank(array $set): bool
    {
        if ($set['style'] != self::defaultStyle()) {
            return false;
        }

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            if (($set[$kind]['default'] ?? null) !== null || ($set[$kind]['own'] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * SAVE / PUBLISH: the whole set checked strictly. `$existing` = kind => the ids of the subjects that exist now.
     *
     * @param  array<string, list<int>>  $existing
     * @return array{0: Set, 1: array<string, string>} the clean set and the messages by path (`categories.default.top`, `brands.own.12`)
     */
    public static function validate(mixed $value, array $existing): array
    {
        $errors = [];
        $set = self::blank();

        if (! is_array($value)) {
            $errors['kind'] = (string) __('design.content.errors.bad_set');

            return [$set, $errors];
        }

        foreach (array_diff(array_keys($value), ['style', ...array_keys(ContentTemplate::KINDS)]) as $key) {
            $errors['kind'] ??= (string) __('design.content.errors.unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $style = $value['style'] ?? self::defaultStyle();

        if (! is_array($style) || array_diff(array_keys($style), array_keys(self::defaultStyle())) !== []) {
            $errors['style'] ??= (string) __('design.content.errors.bad_set');
        } else {
            foreach (['cloud_shape' => self::CLOUD_SHAPES, 'title_style' => self::TITLE_STYLES] as $name => $allowed) {
                if (isset($style[$name]) && ! in_array($style[$name], $allowed, true)) {
                    $errors['style.'.$name] ??= (string) __('design.content.errors.bad_choice');
                }
            }
        }

        $set['style'] = self::style($style);

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            $entry = $value[$kind] ?? ['default' => null, 'own' => []];

            if (! is_array($entry)) {
                $errors[$kind] ??= (string) __('design.content.errors.bad_set');

                continue;
            }

            foreach (array_diff(array_keys($entry), ['default', 'own']) as $key) {
                $errors[$kind] ??= (string) __('design.content.errors.unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
            }

            $default = $entry['default'] ?? null;
            $set[$kind]['default'] = $default === null ? null : ContentTemplate::validate($default, $kind.'.default', $errors);

            $own = $entry['own'] ?? [];

            if (! is_array($own)) {
                $errors[$kind.'.own'] ??= (string) __('design.content.errors.bad_set');

                continue;
            }

            if (count($own) > ContentTemplate::MAX_OWN) {
                $errors[$kind.'.own'] ??= (string) __('design.content.errors.too_many', ['max' => ContentTemplate::MAX_OWN]);
                $own = array_slice($own, 0, ContentTemplate::MAX_OWN, true);
            }

            foreach ($own as $id => $template) {
                $key = self::id($id);

                if ($key === null || ! in_array($key, $existing[$kind] ?? [], true)) {
                    $errors[$kind.'.own'] ??= (string) __('design.content.errors.unknown_subject');

                    continue;
                }

                $set[$kind]['own'][$key] = ContentTemplate::validate($template, $kind.'.own.'.$key, $errors);
            }
        }

        return [$set, $errors];
    }

    /**
     * READ: what a stored set is worth. Never an error: an unknown kind or key is dropped, a bad own key is dropped, a template is read leniently (ContentTemplate::read). With `$existing` the own templates of
     * subjects that are gone are dropped too. A template that is blank is no template.
     *
     * @param  array<string, list<int>>|null  $existing
     * @return Set
     */
    public static function read(mixed $stored, ?array $existing = null): array
    {
        $set = self::blank();

        if (! is_array($stored)) {
            return $set;
        }

        $set['style'] = self::style($stored['style'] ?? null);

        foreach (array_keys(ContentTemplate::KINDS) as $kind) {
            $entry = is_array($stored[$kind] ?? null) ? $stored[$kind] : [];

            if (array_key_exists('default', $entry) && $entry['default'] !== null) {
                $default = ContentTemplate::read($entry['default']);
                $set[$kind]['default'] = ContentTemplate::isBlank($default) ? null : $default;
            }

            foreach (array_slice(is_array($entry['own'] ?? null) ? $entry['own'] : [], 0, ContentTemplate::MAX_OWN, true) as $id => $template) {
                $key = self::id($id);

                if ($key === null || ($existing !== null && ! in_array($key, $existing[$kind] ?? [], true))) {
                    continue;
                }

                // an OWN template that is blank is still a template: it replaces the default with «nothing»
                $set[$kind]['own'][$key] = ContentTemplate::read($template);
            }
        }

        return $set;
    }

    /**
     * The template a page prints: its own when it has one, else the kind's default, else null (today's page). Whole, never mixed.
     *
     * @param  Set  $set
     * @return Template|null
     */
    public static function resolve(array $set, string $kind, int $id): ?array
    {
        return $set[$kind]['own'][$id] ?? $set[$kind]['default'] ?? null;
    }

    private static function id(mixed $key): ?int
    {
        if (is_int($key)) {
            return $key > 0 ? $key : null;
        }

        return is_string($key) && preg_match('/\A[1-9][0-9]{0,17}\z/', $key) === 1 ? (int) $key : null;
    }
}
