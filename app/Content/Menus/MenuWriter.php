<?php

namespace App\Content\Menus;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Content\Models\PageModel;
use App\Rules\PlainText;
use App\Storefront\Menu\MenuRecompiler;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * THE ONLY WRITER of a menu (D3, MENU-2): the admin page and the seeder call it, nothing else saves menu rows. A tree is validated by MenuValidator, its
 * targets (categories, pages, products, HTML blocks) must exist, the rows (and the menu's locations) are replaced inside ONE transaction and the menus are compiled
 * before it commits: a menu that would compile to more than the size limit is refused and nothing is saved (ALL OR NOTHING).
 *
 * `main` and `footer` are never deleted; a menu that holds a location is not deleted either (the message names the place).
 */
final class MenuWriter
{
    public function __construct(private readonly MenuRecompiler $recompiler, private readonly MenuUsage $usage, private readonly MenuLocations $locations)
    {
    }

    /** @throws MenuException */
    public function create(string $key, string $name): MenuModel
    {
        $data = ['key' => trim($key), 'name' => trim($name)];
        $validator = Validator::make($data, [
            'key' => ['required', 'regex:/^[a-z0-9-]{1,40}$/', 'unique:menus,key'],
            'name' => ['required', 'string', 'max:100', new PlainText()],
        ], [], ['key' => __('menus.fields.key'), 'name' => __('menus.fields.name')]);

        if ($validator->fails()) {
            throw new MenuException($validator->errors()->first(), array_map(fn (array $m): string => $m[0], $validator->errors()->toArray()));
        }

        return MenuModel::create($data);
    }

    /**
     * Replaces the menu's items with this tree and renames it; when `$locations` is a list the menu holds exactly those locations afterwards (a location moves from the
     * menu that had it), null leaves them as they are. Returns the locations that moved (location => the name of the menu that had it).
     *
     * @param  array<mixed>  $items
     * @param  list<string>|null  $locations
     * @return array<string, string>
     *
     * @throws MenuException
     */
    public function save(MenuModel $menu, string $name, array $items, ?array $locations = null): array
    {
        $name = trim($name);
        $errors = [];

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = __('menus.errors.name');
        }

        $validation = MenuValidator::validate($items);
        $errors += $validation->errors;
        $errors += $this->missingTargets($validation->items, 'items');

        if ($locations !== null && array_diff($locations, MenuLocations::KEYS) !== []) {
            $errors['locations'] = __('menus.errors.location');
        }

        if ($errors !== []) {
            throw MenuException::invalid($errors);
        }

        $moved = [];

        DB::transaction(function () use ($menu, $name, $validation, $locations, &$moved): void {
            $menu->forceFill(['name' => $name])->save();
            MenuItemModel::query()->where('menu_id', $menu->id)->delete();
            $this->insert($menu->id, null, $validation->items);

            if ($locations !== null) {
                $moved = $this->locations->assign($menu->id, $locations);
            }

            $this->recompiler->now();
        });

        return $moved;
    }

    /** @throws MenuException */
    public function delete(MenuModel $menu): void
    {
        if ($menu->isProtected()) {
            throw new MenuException(__('menus.errors.protected', ['name' => $menu->name]));
        }

        $places = $this->usage->places($menu->key);

        if ($places !== []) {
            throw new MenuException(__('menus.errors.in_use', ['name' => $menu->name, 'places' => implode(', ', $places)]));
        }

        DB::transaction(function () use ($menu): void {
            $this->locations->release($menu->id);
            $menu->delete();
            $this->recompiler->now();
        });
    }

    /**
     * Inserts a validated tree (also used by the seeder).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function insert(int $menuId, ?int $parentId, array $items): void
    {
        foreach ($items as $sort => $item) {
            $row = MenuItemModel::create(array_intersect_key($item, array_flip(MenuValidator::FIELDS)) + ['menu_id' => $menuId, 'parent_id' => $parentId, 'sort' => $sort]);
            $this->insert($menuId, $row->id, $item['children'] ?? []);
        }
    }

    /**
     * The category / page / product targets and HTML blocks that do not exist, as errors by path.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function missingTargets(array $items, string $path): array
    {
        $found = ['category' => [], 'page' => [], 'product' => [], 'block' => []];
        $walk = function (array $items, string $path) use (&$walk, &$found): void {
            foreach ($items as $index => $item) {
                $here = "{$path}.{$index}";

                match ($item['type']) {
                    'category' => $found['category'][$here.'.target_id'] = $item['target_id'],
                    'page' => $found['page'][$here.'.target_id'] = $item['target_id'],
                    'product' => $found['product'][$here.'.target_id'] = $item['target_id'],
                    default => null,
                };

                if ($item['html_block_id'] !== null) {
                    $found['block'][$here.'.html_block_id'] = $item['html_block_id'];
                }

                $walk($item['children'], "{$here}.children");
            }
        };
        $walk($items, $path);

        $errors = [];
        $existing = [
            'category' => $found['category'] === [] ? [] : CategoryModel::query()->whereIn('id', array_unique($found['category']))->pluck('id')->all(),
            'page' => $found['page'] === [] ? [] : PageModel::query()->whereIn('id', array_unique($found['page']))->pluck('id')->all(),
            'product' => $found['product'] === [] ? [] : ProductModel::query()->whereIn('id', array_unique($found['product']))->pluck('id')->all(),
            'block' => $found['block'] === [] ? [] : HtmlBlockModel::query()->whereIn('id', array_unique($found['block']))->pluck('id')->all(),
        ];

        foreach ($found as $kind => $paths) {
            foreach ($paths as $itemPath => $id) {
                if (! in_array((int) $id, array_map('intval', $existing[$kind]), true)) {
                    $errors[$itemPath] = __('menus.errors.missing_'.$kind);
                }
            }
        }

        return $errors;
    }
}
