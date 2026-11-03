<?php

namespace App\Storefront\Home;

use App\Storefront\Design\FieldKinds;

/**
 * Validates the home layout and returns it NORMALISED (HP-1). It runs on «Приложи» (the admin), when the layout is compiled and again every time the storefront reads the compiled copy: an unknown key, a
 * type that is not in the catalog, more than 12 sections, a spacing outside the closed list, a bad heading level, a text over 120 characters or with HTML, or an HTML block that is not chosen is REFUSED
 * (the page then prints the old home page). Paths: `spacing`, `sections` (the list as a whole), `sections.{i}` (one section), `sections.{i}.settings.{name}`.
 */
final class HomeValidator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $layout
     * @return array{0: array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}, 1: array<string, string>} the normalised layout and the messages by path
     */
    public static function validate(array $layout): array
    {
        $validator = new self;
        $clean = $validator->layout($layout);

        return [$clean, $validator->errors];
    }

    /** @param  array<string, mixed>  $params */
    private function fail(string $path, string $code, array $params = []): void
    {
        // the first message of a path is the useful one
        $this->errors[$path] ??= (string) __('design.home.errors.'.$code, $params);
    }

    /** @param  array<string, mixed>  $layout */
    private function layout(array $layout): array
    {
        foreach (array_diff(array_keys($layout), ['spacing', 'sections']) as $key) {
            $this->fail('sections', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $spacing = $layout['spacing'] ?? HomeCatalog::DEFAULT_SPACING;

        if (! is_string($spacing) || ! in_array($spacing, HomeCatalog::SPACINGS, true)) {
            $this->fail('spacing', 'bad_choice');
            $spacing = HomeCatalog::DEFAULT_SPACING;
        }

        $list = $layout['sections'] ?? [];

        if (! is_array($list) || ! array_is_list($list)) {
            $this->fail('sections', 'bad_list');

            return ['spacing' => $spacing, 'sections' => []];
        }

        if (count($list) > HomeCatalog::MAX_SECTIONS) {
            $this->fail('sections', 'too_many', ['max' => HomeCatalog::MAX_SECTIONS]);
            $list = array_slice($list, 0, HomeCatalog::MAX_SECTIONS);
        }

        $catalog = HomeCatalog::all();
        $clean = [];

        foreach ($list as $i => $section) {
            $one = $this->section($section, "sections.{$i}", $catalog);
            $one === null || $clean[] = $one;
        }

        return ['spacing' => $spacing, 'sections' => $clean];
    }

    /**
     * @param  array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string}>  $catalog
     * @return array{type: string, settings: array<string, mixed>}|null
     */
    private function section(mixed $section, string $path, array $catalog): ?array
    {
        if (! is_array($section)) {
            $this->fail($path, 'bad_element');

            return null;
        }

        foreach (array_diff(array_keys($section), ['type', 'settings']) as $key) {
            $this->fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $type = $section['type'] ?? null;

        if (! is_string($type) || ! isset($catalog[$type])) {
            $this->fail($path, 'unknown_type');

            return null;
        }

        $settings = $section['settings'] ?? [];

        if (! is_array($settings)) {
            $this->fail("{$path}.settings", 'bad_element');

            return null;
        }

        $schema = HomeCatalog::common() + $catalog[$type]['schema'];

        foreach (array_diff(array_keys($settings), array_keys($schema)) as $key) {
            $this->fail("{$path}.settings.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [];
        $failer = fn (string $p, string $code, array $params = []) => $this->fail($p, $code, $params);

        foreach ($schema as $name => $field) {
            $clean[$name] = FieldKinds::validate($settings[$name] ?? ($field['default'] ?? null), $field, "{$path}.settings.{$name}", $failer);
        }

        // a heading is PLAIN text: a tag, even one the plain-text rule let through, is refused
        if (isset($clean['text']) && is_string($clean['text']) && $clean['text'] !== strip_tags($clean['text'])) {
            $this->fail("{$path}.settings.text", 'not_plain');
            $clean['text'] = '';
        }

        return ['type' => $type, 'settings' => $clean];
    }
}
