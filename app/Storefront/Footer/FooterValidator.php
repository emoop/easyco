<?php

namespace App\Storefront\Footer;

use App\Rules\PlainText;
use App\Settings\AppearanceSettings;

/**
 * Validates a footer layout against FooterLayout's limits and the block catalog and returns it NORMALISED (FT-1). It runs on SAVE (the admin), on COMPILE (publish) and again
 * when the cached payload is READ, so a hand-edited database row or cache entry cannot add a sixth column, an unsafe link, a colour that is not `#rrggbb` or a setting that does
 * not exist: such a layout is refused (the shop then shows its old footer). A block whose TYPE is not in the catalog (an extension that was switched off) is DROPPED, not an error.
 * Nothing here trusts its input to have been checked earlier.
 */
final class FooterValidator
{
    /** @var array<string, string> path => message */
    private array $errors = [];

    /** @var array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}> */
    private array $catalog = [];

    private int $blocks = 0;

    /** @param  array<string, mixed>  $layout */
    public static function validate(array $layout): FooterValidation
    {
        $validator = new self;
        $validator->catalog = FooterCatalog::all();
        $clean = $validator->layout($layout);

        return new FooterValidation($clean, $validator->errors);
    }

    /** @param  array<string, mixed>  $params */
    private function fail(string $path, string $code, array $params = []): void
    {
        // The first message of a path is the useful one.
        $this->errors[$path] ??= (string) __('footer.errors.'.$code, $params);
    }

    /** @param  array<string, mixed>  $layout */
    private function layout(array $layout): array
    {
        $default = FooterLayout::default();
        $this->unknownKeys($layout, ['upper', 'lower'], 'layout');

        $upper = $this->upper(is_array($layout['upper'] ?? null) ? $layout['upper'] : $default['upper']);

        if ($this->blocks > FooterLayout::MAX_BLOCKS) {
            $this->fail('upper', 'too_many_blocks', ['max' => FooterLayout::MAX_BLOCKS]);
        }

        return ['upper' => $upper, 'lower' => $this->lower(is_array($layout['lower'] ?? null) ? $layout['lower'] : $default['lower'])];
    }

    /** @param  array<string, mixed>  $u */
    private function upper(array $u): array
    {
        $this->unknownKeys($u, ['enabled', 'padding', 'bg', 'text', 'border_color', 'border_width', 'rows'], 'upper');
        $rows = $u['rows'] ?? [];

        if (! is_array($rows) || ! array_is_list($rows)) {
            $this->fail('upper.rows', 'bad_list');
            $rows = [];
        }

        if (count($rows) < 1 || count($rows) > FooterLayout::MAX_ROWS) {
            $this->fail('upper.rows', 'rows_count', ['max' => FooterLayout::MAX_ROWS]);
        }

        $clean = [];

        foreach (array_slice($rows, 0, FooterLayout::MAX_ROWS) as $i => $row) {
            $clean[] = $this->row($row, "upper.rows.{$i}");
        }

        return [
            'enabled' => $this->bool($u['enabled'] ?? true, 'upper.enabled'),
            'padding' => $this->choice($u['padding'] ?? 'normal', FooterLayout::PADDINGS, 'upper.padding'),
            'bg' => $this->color($u['bg'] ?? null, 'upper.bg'),
            'text' => $this->color($u['text'] ?? null, 'upper.text'),
            'border_color' => $this->color($u['border_color'] ?? null, 'upper.border_color'),
            'border_width' => $this->width($u['border_width'] ?? 1, 'upper.border_width'),
            'rows' => $clean,
        ];
    }

    /** @param  array<string, mixed>  $l */
    private function lower(array $l): array
    {
        $this->unknownKeys($l, ['enabled', 'padding', 'bg', 'text', 'align', 'text_template'], 'lower');
        $template = $l['text_template'] ?? '';
        $template = is_string($template) ? trim($template) : '';

        if (mb_strlen($template) > FooterLayout::MAX_TEMPLATE) {
            $this->fail('lower.text_template', 'too_long', ['max' => FooterLayout::MAX_TEMPLATE]);
            $template = mb_substr($template, 0, FooterLayout::MAX_TEMPLATE);
        }

        if ($template !== '' && ! $this->isPlain($template)) {
            $this->fail('lower.text_template', 'not_plain');
            $template = '';
        }

        return [
            'enabled' => $this->bool($l['enabled'] ?? true, 'lower.enabled'),
            'padding' => $this->choice($l['padding'] ?? 'compact', FooterLayout::PADDINGS, 'lower.padding'),
            'bg' => $this->color($l['bg'] ?? null, 'lower.bg'),
            'text' => $this->color($l['text'] ?? null, 'lower.text'),
            'align' => $this->choice($l['align'] ?? 'center', FooterLayout::ALIGNS, 'lower.align'),
            'text_template' => $template,
        ];
    }

