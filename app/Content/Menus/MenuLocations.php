<?php

namespace App\Content\Menus;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * Where a menu sits in the shop (MENU-2, menu-editor-v2-design.md M-D4): `header` (the bar), `drawer` (the phone menu; empty = the header's menu) and `footer`.
 * ONE menu per location, a menu may hold several. Stored as `menus.locations` = {"header": id|null, "drawer": id|null, "footer": id|null}; the storefront reads the
 * keys from the compiled copy (MenuCompiler), never this setting. A location with no menu renders NOTHING (there is no fallback to the category tree).
 */
final class MenuLocations
{
    public const KEYS = ['header', 'drawer', 'footer'];

    public function __construct(private readonly SiteSettingsRepository $settings)
    {
    }

    /** @return array<string, ?int> */
    public function all(): array
    {
        $json = $this->settings->get(AppearanceSettings::MENUS_LOCATIONS_KEY);
        $data = is_string($json) ? json_decode($json, true) : null;
        $data = is_array($data) ? $data : [];
        $out = [];

        foreach (self::KEYS as $location) {
            $id = $data[$location] ?? null;
            $out[$location] = is_int($id) && $id > 0 ? $id : null;
        }

        return $out;
    }

    public function has(): bool
    {
        return $this->settings->get(AppearanceSettings::MENUS_LOCATIONS_KEY) !== null;
    }

    /** @return list<string> the locations this menu holds */
    public function of(int $menuId): array
    {
        return array_keys(array_filter($this->all(), fn (?int $id): bool => $id === $menuId));
    }

    /** @param  array<string, ?int>  $ids */
    public function store(array $ids): void
    {
        $clean = [];

        foreach (self::KEYS as $location) {
            $id = $ids[$location] ?? null;
            $clean[$location] = is_int($id) && $id > 0 ? $id : null;
        }

        $this->settings->set(AppearanceSettings::MENUS_LOCATIONS_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR));
    }

    /**
     * This menu holds exactly these locations: it takes each ticked one (moving it from the menu that had it) and lets go of the others.
     *
     * @param  list<string>  $locations
     * @return array<string, string> the locations that moved, location => the name of the menu that had it
     */
    public function assign(int $menuId, array $locations): array
    {
        $ids = $this->all();
        $moved = [];

        foreach (self::KEYS as $location) {
            if (in_array($location, $locations, true)) {
                if ($ids[$location] !== null && $ids[$location] !== $menuId) {
                    $moved[$location] = (string) MenuModel::query()->whereKey($ids[$location])->value('name');
                }

                $ids[$location] = $menuId;
            } elseif ($ids[$location] === $menuId) {
                $ids[$location] = null;
            }
        }

        $this->store($ids);

        return $moved;
    }

    /** A deleted menu holds no location any more. */
    public function release(int $menuId): void
    {
        $this->assign($menuId, []);
    }

    /**
     * The key of the menu in each location (a location whose menu is gone is null).
     *
     * @return array<string, ?string>
     */
    public function keys(): array
    {
        $ids = $this->all();
        $keys = MenuModel::query()->whereIn('id', array_filter($ids))->pluck('key', 'id')->all();
        $out = [];

        foreach (self::KEYS as $location) {
            $out[$location] = $ids[$location] !== null ? ($keys[$ids[$location]] ?? null) : null;
        }

        return $out;
    }
}
