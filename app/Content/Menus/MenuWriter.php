<?php

namespace App\Content\Menus;

use App\Content\Models\PageModel;
use App\Rules\PlainText;
use App\Storefront\Menu\MenuRecompiler;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * THE ONLY WRITER of a menu (D3): the admin page and the seeder call it, nothing else saves menu rows. A tree is validated by MenuValidator, its
 * targets (categories, pages, image assets) must exist, the rows are replaced inside ONE transaction and the menus are compiled before it
 * commits: a menu that would compile to more than the size limit is refused and nothing is saved.
 *
 * `main` and `footer` are never deleted; a menu the header uses is not deleted either (the message names the place).
 */
final class MenuWriter
{
    public function __construct(private readonly MenuRecompiler $recompiler, private readonly MenuUsage $usage)
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
     * Replaces the menu's items with this tree and renames it.
     *
     * @param  array<mixed>  $items
     *
     * @throws MenuException
     */
    public function save(MenuModel $menu, string $name, array $items): void
    {
        $name = trim($name);
        $errors = [];

        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = __('menus.errors.name');
        }

        $validation = MenuValidator::validate($items);
        $errors += $validation->errors;
        $errors += $this->missingTargets($validation->items, 'items');

        if ($errors !== []) {
            throw MenuException::invalid($errors);
        }

        DB::transaction(function () use ($menu, $name, $validation): void {
            $menu->forceFill(['name' => $name])->save();
            MenuItemModel::query()->where('menu_id', $menu->id)->delete();
            $this->insert($menu->id, null, $validation->items);
            $this->recompiler->now();
        });
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
     * The category / page / image targets that do not exist, as errors by path.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function missingTargets(array $items, string $path): array
    {
        $found = ['category' => [], 'page' => [], 'asset' => []];
        $walk = function (array $items, string $path) use (&$walk, &$found): void {
            foreach ($items as $index => $item) {
                $here = "{$path}.{$index}";

                match ($item['type']) {
                    'category' => $found['category'][$here.'.target_id'] = $item['target_id'],
                    'page' => $found['page'][$here.'.target_id'] = $item['target_id'],
                    'image' => $found['asset'][$here.'.media_asset_id'] = $item['media_asset_id'],
                    default => null,
                };
                $walk($item['children'], "{$here}.children");
            }
        };
        $walk($items, $path);

        $errors = [];
        $existing = [
            'category' => $found['category'] === [] ? [] : CategoryModel::query()->whereIn('id', array_unique($found['category']))->pluck('id')->all(),
            'page' => $found['page'] === [] ? [] : PageModel::query()->whereIn('id', array_unique($found['page']))->pluck('id')->all(),
            'asset' => $found['asset'] === [] ? [] : MediaAssetModel::query()->where('type', 'image')->whereIn('id', array_unique($found['asset']))->pluck('id')->all(),
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