    /** @return array{columns: list<array<string, mixed>>} */
    private function row(mixed $row, string $path): array
    {
        if (! is_array($row)) {
            $this->fail($path, 'bad_element');

            return ['columns' => []];
        }

        $this->unknownKeys($row, ['columns'], $path);
        $columns = $row['columns'] ?? [];

        if (! is_array($columns) || ! array_is_list($columns)) {
            $this->fail("{$path}.columns", 'bad_list');
            $columns = [];
        }

        if (count($columns) < 1 || count($columns) > FooterLayout::MAX_COLUMNS) {
            $this->fail("{$path}.columns", 'columns_count', ['max' => FooterLayout::MAX_COLUMNS]);
        }

        $clean = [];

        foreach (array_slice($columns, 0, FooterLayout::MAX_COLUMNS) as $i => $column) {
            $clean[] = $this->column($column, "{$path}.columns.{$i}");
        }

        return ['columns' => $clean];
    }

    /** @return array{span: int, align: string, blocks: list<array<string, mixed>>} */
    private function column(mixed $column, string $path): array
    {
        if (! is_array($column)) {
            $this->fail($path, 'bad_element');

            return ['span' => 1, 'align' => 'start', 'blocks' => []];
        }

        $this->unknownKeys($column, ['span', 'align', 'blocks'], $path);
        $blocks = $column['blocks'] ?? [];

        if (! is_array($blocks) || ! array_is_list($blocks)) {
            $this->fail("{$path}.blocks", 'bad_list');
            $blocks = [];
        }

        if (count($blocks) > FooterLayout::MAX_BLOCKS_PER_COLUMN) {
            $this->fail("{$path}.blocks", 'too_many_in_column', ['max' => FooterLayout::MAX_BLOCKS_PER_COLUMN]);
        }

        $span = $column['span'] ?? 1;
        $span = is_string($span) && ctype_digit($span) ? (int) $span : $span;

        if (! in_array($span, FooterLayout::SPANS, true)) {
            $this->fail("{$path}.span", 'bad_choice');
            $span = 1;
        }

        $clean = [];

        foreach (array_slice($blocks, 0, FooterLayout::MAX_BLOCKS_PER_COLUMN) as $i => $block) {
            $result = $this->block($block, "{$path}.blocks.{$i}");

            if ($result !== null) {
                $clean[] = $result;
                $this->blocks++;
            }
        }

        return ['span' => $span, 'align' => $this->choice($column['align'] ?? 'start', FooterLayout::ALIGNS, "{$path}.align"), 'blocks' => $clean];
    }

