<?php

namespace App\Filament\Design\Zones;

/**
 * The properties panel hands values back in a few other shapes than the stored ones (a number as a string, a switch, a repeater keyed by random ids). These helpers put them back, for
 * every zone. They never validate: the zone's validator does that on save.
 */
final class FieldValues
{
    /**
     * The settings of a node from the panel: only the fields of its type, the kinds put back (numbers, switches, the social list).
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function settings(array $fields, array $raw): array
    {
        $out = [];

        foreach ($fields as $name => $field) {
            $value = $raw[$name] ?? null;
            $out[$name] = match ($field['kind']) {
                'bool' => (bool) $value,
                'int' => self::wholeNumber($value),
                'menu', 'html_block' => self::id($value),
                'social' => array_values(array_map(static fn (mixed $item): array => ['network' => (string) ($item['network'] ?? ''), 'url' => (string) ($item['url'] ?? '')], array_filter(is_array($value) ? $value : [], 'is_array'))),
                'payments' => array_values(array_filter(is_array($value) ? $value : [], 'is_string')),
                default => $value === null ? '' : $value,
            };
        }

        return $out;
    }

    public static function wholeNumber(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1 ? (int) $value : $value;
    }

    /** A select of ids gives an int, a numeric string or nothing: null = "none chosen". */
    public static function id(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) === 1 ? (int) $value : $value;
    }
}
