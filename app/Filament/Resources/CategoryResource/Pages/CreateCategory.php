<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $category = new Category(
            id: null,
            parentId: $data['parent_id'] ?? null,
            name: $data['name'],
            slug: $data['slug'],
        );

        // CT-1: the picture is an id of the media library (the picker validated it); the asset is never touched here
        filled($data['picture_media_asset_id'] ?? null) && $category->setPicture((string) $data['picture_media_asset_id']);

        app(CategoryRepository::class)->save($category);

        return CategoryModel::find($category->id());
    }
}