    /** @return array{type: string, settings: array<string, mixed>}|null */
    private function block(mixed $block, string $path): ?array
    {
        if (! is_array($block)) {
            $this->fail($path, 'bad_element');

            return null;
        }

        $this->unknownKeys($block, ['type', 'settings'], $path);
        $type = $block['type'] ?? null;

        // A block of a type that is not in the catalog (an extension switched off) is dropped, never an error.
        if (! is_string($type) || ! isset($this->catalog[$type])) {
            return null;
        }

        $settings = $block['settings'] ?? [];

        if (! is_array($settings)) {
            $this->fail("{$path}.settings", 'bad_element');

            return null;
        }

        $schema = $this->catalog[$type]['schema'];

        foreach (array_diff(array_keys($settings), array_keys($schema)) as $key) {
            $this->fail("{$path}.settings.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [];

        foreach ($schema as $name => $field) {
            $clean[$name] = $this->setting($settings[$name] ?? ($field['default'] ?? null), $field, "{$path}.settings.{$name}");
        }

        return ['type' => $type, 'settings' => $clean];
    }

    /** @param  array<string, mixed>  $field */
    private function setting(mixed $value, array $field, string $path): mixed
    {
        switch ($field['kind']) {
            case 'int':
                $number = is_string($value) && preg_match('/^-?\d{1,6}$/', $value) === 1 ? (int) $value : $value;

                if (! is_int($number) || $number < (int) ($field['min'] ?? 0) || $number > (int) ($field['max'] ?? PHP_INT_MAX)) {
                    $this->fail($path, 'bad_choice');

                    return (int) ($field['default'] ?? $field['min'] ?? 0);
                }

                return $number;
            case 'enum':
                if (! in_array($value, $field['options'], true)) {
                    $this->fail($path, 'bad_choice');

                    return $field['default'] ?? $field['options'][0];
                }

                return $value;
            case 'bool':
                return $this->bool($value, $path);
            case 'text':
                return $this->text($value, (int) ($field['max'] ?? 120), ! empty($field['required']), $path);
            case 'textarea':
                return $this->textarea($value, (int) ($field['max'] ?? 400), $path);
            case 'email':
                return $this->email($value, (int) ($field['max'] ?? 120), $path);
            case 'url':
                return $this->url($value, ! empty($field['required']), $path);
            case 'menu':
                return $this->reference($value, false, $path);
            case 'html_block':
                return $this->reference($value, true, $path);
            case 'social':
                return $this->social($value, (int) ($field['max'] ?? 8), $path);
            case 'payments':
                return $this->payments($value, $path);
        }

        $this->fail($path, 'unknown_setting');

        return null;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $this->fail($path, 'bad_choice');

        return false;
    }

    /** @param  list<string|int>  $options */
    private function choice(mixed $value, array $options, string $path): string
    {
        if (! in_array($value, $options, true)) {
            $this->fail($path, 'bad_choice');

            return (string) $options[0];
        }

        return (string) $value;
    }

    private function width(mixed $value, string $path): int
    {
        $value = is_string($value) && ctype_digit($value) ? (int) $value : $value;

        if (! in_array($value, FooterLayout::BORDER_WIDTHS, true)) {
            $this->fail($path, 'bad_width', ['max' => max(FooterLayout::BORDER_WIDTHS)]);

            return 1;
        }

        return $value;
    }

    /** `null` (or an empty text) = the Облик colour; otherwise `#rrggbb`, kept in lower case. */
    private function color(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! AppearanceSettings::isHex($value)) {
            $this->fail($path, 'bad_color');

            return null;
        }

        return strtolower($value);
    }

    private function text(mixed $value, int $max, bool $required, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($required && $value === '') {
            $this->fail($path, 'required');
        }

        if (mb_strlen($value) > $max) {
            $this->fail($path, 'too_long', ['max' => $max]);

            return mb_substr($value, 0, $max);
        }

        if ($value !== '' && ! $this->isPlain($value)) {
            $this->fail($path, 'not_plain');

            return '';
        }

        return $value;
    }

    /** Plain lines: line breaks are kept (as "\n"), every other control character is refused. */
    private function textarea(mixed $value, int $max, string $path): string
    {
        $value = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';

        if (mb_strlen($value) > $max) {
            $this->fail($path, 'too_long', ['max' => $max]);

            return mb_substr($value, 0, $max);
        }

        foreach (explode("\n", $value) as $line) {
            if ($line !== '' && ! $this->isPlain($line)) {
                $this->fail($path, 'not_plain');

                return '';
            }
        }

        return $value;
    }

    private function email(mixed $value, int $max, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > $max || filter_var($value, FILTER_VALIDATE_EMAIL) === false || ! $this->isPlain($value)) {
            $this->fail($path, 'bad_email');

            return '';
        }

        return $value;
    }

    private function url(mixed $value, bool $required, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            $required && $this->fail($path, 'required');

            return '';
        }

        if (! AppearanceSettings::isSafeBannerUrl($value)) {
            $this->fail($path, 'bad_url');

            return '';
        }

        return $value;
    }

    /** The id of a menu / an HTML block (a positive whole number); a menu may be null = the menu of the footer location. */
    private function reference(mixed $value, bool $required, string $path): ?int
    {
        if ($value === null || $value === '') {
            $required && $this->fail($path, 'required');

            return null;
        }

        $id = is_string($value) && ctype_digit($value) ? (int) $value : $value;

        if (! is_int($id) || $id < 1) {
            $this->fail($path, 'bad_choice');

            return null;
        }

        return $id;
    }

    /** @return list<array{network: string, url: string}> */
    private function social(mixed $value, int $max, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'bad_list');

            return [];
        }

        if (count($value) > $max) {
            $this->fail($path, 'too_many_social', ['max' => $max]);
        }

        $out = [];

        foreach (array_slice($value, 0, $max) as $i => $item) {
            $network = is_array($item) ? ($item['network'] ?? null) : null;
            $url = is_array($item) && is_string($item['url'] ?? null) ? trim($item['url']) : '';

            if (! in_array($network, FooterCatalog::networks(), true)) {
                $this->fail("{$path}.{$i}.network", 'bad_choice');

                continue;
            }

            if (! AppearanceSettings::isSafeBannerUrl($url) || ! str_starts_with(strtolower($url), 'https://')) {
                $this->fail("{$path}.{$i}.url", 'bad_https_url');

                continue;
            }

            $out[] = ['network' => $network, 'url' => $url];
        }

        return $out;
    }

    /** A subset of the closed list, in the order given, each once; a method that is not in the list is dropped. @return list<string> */
    private function payments(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'bad_list');

            return [];
        }

        return array_values(array_unique(array_filter($value, static fn ($method): bool => is_string($method) && in_array($method, FooterCatalog::PAYMENTS, true))));
    }

    /** @param  array<string, mixed>  $data  @param  list<string>  $allowed */
    private function unknownKeys(array $data, array $allowed, string $path): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $this->fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }
    }

    private function isPlain(string $text): bool
    {
        $failed = false;
        (new PlainText)->validate('text', $text, static function () use (&$failed): void {
            $failed = true;
        });

        return ! $failed;
    }
}
