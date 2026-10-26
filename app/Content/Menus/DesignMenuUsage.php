<?php

namespace App\Content\Menus;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\ComponentRegistry;

/**
 * Where the «Дизайн» screen uses a menu (DS-3): a field of kind `menu` of a node of the header, of the footer or of the checkout header, in the PUBLISHED or the DRAFT layout. A menu used there is never
 * deleted (the same shape as HtmlBlockUsage::footer, for every zone). ONE batched read of the six stored layouts answers for every menu: `of()` is a lookup in it, never a query per menu. Admin only:
 * the layouts are not validated here (a bad stored one simply names nothing), and a field is a menu field because the zone's CATALOG says so (an extension's block counts like a built-in one).
 */
final class DesignMenuUsage
{
    /** zone => [setting key => is a draft] */
    private const KEYS = [
        'header' => [AppearanceSettings::HEADER_PUBLISHED_KEY => false, AppearanceSettings::HEADER_DRAFT_KEY => true],
        'footer' => [AppearanceSettings::FOOTER_PUBLISHED_KEY => false, AppearanceSettings::FOOTER_DRAFT_KEY => true],
        'checkout' => [AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY => false, AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY => true],
    ];

    /** @var array<int, array<string, array{zone: string, draft: bool}>>|null menu id => its places */
    private ?array $found = null;

    /**
     * The places that use a menu, as the admin reads them: «Дизайн — Хедър», «Дизайн — Футър (чернова)», …
     *
     * @return list<string> empty = the design does not use the menu
     */
    public function of(int $menuId): array
    {
        $labels = [];

        foreach ($this->found()[$menuId] ?? [] as $use) {
            $labels[] = __('menus.design.'.$use['zone']).($use['draft'] ? ' '.__('menus.design.draft') : '');
        }

        return $labels;
    }

    /** @return array<int, array<string, array{zone: string, draft: bool}>> */
    private function found(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        $keys = [];

        foreach (self::KEYS as $zone => $pairs) {
            foreach ($pairs as $key => $draft) {
                $keys[$key] = [$zone, $draft];
            }
        }

        $values = app(SiteSettingsRepository::class)->getMany(array_keys($keys));
        $menuFields = [
            'header' => $this->menuFields('header'),
            'footer' => $this->menuFields('footer'),
            'checkout' => $this->menuFields('checkout_header'),
        ];
        $found = [];

        foreach ($keys as $key => [$zone, $draft]) {
            $json = $values[$key] ?? null;
            $data = is_string($json) ? json_decode($json, true) : null;

            if (! is_array($data)) {
                continue;
            }

            $ids = $zone === 'checkout' ? $this->flat($data, $menuFields['checkout']['checkout_header'] ?? []) : $this->nodes($data, $menuFields[$zone]);

            foreach ($ids as $id) {
                $found[$id][$zone.'|'.($draft ? 'd' : 'p')] = ['zone' => $zone, 'draft' => $draft];
            }
        }

        return $this->found = $found;
    }

    /**
     * type => the names of its fields of kind `menu`, for the types that have one.
     *
     * @return array<string, list<string>>
     */
    private function menuFields(string $zone): array
    {
        $out = [];

        foreach (ComponentRegistry::for($zone) as $type => $component) {
            $names = [];

            foreach ($component['fields'] as $name => $field) {
                is_array($field) && ($field['kind'] ?? null) === 'menu' && $names[] = (string) $name;
            }

            $names !== [] && $out[(string) $type] = $names;
        }

        return $out;
    }

    /**
     * The menu ids of every node `{type, settings}` at any depth of a stored layout.
     *
     * @param  array<string, list<string>>  $menuFields
     * @return list<int>
     */
    private function nodes(array $node, array $menuFields): array
    {
        $ids = [];
        $type = $node['type'] ?? null;

        if (is_string($type) && isset($menuFields[$type]) && is_array($node['settings'] ?? null)) {
            foreach ($menuFields[$type] as $name) {
                $id = $this->id($node['settings'][$name] ?? null);

                $id !== null && $ids[] = $id;
            }
        }

        foreach ($node as $value) {
            is_array($value) && $ids = [...$ids, ...$this->nodes($value, $menuFields)];
        }

        return $ids;
    }

    /**
     * The menu ids of a zone that stores flat values (the checkout header): the fields of kind `menu` of its one component.
     *
     * @param  list<string>  $names
     * @return list<int>
     */
    private function flat(array $values, array $names): array
    {
        return array_values(array_filter(array_map(fn (string $name): ?int => $this->id($values[$name] ?? null), $names), static fn (?int $id): bool => $id !== null));
    }

    private function id(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }
}
