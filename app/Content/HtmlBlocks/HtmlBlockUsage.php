<?php

namespace App\Content\HtmlBlocks;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Where an HTML block is shown: by the mega item of a menu, by an `html_block` block of the PUBLISHED or the DRAFT footer layout (HDR-3), and by an `html_block` component of the PUBLISHED or the
 * DRAFT right column of the product page (SC-1.1). A block in use is never deleted; the message names the menus, and the footer and the right column as more names. Admin only (the storefront never calls this): the footer layouts are read through the settings repository.
 */
final class HtmlBlockUsage
{
    /** @return list<string> the names of the menus that use the block (empty = free) */
    public static function menus(int $blockId): array
    {
        return DB::table('menu_items')
            ->join('menus', 'menus.id', '=', 'menu_items.menu_id')
            ->where('menu_items.html_block_id', $blockId)
            ->distinct()
            ->orderBy('menus.name')
            ->pluck('menus.name')
            ->map(fn ($name): string => (string) $name)
            ->all();
    }

    /** Does the published or the draft footer layout hold an `html_block` block that names this block? */
    public static function footer(int $blockId): bool
    {
        $settings = app(SiteSettingsRepository::class);

        foreach ([AppearanceSettings::FOOTER_PUBLISHED_KEY, AppearanceSettings::FOOTER_DRAFT_KEY] as $key) {
            $json = $settings->get($key);
            $layout = is_string($json) ? json_decode($json, true) : null;

            foreach (is_array($layout['upper']['rows'] ?? null) ? $layout['upper']['rows'] : [] as $row) {
                foreach (is_array($row['columns'] ?? null) ? $row['columns'] : [] as $column) {
                    foreach (is_array($column['blocks'] ?? null) ? $column['blocks'] : [] as $block) {
                        $id = is_array($block) && ($block['type'] ?? null) === 'html_block' ? ($block['settings']['html_block'] ?? null) : null;

                        if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === $blockId) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /** SC-1.1: does the published or the draft right column hold an `html_block` component that names this block? */
    public static function summary(int $blockId): bool
    {
        $settings = app(SiteSettingsRepository::class);

        foreach ([AppearanceSettings::SUMMARY_PUBLISHED_KEY, AppearanceSettings::SUMMARY_DRAFT_KEY] as $key) {
            $json = $settings->get($key);
            $layout = is_string($json) ? json_decode($json, true) : null;

            foreach (is_array($layout['components'] ?? null) ? $layout['components'] : [] as $component) {
                $id = is_array($component) && ($component['type'] ?? null) === 'html_block' ? ($component['settings']['html_block'] ?? null) : null;

                if ((is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id === $blockId) {
                    return true;
                }
            }
        }

        return false;
    }

    /** CD-1b: the names of the categories that show the block as their banner («Блок отгоре»). @return list<string> */
    public static function categories(int $blockId): array
    {
        return DB::table('catalog_categories')->where('header_block_id', $blockId)->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all();
    }

    /** Is the block shown anywhere (a menu, the footer, the right column or a category)? */
    public static function isUsed(int $blockId): bool
    {
        return self::menus($blockId) !== [] || self::footer($blockId) || self::summary($blockId) || self::categories($blockId) !== [];
    }

    /**
     * The refusal message of a delete, or null when the block is free. The formats for menus and the footer are unchanged; when the right column is one of the users, the message names
     * every user in one sentence (`in_use_places`).
     */
    public static function refusal(HtmlBlockModel $block): ?string
    {
        $menus = self::menus((int) $block->id);
        $footer = self::footer((int) $block->id);
        $summary = self::summary((int) $block->id);
        $categories = self::categories((int) $block->id);
        $params = ['title' => $block->title, 'menus' => implode(', ', $menus)];

        if ($categories !== [] && ! $summary && $menus === [] && ! $footer) {
            return (string) __('html_blocks.errors.in_use_category', ['title' => $block->title, 'categories' => implode(', ', $categories)]);
        }

        if ($summary || $categories !== []) {
            $places = [];

            if ($menus !== []) {
                $places[] = (string) __('html_blocks.places.menu', $params);
            }

            if ($footer) {
                $places[] = (string) __('html_blocks.places.footer');
            }

            $summary && $places[] = (string) __('html_blocks.places.summary');
            $categories !== [] && $places[] = (string) __('html_blocks.places.category', ['categories' => implode(', ', $categories)]);

            return (string) __('html_blocks.errors.in_use_places', ['title' => $block->title, 'places' => implode((string) __('html_blocks.places.join'), $places)]);
        }

        return match (true) {
            $menus !== [] && $footer => (string) __('html_blocks.errors.in_use_both', $params),
            $menus !== [] => (string) __('html_blocks.errors.in_use', $params),
            $footer => (string) __('html_blocks.errors.in_use_footer', $params),
            default => null,
        };
    }
}
