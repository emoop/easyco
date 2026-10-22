<?php

namespace App\Content\HtmlBlocks;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * Where an HTML block is shown: by the mega item of a menu, and (HDR-3) by an `html_block` block of the PUBLISHED or the DRAFT footer layout. A block in use is never deleted; the message
 * names the menus, and the footer as one more name. Admin only (the storefront never calls this): the footer layouts are read through the settings repository.
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

    /** Is the block shown anywhere (a menu or the footer)? */
    public static function isUsed(int $blockId): bool
    {
        return self::menus($blockId) !== [] || self::footer($blockId);
    }

    /** The refusal message of a delete, or null when the block is free. The format for menus is unchanged; the footer is one more name. */
    public static function refusal(HtmlBlockModel $block): ?string
    {
        $menus = self::menus((int) $block->id);
        $footer = self::footer((int) $block->id);
        $params = ['title' => $block->title, 'menus' => implode(', ', $menus)];

        return match (true) {
            $menus !== [] && $footer => (string) __('html_blocks.errors.in_use_both', $params),
            $menus !== [] => (string) __('html_blocks.errors.in_use', $params),
            $footer => (string) __('html_blocks.errors.in_use_footer', $params),
            default => null,
        };
    }
}
