<?php

namespace App\Storefront\Home;

/**
 * HP-1: the sections the home page («Начална» in «Дизайн») may hold: a CLOSED list of component types, each with its own validated settings, and the closed lists the zone uses (the spacing between the
 * sections in px, the heading's level and lines). A section holds ONE component. Every section also has the two COMMON settings (`show`, `spacing`: its own top spacing or «as the general one»). Later
 * components (HP-2 … HP-6) are added HERE (a type + a schema + a Blade partial `storefront/partials/home/{type}.blade.php`): neither the zone, the validator nor the compiler changes.
 */
final class HomeCatalog
{
    public const ZONE = 'home';

    public const SCOPE = 'global';

    public const MAX_SECTIONS = 12;

    /** The steps of the spacing between the sections, in px (the CSS classes `sf-hp-gap-N` / `sf-hp-mt-N` give each its rem). */
    public const SPACINGS = ['0', '8', '16', '24', '32', '48', '64', '96'];

    public const DEFAULT_SPACING = '32';

    public const LEVELS = ['h2', 'h3', 'h4'];

    /** `lines` = a line on each side of the text, `none` = the text only. */
    public const LINE_STYLES = ['lines', 'none'];

    public const LINE_THICKNESSES = ['1', '2'];

    public const HEADING_MAX = 120;

    /**
     * The settings every section has (before its component's own).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function common(): array
    {
        return [
            'show' => ['kind' => 'bool', 'default' => true],
            'spacing' => ['kind' => 'enum', 'options' => ['default', ...self::SPACINGS], 'default' => 'default', 'options_prefix' => 'design.home.options.spacing.'],
        ];
    }

    /**
     * type => {label (a lang key), schema (the component's own settings), view}.
     *
     * @return array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string}>
     */
    public static function all(): array
    {
        $types = [
            'heading' => [
                'text' => ['kind' => 'text', 'max' => self::HEADING_MAX, 'required' => true, 'default' => ''],
                'level' => ['kind' => 'enum', 'options' => self::LEVELS, 'default' => 'h2', 'options_prefix' => 'design.home.options.level.'],
                'lines' => ['kind' => 'enum', 'options' => self::LINE_STYLES, 'default' => 'lines', 'options_prefix' => 'design.home.options.lines.'],
                'thickness' => ['kind' => 'enum', 'options' => self::LINE_THICKNESSES, 'default' => '1', 'options_prefix' => 'design.home.options.thickness.'],
            ],
            'html_block' => [
                'html_block' => ['kind' => 'html_block', 'default' => null, 'required' => true],
            ],
        ];
        $out = [];

        foreach ($types as $type => $schema) {
            $out[$type] = ['label' => 'design.home.types.'.$type, 'schema' => $schema, 'view' => 'storefront.partials.home.'.$type];
        }

        return $out;
    }

    /** The defaults of a type's settings: the common ones, then its own (an id kind starts as «none chosen»). @return array<string, mixed> */
    public static function defaultsFor(string $type): array
    {
        $defaults = [];

        foreach (self::common() + (self::all()[$type]['schema'] ?? []) as $name => $field) {
            $defaults[$name] = $field['default'] ?? null;
        }

        return $defaults;
    }
}
