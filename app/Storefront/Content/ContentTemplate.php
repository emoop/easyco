<?php

namespace App\Storefront\Content;

use App\Storefront\Home\HomeCatalog;
use App\Storefront\Home\HomeValidator;
use App\Storefront\ReadModels\ListingScope;

/**
 * P3: the template of a LISTING page (category, brand, season, tag) — what «Дизайн» → «Съдържание» stores. A closed shape:
 *
 *   {cloud: bool, title: bool, top: int|null, bottom: null | {type: 'html_block', html_block: int} | {type: 'carousel', settings: {…the home carousel's settings…}}, bottom_heading: string}
 *
 * `cloud` = the quick-navigation cloud of the title (P3.1: printed ONLY when on); `title` = show the page name (off: the <h1> stays for search engines, visually hidden); `bottom_heading` = an optional short heading
 * above the bottom slot (empty = none); `top` = the id of an HTML block shown above the listing; `bottom` = an HTML block or the shared HP-5 carousel below it. The slots are validated by the HOME
 * layout's own validator (HomeValidator in its content mode: a carousel of the kinds `products` | `blocks`, the extra source `current`): one validation, one set of rules, no second implementation. A template is
 * WHOLE: a page's own template replaces the kind's default completely, nothing is mixed.
 *
 * Per kind (`categories` | `brands` | `seasons` | `tags`) the SET holds one default template (null = today's page) and a map subject id => its own template.
 *
 * Pure: no query, no setting (the existence of a subject is the store's question).
 *
 * @phpstan-type Template array{cloud: bool, title: bool, top: int|null, bottom: array<string, mixed>|null, bottom_heading: string}
 */
final class ContentTemplate
{
    /** kind => the scope of its pages */
    public const KINDS = ['categories' => ListingScope::CATEGORY, 'brands' => ListingScope::BRAND, 'seasons' => ListingScope::SEASON, 'tags' => ListingScope::TAG];

    /** The most own templates one kind holds (the storage is one JSON; the compiled copy is capped as a whole too). */
    public const MAX_OWN = 200;

    /** The longest heading above the bottom slot. */
    public const HEADING_MAX = 80;

    public static function kindOf(ListingScope $scope): string
    {
        return (string) array_search($scope, self::KINDS, true);
    }

    /** @return Template an empty template: today's page */
    public static function blank(): array
    {
        return ['cloud' => false, 'title' => true, 'top' => null, 'bottom' => null, 'bottom_heading' => ''];
    }

    /** Is the template the same as no template at all (nothing to print)? @param Template $template */
    public static function isBlank(array $template): bool
    {
        return $template['cloud'] === false && $template['title'] === true && $template['top'] === null && $template['bottom'] === null && $template['bottom_heading'] === '';
    }

