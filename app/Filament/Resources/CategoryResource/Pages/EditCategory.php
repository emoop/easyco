<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Write-interception per admin-panel-design.md §5. */
class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $category = app(CategoryRepository::class)->findById((string) $record->id);

        if ($category === null) {
            throw new RuntimeException("Category \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($category->name() !== $data['name']) {
            $category->rename($data['name']);
        }

        if ($category->slug() !== $data['slug']) {
            $category->changeSlug($data['slug']);
        }

        $newParentId = $data['parent_id'] ?? null;

        if ($category->parentId() !== $newParentId) {
            $category->changeParent($newParentId);
        }

        app(CategoryRepository::class)->save($category);

        return CategoryModel::find($category->id());
    }
}
