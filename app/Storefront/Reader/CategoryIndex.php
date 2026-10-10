<?php

namespace App\Storefront\Reader;

use App\Storefront\ReadModels\Breadcrumb;
use App\Storefront\ReadModels\CategoryNode;
use App\Storefront\Url\StorefrontUrls;
use App\Storefront\Visibility\StorefrontVisibility;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\DB;

/**
 * The category structure of the shop, loaded ONCE per request in TWO queries (all categories; the visible
 * (category, product) pairs) and then answered from memory: the tree, "is this category shown", canonical paths,
 * descendant ids and distinct visible-product counts. Nothing here queries per node.
 *
 * (Categories are `catalog_categories`: id, parent_id, name, slug; no soft delete, no active flag. Their tree is
 * parent_id. Slugs are globally unique.) A category is shown when its subtree holds at least one visible product
 * (StorefrontVisibility::isCategoryShown). The pair read is the scaling cost of exact distinct counts; S4 caches it.
 *
 * A broken tree (a parent_id cycle in a hand-edited database) cannot loop: every walk keeps a visited set, and a
 * category in a cycle is simply not reachable from a root.
 */
#[Scoped]
final class CategoryIndex
{
    private bool $loaded = false;

    /** @var array<int, array{id: int, parent: ?int, name: string, slug: string}> */
    private array $byId = [];

    /** @var array<int, list<int>> */
    private array $childrenOf = [];

    /** @var array<string, int> lower-cased slug => id */
    private array $idBySlug = [];

    /** @var array<int, array<int, true>> category id => set of visible product ids in its subtree */
    private array $subtreeProducts = [];

    /** @var array<int, string> */
    private array $paths = [];

    public function __construct(
        private readonly StorefrontVisibility $visibility,
        private readonly StorefrontUrls $urls,
    ) {
    }

    /** @return list<CategoryNode> shown root categories with their shown descendants, by name then id */
    public function tree(): array
    {
        $this->load();

        $roots = [];

        foreach ($this->byId as $id => $row) {
            if ($row['parent'] === null || ! isset($this->byId[$row['parent']])) {
                $roots[] = $id;
            }
        }

        return $this->nodes($roots, []);
    }

    /** The id of a SHOWN category by slug (case-insensitive: slugs are stored lower-case), or null. */
    public function shownIdBySlug(string $slug): ?int
    {
        $this->load();

        $id = $this->idBySlug[mb_strtolower($slug, 'UTF-8')] ?? null;

        return $id !== null && $this->isShown($id) ? $id : null;
    }

    public function isShown(int $id): bool
    {
        $this->load();

        return isset($this->byId[$id]) && $this->visibility->isCategoryShown(count($this->subtreeProducts[$id] ?? []));
    }

    /** @return array{id: int, name: string, slug: string}|null */
    public function row(int $id): ?array
    {
        $this->load();

        return isset($this->byId[$id])
            ? ['id' => $id, 'name' => $this->byId[$id]['name'], 'slug' => $this->byId[$id]['slug']]
            : null;
    }

    /** Canonical path: ancestor slugs from the root, then its own. */
    public function path(int $id): string
    {
        $this->load();

        return $this->paths[$id] ?? '';
    }

    public function productCount(int $id): int
    {
        $this->load();

        return count($this->subtreeProducts[$id] ?? []);
    }

    /** @return list<int> the id and every descendant id */
    public function selfAndDescendantIds(int $id): array
    {
        $this->load();

        $ids = [];
        $stack = [$id];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($ids[$current]) || ! isset($this->byId[$current])) {
                continue;
            }

            $ids[$current] = $current;

            foreach ($this->childrenOf[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_values($ids);
    }

    /** @return list<int> ancestors from the root down to (excluding) the category */
    public function ancestorIds(int $id): array
    {
        $this->load();

        $chain = [];
        $current = $this->byId[$id]['parent'] ?? null;

        while ($current !== null && isset($this->byId[$current]) && ! in_array($current, $chain, true) && $current !== $id) {
            array_unshift($chain, $current);
            $current = $this->byId[$current]['parent'];
        }

        return $chain;
    }

    /** @return list<Breadcrumb> the ancestors, then the category itself */
    public function breadcrumbs(int $id): array
    {
        $crumbs = [];

        foreach ([...$this->ancestorIds($id), $id] as $step) {
            $crumbs[] = new Breadcrumb($this->byId[$step]['name'], $this->urls->category($this->paths[$step]));
        }

        return $crumbs;
    }

    /** The shown children of a category as nodes. @return list<CategoryNode> */
    public function shownChildren(int $id): array
    {
        $this->load();

        return $this->nodes($this->childrenOf[$id] ?? [], [$id]);
    }

    public function depth(int $id): int
    {
        return count($this->ancestorIds($id));
    }

    /**
     * @param list<int> $ids
     * @param list<int> $trail ids already on the way down (cycle guard)
     * @return list<CategoryNode>
     */
    private function nodes(array $ids, array $trail): array
    {
        $nodes = [];

        foreach ($ids as $id) {
            if (in_array($id, $trail, true) || ! $this->isShown($id)) {
                continue;
            }

            $row = $this->byId[$id];
            $nodes[] = new CategoryNode(
                id: (string) $id,
                name: $row['name'],
                slug: $row['slug'],
                path: $this->paths[$id],
                url: $this->urls->category($this->paths[$id]),
                productCount: count($this->subtreeProducts[$id] ?? []),
                children: $this->nodes($this->childrenOf[$id] ?? [], [...$trail, $id]),
            );
        }

        return $nodes;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        $rows = DB::table('catalog_categories')->select('id', 'parent_id', 'name', 'slug')->orderBy('name')->orderBy('id')->get();

        foreach ($rows as $row) {
            $id = (int) $row->id;
            $this->byId[$id] = [
                'id' => $id,
                'parent' => $row->parent_id === null ? null : (int) $row->parent_id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
            ];
            $this->idBySlug[mb_strtolower((string) $row->slug, 'UTF-8')] = $id;
        }

        foreach ($this->byId as $id => $row) {
            if ($row['parent'] !== null && isset($this->byId[$row['parent']])) {
                $this->childrenOf[$row['parent']][] = $id;
            }
        }

        foreach (array_keys($this->byId) as $id) {
            $this->paths[$id] = implode('/', array_map(
                fn (int $step): string => $this->byId[$step]['slug'],
                [...$this->ancestorIds($id), $id],
            ));
        }

        $direct = [];

        foreach ($this->visibility->visibleCategoryPairs()->get() as $pair) {
            $direct[(int) $pair->category_id][(int) $pair->product_id] = true;
        }

        foreach (array_keys($this->byId) as $id) {
            $set = [];

            foreach ($this->selfAndDescendantIds($id) as $member) {
                foreach ($direct[$member] ?? [] as $productId => $true) {
                    $set[$productId] = true;
                }
            }

            $this->subtreeProducts[$id] = $set;
        }
    }
}