    /**
     * SAVE / PUBLISH: the template checked strictly. Returns the clean template and puts the messages by path into `$errors` (the first message of a path stays).
     *
     * @param  array<string, string>  $errors
     * @return Template
     */
    public static function validate(mixed $value, string $path, array &$errors): array
    {
        $fail = static function (string $at, string $code, array $params = []) use (&$errors): void {
            $errors[$at] ??= (string) __('design.content.errors.'.$code, $params);
        };

        if (! is_array($value)) {
            $fail($path, 'bad_template');

            return self::blank();
        }

        foreach (array_diff(array_keys($value), ['cloud', 'title', 'top', 'bottom', 'bottom_heading']) as $key) {
            $fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $cloud = $value['cloud'] ?? false;

        if (! is_bool($cloud)) {
            $fail($path.'.cloud', 'bad_choice');
            $cloud = false;
        }

        $title = $value['title'] ?? true;

        if (! is_bool($title)) {
            $fail($path.'.title', 'bad_choice');
            $title = true;
        }

        $heading = self::heading($value['bottom_heading'] ?? '', $path.'.bottom_heading', $fail);

        return ['cloud' => $cloud, 'title' => $title, 'top' => self::top($value['top'] ?? null, $path.'.top', $fail), 'bottom' => self::bottom($value['bottom'] ?? null, $path.'.bottom', $fail), 'bottom_heading' => $heading];
    }

    /**
     * READ: what a stored template is worth. Never an error: an unknown key is dropped, a slot that does not validate is null (the rest stays), a cloud that is not a real boolean is off.
     *
     * @return Template
     */
    public static function read(mixed $value): array
    {
        if (! is_array($value)) {
            return self::blank();
        }

        $quiet = static function (): void {
        };
        $cloud = ($value['cloud'] ?? false) === true;
        $title = ($value['title'] ?? true) !== false;
        $headingErrors = [];
        $heading = self::heading($value['bottom_heading'] ?? '', 'bottom_heading', static function (string $at, string $code) use (&$headingErrors): void {
            $headingErrors[$at] = $code;
        });

        // a slot is judged alone: its own errors make THAT slot null
        $topErrors = [];
        $top = self::top($value['top'] ?? null, 'top', static function (string $at, string $code) use (&$topErrors): void {
            $topErrors[$at] = $code;
        });
        $bottomErrors = [];
        $bottom = self::bottom($value['bottom'] ?? null, 'bottom', static function (string $at, string $code) use (&$bottomErrors): void {
            $bottomErrors[$at] = $code;
        });

        return ['cloud' => $cloud, 'title' => $title, 'top' => $topErrors === [] ? $top : null, 'bottom' => $bottomErrors === [] ? $bottom : null, 'bottom_heading' => $headingErrors === [] ? $heading : ''];
    }

    /** The ids of the HTML blocks a template shows, in order, each once. @param Template $template @return list<int> */
    public static function blockIds(array $template): array
    {
        $ids = [$template['top']];
        $bottom = $template['bottom'];

        if (is_array($bottom)) {
            $ids = [...$ids, ...($bottom['type'] === 'html_block' ? [$bottom['html_block'] ?? null] : HomeCatalog::blockIds(['type' => 'carousel', 'settings' => $bottom['settings'] ?? []]))];
        }

        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
    }

    /** The slot as a one-section home layout section (what HomeView prints), or null. @param Template $template @return array{type: string, settings: array<string, mixed>}|null */
    public static function topSection(array $template): ?array
    {
        return $template['top'] === null ? null : ['type' => 'html_block', 'settings' => ['html_block' => $template['top']]];
    }

    /** @param Template $template @return array{type: string, settings: array<string, mixed>}|null */
    public static function bottomSection(array $template): ?array
    {
        $bottom = $template['bottom'];

        if (! is_array($bottom)) {
            return null;
        }

        return $bottom['type'] === 'html_block' ? ['type' => 'html_block', 'settings' => ['html_block' => $bottom['html_block']]] : ['type' => 'carousel', 'settings' => $bottom['settings']];
    }

    /** The heading above a slot: plain text, at most HEADING_MAX characters, no control characters (it is escaped when printed). '' = none. P4: shared with the product page's slots. @param  callable(string, string, array<string, mixed>=): void  $fail */
    public static function heading(mixed $value, string $path, callable $fail): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (! is_string($value) || preg_match('/\p{C}/u', $value) === 1) {
            $fail($path, 'bad_choice');

            return '';
        }

        $value = trim($value);

        if (mb_strlen($value, 'UTF-8') > self::HEADING_MAX) {
            $fail($path, 'heading_too_long', ['max' => self::HEADING_MAX]);

            return '';
        }

        return $value;
    }

    /** @param  callable(string, string, array<string, mixed>=): void  $fail */
    private static function top(mixed $value, string $path, callable $fail): ?int
    {
        if ($value === null) {
            return null;
        }

        [$clean, $errors] = HomeValidator::validate(['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => [['type' => 'html_block', 'settings' => ['html_block' => $value]]]], true);

        foreach ($errors as $at => $message) {
            $fail($path, 'bad_block');
        }

        $id = $clean['sections'][0]['settings']['html_block'] ?? null;

        return $errors === [] && is_int($id) ? $id : null;
    }

    /**
     * A slot below a page's content: an HTML block or a carousel (P3: the bottom slot of a listing template; P4: `$mode` = HomeCatalog::PRODUCT_MODE for a product page's slot, whose carousel has the source `similar`).
     *
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return array<string, mixed>|null
     */
    public static function bottom(mixed $value, string $path, callable $fail, bool|string $mode = true): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $fail($path, 'bad_slot');

            return null;
        }

        $type = $value['type'] ?? null;

        if ($type !== 'html_block' && $type !== 'carousel') {
            $fail($path, 'bad_slot');

            return null;
        }

        foreach (array_diff(array_keys($value), $type === 'html_block' ? ['type', 'html_block'] : ['type', 'settings']) as $key) {
            $fail($path, 'unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $section = $type === 'html_block' ? ['type' => 'html_block', 'settings' => ['html_block' => $value['html_block'] ?? null]] : ['type' => 'carousel', 'settings' => is_array($value['settings'] ?? null) ? $value['settings'] : []];
        [$clean, $errors] = HomeValidator::validate(['spacing' => HomeCatalog::DEFAULT_SPACING, 'sections' => [$section]], $mode);

        foreach ($errors as $at => $message) {
            // `sections.0.settings.source` -> `bottom.settings.source`
            $fail($path.(str_contains($at, '.settings.') ? '.settings.'.substr($at, strrpos($at, '.') + 1) : ''), 'bad_slot_value', ['message' => $message]);
        }

        $one = $clean['sections'][0] ?? null;

        if ($one === null) {
            return null;
        }

        return $type === 'html_block' ? ['type' => 'html_block', 'html_block' => $one['settings']['html_block']] : ['type' => 'carousel', 'settings' => $one['settings']];
    }
}
