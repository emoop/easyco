<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\Contracts\ProductTemplateRepository;
use EasyCo\Catalog\ProductTemplate;

/**
 * Maps ProductTemplate onto catalog_product_templates via
 * ProductTemplateModel. Identical id-or-new + assignId() pattern as
 * EloquentSeasonRepository/EloquentProductGroupRepository.
 */
final class EloquentProductTemplateRepository implements ProductTemplateRepository
{
    public function save(ProductTemplate $productTemplate): void
    {
        $model = $productTemplate->id() !== null
            ? ProductTemplateModel::findOrFail($productTemplate->id())
            : new ProductTemplateModel;

        $model->name = $productTemplate->name();
        $model->brand_id = $productTemplate->brandId();
        $model->season_id = $productTemplate->seasonId();
        $model->product_group_id = $productTemplate->productGroupId();
        $model->category_ids = $productTemplate->categoryIds();
        $model->tag_ids = $productTemplate->tagIds();
        $model->save();

        if ($productTemplate->id() === null) {
            $productTemplate->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?ProductTemplate
    {
        $model = ProductTemplateModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return ProductTemplate[] */
    public function all(): array
    {
        return ProductTemplateModel::all()
            ->map(fn (ProductTemplateModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(ProductTemplateModel $model): ProductTemplate
    {
        return new ProductTemplate(
            id: (string) $model->id,
            name: $model->name,
            brandId: $model->brand_id !== null ? (string) $model->brand_id : null,
            seasonId: $model->season_id !== null ? (string) $model->season_id : null,
            productGroupId: $model->product_group_id !== null ? (string) $model->product_group_id : null,
            categoryIds: $model->category_ids ?? [],
            tagIds: $model->tag_ids ?? [],
        );
    }
}
