<?php

namespace App\Storefront\Below;

use App\Storefront\Content\ContentTemplate;
use App\Storefront\Home\HomeCatalog;

/**
 * P4: the slots BELOW the main area of the product page («Дизайн» → «Под галерията»): ONE template for every product page, an ordered list of at most MAX slots.
 *
 *   {slots: [ {type: 'carousel', heading: string, settings: {…the home carousel's settings, its source may be `similar`…}}
 *           | {type: 'html_block', heading: string, html_block: int} ]}
 *
 * The same engine as P3's listing templates: a slot is validated by the HOME layout's own validator (HomeValidator in its product mode: a carousel of the kinds `products` | `blocks` with the extra source
 * `similar`), the heading by ContentTemplate::heading. No second implementation. Pure: no query, no setting.
 *
 * SAVE validates strictly (errors by path `slots.2.heading`); READ never fails: a slot that does not validate is dropped, more than MAX slots are cut, an unknown key is dropped.
 *
 * @phpstan-type Slot array{type: string, heading: string, settings?: array<string, mixed>, html_block?: int}
 * @phpstan-type Set array{slots: list<Slot>}
 */
final class BelowSlots
{
    /** The most slots a product page holds. */
    public const MAX = 6;

    public const TYPES = ['carousel', 'html_block'];

    /** @return Set no slot: the product page it always was */
    public static function blank(): array
    {
        return ['slots' => []];
    }

    /** @param  Set  $set */
    public static function isBlank(array $set): bool
    {
        return $set['slots'] === [];
    }

    /**
     * SAVE / PUBLISH: the whole template checked strictly. Returns the clean set and the messages by path.
     *
     * @return array{0: Set, 1: array<string, string>}
     */
    public static function validate(mixed $value): array
    {
        $errors = [];

        if (! is_array($value)) {
            $errors['slots'] = (string) __('design.below.errors.bad_set');

            return [self::blank(), $errors];
        }

        foreach (array_diff(array_keys($value), ['slots']) as $key) {
            $errors['slots'] ??= (string) __('design.below.errors.unknown_key', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $slots = $value['slots'] ?? [];

        if (! is_array($slots)) {
            $errors['slots'] ??= (string) __('design.below.errors.bad_set');

            return [self::blank(), $errors];
        }

        $slots = array_values($slots);

        if (count($slots) > self::MAX) {
            $errors['slots'] ??= (string) __('design.below.errors.too_many', ['max' => self::MAX]);
            $slots = array_slice($slots, 0, self::MAX);
        }

        $clean = [];

        foreach ($slots as $index => $slot) {
            $one = self::slot($slot, 'slots.'.$index, static function (string $at, string $code, array $params = []) use (&$errors): void {
                $errors[$at] ??= (string) __('design.below.errors.'.$code, $params);
            });
            $one === null || $clean[] = $one;
        }

        return [['slots' => $clean], $errors];
    }

    /**
     * READ: what a stored template is worth. Never an error: a slot that does not validate is dropped, a slot beyond MAX is cut.
     *
     * @return Set
     */
    public static function read(mixed $stored): array
    {
        $slots = is_array($stored) && is_array($stored['slots'] ?? null) ? array_slice(array_values($stored['slots']), 0, self::MAX) : [];
        $clean = [];

        foreach ($slots as $slot) {
            $failed = false;
            // a bad HEADING costs the heading only (the slot stays without it); any other problem costs the slot
            $one = self::slot($slot, 'slot', static function (string $at) use (&$failed): void {
                str_ends_with($at, '.heading') || $failed = true;
            });
            $failed || $one === null || $clean[] = $one;
        }

        return ['slots' => $clean];
    }

    /** The ids of the HTML blocks the slots show (an HTML block slot's block, a block carousel's blocks), in order, each once. @param Set $set @return list<int> */
    public static function blockIds(array $set): array
    {
        $ids = [];

        foreach ($set['slots'] as $slot) {
            $ids = [...$ids, ...($slot['type'] === 'html_block' ? [$slot['html_block'] ?? null] : HomeCatalog::blockIds(['type' => 'carousel', 'settings' => $slot['settings'] ?? []]))];
        }

        return array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)));
    }

    /** The ids of the blocks of the `html_block` slots only (the ones that carry «Показвай, когато…» conditions). @param Set $set @return list<int> */
    public static function conditionalBlockIds(array $set): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (array $slot): mixed => $slot['type'] === 'html_block' ? ($slot['html_block'] ?? null) : null, $set['slots']), static fn (mixed $id): bool => is_int($id) && $id > 0)));
    }

    /**
     * A slot as the HOME layout's sections HomeView prints: the heading (when set) and then the block or the carousel.
     *
     * @param  Slot  $slot
     * @return list<array{type: string, settings: array<string, mixed>}>
     */
    public static function sections(array $slot): array
    {
        $main = $slot['type'] === 'html_block' ? ['type' => 'html_block', 'settings' => ['html_block' => $slot['html_block'] ?? null]] : ['type' => 'carousel', 'settings' => $slot['settings'] ?? []];

        return [$main];
    }

    /**
     * One slot checked; `$fail(path, code, params)` for every problem. Null when the slot is unusable.
     *
     * @param  callable(string, string, array<string, mixed>=): void  $fail
     * @return Slot|null
     */
    private static function slot(mixed $slot, string $path, callable $fail): ?array
    {
        if (! is_array($slot)) {
            $fail($path, 'bad_slot');

            return null;
        }

        $heading = ContentTemplate::heading($slot['heading'] ?? '', $path.'.heading', $fail);
        unset($slot['heading']);

        $one = ContentTemplate::bottom($slot === [] ? null : $slot, $path, $fail, HomeCatalog::PRODUCT_MODE);

        if ($one === null) {
            $slot === [] && $fail($path, 'bad_slot');

            return null;
        }

        return ['type' => $one['type'], 'heading' => $heading] + array_diff_key($one, ['type' => 1]);
    }
}
