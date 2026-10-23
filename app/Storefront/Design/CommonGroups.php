<?php

namespace App\Storefront\Design;

use InvalidArgumentException;

/**
 * THE SHARED PROPERTY GROUPS (DS-1, design-screen-design.md section 3): the same few properties, with the same closed values and the same classes, for every component of every zone.
 * A component lists the groups it `supports`; the Design screen shows them in one fixed section above the component's own fields.
 *
 *   align        start | center | end                        -> class sf-al-{s|c|e}
 *   spacing      top, bottom: 0 | s | m | l                  -> classes sf-mt-{0|s|m|l}, sf-mb-{0|s|m|l}      (no value = the component's own spacing; `0` is a choice)
 *   visibility   desktop, phone: bool (default true)         -> classes sf-hide-d, sf-hide-m
 *   colors       bg, fg: `#rgb` / `#rrggbb`                  -> CSS variables --sf-n-{id}-bg / -fg (for the token stylesheet)
 *   text_size    s | m | l (default m)                       -> class sf-ts-{s|m|l}
 *
 * The stored shape is `{align?: 'center', spacing?: {top?, bottom?}, visibility?: {desktop?, phone?}, colors?: {bg?, fg?}, text_size?: 'l'}`; a value equal to its default is NOT stored
 * and emits nothing (the D1 rule: only differences are compiled). Data and class names only: the CSS rules themselves arrive with the Design screen.
 */
final class CommonGroups
{
    /** group => key => spec (a group with ONE value has the single key `value`). */
    public const GROUPS = [
        'align' => ['value' => ['kind' => 'enum', 'options' => ['start', 'center', 'end'], 'default' => 'start']],
        'spacing' => [
            'top' => ['kind' => 'enum', 'options' => ['0', 's', 'm', 'l']],
            'bottom' => ['kind' => 'enum', 'options' => ['0', 's', 'm', 'l']],
        ],
        'visibility' => ['desktop' => ['kind' => 'bool', 'default' => true], 'phone' => ['kind' => 'bool', 'default' => true]],
        'colors' => ['bg' => ['kind' => 'color'], 'fg' => ['kind' => 'color']],
        'text_size' => ['value' => ['kind' => 'enum', 'options' => ['s', 'm', 'l'], 'default' => 'm']],
    ];

    /** Groups stored as ONE scalar, not as an object. */
    private const SCALAR = ['align', 'text_size'];

    private const ALIGN_CLASS = ['start' => 'sf-al-s', 'center' => 'sf-al-c', 'end' => 'sf-al-e'];

    /**
     * The normalised `common` of a node: unknown groups / keys and values outside the closed lists fail, a group the component does not support fails, a value equal to its default
     * is dropped.
     *
     * @param  array<string, mixed>  $common
     * @param  list<string>  $supports  the groups the component supports
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return array<string, mixed>
     */
    public static function validate(array $common, array $supports, string $path, callable $fail): array
    {
        $out = [];

        foreach ($common as $group => $value) {
            if (! is_string($group) || ! isset(self::GROUPS[$group]) || ! in_array($group, $supports, true)) {
                $fail("{$path}.".(is_string($group) ? $group : '?'), 'unknown_setting', ['key' => is_string($group) ? mb_substr($group, 0, 30) : '?']);
            }
        }

        foreach (self::GROUPS as $group => $keys) {
            if (! array_key_exists($group, $common) || ! in_array($group, $supports, true)) {
                continue;
            }

            $clean = in_array($group, self::SCALAR, true) ? self::scalar($common[$group], $keys['value'], "{$path}.{$group}", $fail) : self::object($common[$group], $keys, "{$path}.{$group}", $fail);

            if ($clean !== null && $clean !== []) {
                $out[$group] = $clean;
            }
        }

        return $out;
    }

    /**
     * The classes of a validated `common`, in a fixed order, space separated (empty when nothing differs from the defaults).
     *
     * @param  array<string, mixed>  $common
     */
    public static function classes(array $common): string
    {
        $classes = [];

        if (isset($common['align']) && isset(self::ALIGN_CLASS[$common['align']])) {
            $classes[] = self::ALIGN_CLASS[$common['align']];
        }

        foreach (['top' => 'sf-mt-', 'bottom' => 'sf-mb-'] as $side => $prefix) {
            $value = $common['spacing'][$side] ?? null;

            if (is_string($value) && in_array($value, self::GROUPS['spacing'][$side]['options'], true)) {
                $classes[] = $prefix.$value;
            }
        }

        if (($common['visibility']['desktop'] ?? true) === false) {
            $classes[] = 'sf-hide-d';
        }

        if (($common['visibility']['phone'] ?? true) === false) {
            $classes[] = 'sf-hide-m';
        }

        if (isset($common['text_size']) && in_array($common['text_size'], self::GROUPS['text_size']['value']['options'], true)) {
            $classes[] = 'sf-ts-'.$common['text_size'];
        }

        return implode(' ', $classes);
    }

    /**
     * The CSS variables of a node's colours for the token stylesheet: `--sf-n-{id}-bg` / `-fg`. A colour that is not valid hex is skipped (checked again here).
     *
     * @param  array<string, mixed>  $common
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when the node id is not `[a-z0-9-]{1,40}`
     */
    public static function variables(string $nodeId, array $common): array
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $nodeId) !== 1) {
            throw new InvalidArgumentException('A node id is 1 to 40 characters of a-z, 0-9 and "-".');
        }

        $vars = [];

        foreach (['bg', 'fg'] as $key) {
            $color = $common['colors'][$key] ?? null;

            if (is_string($color) && preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $color) === 1) {
                $vars["--sf-n-{$nodeId}-{$key}"] = $color;
            }
        }

        return $vars;
    }

    // ---- internals -----------------------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $spec  @param  callable(string, string, array<string, mixed>=): void  $fail */
    private static function scalar(mixed $value, array $spec, string $path, callable $fail): ?string
    {
        if (is_array($value)) {
            $fail($path, 'bad_choice', []);

            return null;
        }

        $clean = FieldKinds::validate($value, $spec, $path, $fail);

        return $clean === ($spec['default'] ?? null) ? null : $clean;
    }

    /**
     * @param  array<string, array<string, mixed>>  $keys
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value, array $keys, string $path, callable $fail): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $fail($path, 'bad_choice', []);

            return null;
        }

        foreach (array_diff(array_keys($value), array_keys($keys)) as $key) {
            $fail("{$path}.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $out = [];

        foreach ($keys as $key => $spec) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $clean = FieldKinds::validate($value[$key], $spec, "{$path}.{$key}", $fail);

            // a value equal to its default (or an empty colour) is not stored
            if ($clean !== null && $clean !== ($spec['default'] ?? null)) {
                $out[$key] = $clean;
            }
        }

        return $out;
    }
}
