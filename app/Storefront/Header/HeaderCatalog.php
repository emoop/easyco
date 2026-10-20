<?php

namespace App\Storefront\Header;

use EasyCo\Extensibility\Hook;
use Illuminate\Support\Facades\Log;

/**
 * THE ELEMENT CATALOG of the header builder (storefront-customization-design.md 4.2): which element types exist, and for each the schema of its settings.
 * The validator, the admin form and the renderer are all built from it, so a type or a setting that is not here can never be saved, compiled or printed.
 *
 * A schema field is `['kind' => ..., ...]` with kind one of `int` (min, max, default), `enum` (options, default), `text` (max, default), `url` (default; empty
 * or a safe link), `bool` (default), `html` (max bytes) or `social` (a list of {network, url}, max). An EXTENSION adds a type through
 * `Hook::filter('storefront.header.elements', $catalog)` with a descriptor `{type, label, schema, view}`; it can never replace or remove a built-in type:
 * a filter result that does is ignored (the built-ins win) and logged once.
 *
 * Elements whose feature does not exist yet (search, account, wishlist) are accepted and stored; they are RENDERED only when their route exists.
 */
final class HeaderCatalog
{
    public const HOOK = 'storefront.header.elements';

    /** The types a mobile drawer may hold. */
    public const DRAWER_TYPES = ['menu', 'account', 'text', 'button', 'social'];

    public const SOCIAL_NETWORKS = ['facebook', 'instagram', 'tiktok', 'youtube', 'pinterest', 'viber', 'whatsapp', 'x'];

    /** Feature elements: the route that must exist for the element to be printed. */
    public const FEATURE_ROUTES = ['search' => 'storefront.search', 'account' => 'storefront.account', 'wishlist' => 'storefront.wishlist'];

    private static bool $warned = false;

    /**
     * The built-in descriptors: type => {label (lang key), schema, view}.
     *
     * @return array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}>
     */
    public static function builtin(): array
    {
        $styles = ['kind' => 'enum', 'options' => ['icon', 'icon_label'], 'default' => 'icon'];

        $types = [
            'logo' => ['height' => ['kind' => 'int', 'min' => 16, 'max' => 120, 'default' => 28]],
            'menu' => ['style' => ['kind' => 'enum', 'options' => ['links', 'dropdown', 'mega'], 'default' => 'links']],
            'menu_toggle' => ['label_visible' => ['kind' => 'bool', 'default' => false]],
            'search' => ['mode' => ['kind' => 'enum', 'options' => ['icon_row', 'field'], 'default' => 'icon_row']],
            'account' => ['style' => $styles],
            'wishlist' => ['style' => $styles, 'show_count' => ['kind' => 'bool', 'default' => false]],
            'cart' => ['style' => $styles],
            'text' => ['text' => ['kind' => 'text', 'max' => 120, 'default' => ''], 'url' => ['kind' => 'url', 'default' => '']],
            'html' => ['html' => ['kind' => 'html', 'max' => 4000, 'default' => '']],
            'button' => ['label' => ['kind' => 'text', 'max' => 30, 'default' => '', 'required' => true], 'url' => ['kind' => 'url', 'default' => '', 'required' => true], 'style' => ['kind' => 'enum', 'options' => ['primary', 'outline'], 'default' => 'primary']],
            'social' => ['items' => ['kind' => 'social', 'max' => 8, 'default' => []]],
            'spacer' => ['width' => ['kind' => 'enum', 'options' => ['sm', 'md', 'lg'], 'default' => 'md']],
        ];

        $out = [];

        foreach ($types as $type => $schema) {
            $out[$type] = ['label' => 'header.elements.'.$type, 'schema' => $schema, 'view' => 'storefront.header.elements.'.$type, 'builtin' => true];
        }

        return $out;
    }

    /**
     * The catalog: the built-ins plus what extensions add through the hook.
     *
     * @return array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}>
     */
    public static function all(): array
    {
        $builtin = self::builtin();
        $filtered = Hook::apply(self::HOOK, $builtin);

        if (! is_array($filtered)) {
            return $builtin;
        }

        // A built-in type must come back exactly as it went in: replaced or removed = the whole filter result is ignored.
        foreach ($builtin as $type => $descriptor) {
            if (! isset($filtered[$type]) || $filtered[$type] != $descriptor) {
                self::warnOnce('a filter tried to replace or remove the built-in element "'.$type.'"');

                return $builtin;
            }
        }

        $catalog = $builtin;

        foreach ($filtered as $type => $descriptor) {
            if (isset($builtin[$type])) {
                continue;
            }

            $clean = self::cleanExtension($type, $descriptor);

            if ($clean === null) {
                self::warnOnce('an extension element "'.mb_substr((string) $type, 0, 30).'" is not valid and was skipped');

                continue;
            }

            $catalog[$type] = $clean;
        }

        return $catalog;
    }

    /** @return array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}|null */
    private static function cleanExtension(mixed $type, mixed $descriptor): ?array
    {
        if (! is_string($type) || preg_match('/^[a-z][a-z0-9_]{1,30}$/', $type) !== 1 || ! is_array($descriptor)) {
            return null;
        }

        $label = $descriptor['label'] ?? null;
        $view = $descriptor['view'] ?? null;
        $schema = $descriptor['schema'] ?? null;

        if (! is_string($label) || $label === '' || mb_strlen($label) > 80 || ! is_string($view) || preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $view) !== 1 || ! is_array($schema)) {
            return null;
        }

        $clean = [];

        foreach ($schema as $name => $field) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) !== 1 || ! is_array($field) || ! in_array($field['kind'] ?? null, ['int', 'enum', 'text', 'url', 'bool'], true)) {
                return null;
            }

            $clean[$name] = $field;
        }

        return ['label' => $label, 'schema' => $clean, 'view' => $view, 'builtin' => false];
    }

    private static function warnOnce(string $message): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;
        Log::warning('storefront.header.elements: '.$message);
    }

    /** Forgets the "logged once" flag (tests only). */
    public static function forgetWarnings(): void
    {
        self::$warned = false;
    }

    /** The defaults of one type's settings. @return array<string, mixed> */
    public static function defaultsFor(string $type): array
    {
        $defaults = [];

        foreach ((self::all()[$type]['schema'] ?? []) as $name => $field) {
            $defaults[$name] = $field['default'] ?? ($field['kind'] === 'bool' ? false : ($field['kind'] === 'int' ? ($field['min'] ?? 0) : ''));
        }

        return $defaults;
    }
}
