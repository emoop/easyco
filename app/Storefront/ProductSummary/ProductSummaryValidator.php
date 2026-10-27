<?php

namespace App\Storefront\ProductSummary;

use App\Storefront\Design\FieldKinds;

/**
 * Validates the layout of the right column and returns it NORMALISED (SC-1). It runs on «Приложи» (the admin), when the layout is compiled and again when the storefront reads the compiled copy: a
 * type that is not in the catalog, a second `title` or `purchase`, a missing locked component, more than 15 components, more than 5 HTML blocks or a setting out of range is REFUSED (the page then
 * prints the default layout). Paths: `components` (the list as a whole), `components.{i}` (one component), `components.{i}.settings.{name}`.
 */
final class ProductSummaryValidator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $layout
     * @return array{0: array{components: list<array{type: string, settings: array<string, mixed>}>}, 1: array<string, string>} the normalised layout and the messages by path
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
        $this->errors[$path] ??= (string) __('design.summary.errors.'.$code, $params);
    }

    /** @param  array<string, mixed>  $layout */
    private function layout(array $layout): array
    {
        foreach (array_diff(array_keys($layout), ['components']) as $key) {
            $this->fail('components', 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $list = $layout['components'] ?? [];

        if (! is_array($list) || ! array_is_list($list)) {
            $this->fail('components', 'bad_list');

            return ['components' => []];
        }

        if (count($list) > ProductSummaryCatalog::MAX_COMPONENTS) {
            $this->fail('components', 'too_many', ['max' => ProductSummaryCatalog::MAX_COMPONENTS]);
            $list = array_slice($list, 0, ProductSummaryCatalog::MAX_COMPONENTS);
        }

        $catalog = ProductSummaryCatalog::all();
        $clean = [];
        $count = [];

        foreach ($list as $i => $component) {
            $one = $this->component($component, "components.{$i}", $catalog);

            if ($one === null) {
                continue;
            }

            $count[$one['type']] = ($count[$one['type']] ?? 0) + 1;

            if ($count[$one['type']] > $catalog[$one['type']]['max']) {
                $this->fail("components.{$i}", $catalog[$one['type']]['max'] === 1 ? 'only_one' : 'too_many_html', ['max' => $catalog[$one['type']]['max']]);
            }

            $clean[] = $one;
        }

        foreach (ProductSummaryCatalog::LOCKED as $type) {
            ($count[$type] ?? 0) === 0 && $this->fail('components', 'locked_missing');
        }

        return ['components' => $clean];
    }

    /**
     * @param  array<string, array{label: string, max: int, schema: array<string, array<string, mixed>>, view: string}>  $catalog
     * @return array{type: string, settings: array<string, mixed>}|null
     */
    private function component(mixed $component, string $path, array $catalog): ?array
    {
        if (! is_array($component)) {
            $this->fail($path, 'bad_element');

            return null;
        }

        foreach (array_diff(array_keys($component), ['type', 'settings']) as $key) {
            $this->fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $type = $component['type'] ?? null;

        if (! is_string($type) || ! isset($catalog[$type])) {
            $this->fail($path, 'unknown_type');

            return null;
        }

        $settings = $component['settings'] ?? [];

        if (! is_array($settings)) {
            $this->fail("{$path}.settings", 'bad_element');

            return null;
        }

        $schema = $catalog[$type]['schema'];

        foreach (array_diff(array_keys($settings), array_keys($schema)) as $key) {
            $this->fail("{$path}.settings.{$key}", 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $clean = [];
        $failer = fn (string $p, string $code, array $params = []) => $this->fail($p, $code, $params);

        foreach ($schema as $name => $field) {
            $clean[$name] = FieldKinds::validate($settings[$name] ?? ($field['default'] ?? null), $field, "{$path}.settings.{$name}", $failer);
        }

        return ['type' => $type, 'settings' => $clean];
    }
}
