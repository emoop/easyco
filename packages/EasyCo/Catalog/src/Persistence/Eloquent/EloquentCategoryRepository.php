<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;

/**
 * Maps Category onto catalog_categories via CategoryModel. Mirrors
 * EloquentAttributeDefinitionRepository's id-or-new + assignId() pattern
 * exactly. A duplicate `slug` is a plain unique-constraint violation
 * that propagates as a raw QueryException — no dedicated exception
 * wrapping, same precedent as EloquentBrandRepository/
 * EloquentAttributeDefinitionRepository.
 */
final class EloquentCategoryRepository implements CategoryRepository
{
    public function save(Category $category): void
    {
        $model = $category->id() !== null
            ? CategoryModel::findOrFail($category->id())
            : new CategoryModel();

        $model->parent_id = $category->parentId();
        $model->name = $category->name();
        $model->slug = $category->slug();
        $model->save();

        if ($category->id() === null) {
            $category->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?Category
    {
        $model = CategoryModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return Category[] */
    public function all(): array
    {
        return CategoryModel::all()
            ->map(fn (CategoryModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(CategoryModel $model): Category
    {
        return new Category(
            id: (string) $model->id,
            parentId: $model->parent_id !== null ? (string) $model->parent_id : null,
            name: $model->name,
            slug: $model->slug,
        );
    }
}
