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

        // CT-1: «Премахни изображението» sets the field to null and only detaches (the asset stays in «Медия», now unused); a save that does not touch it keeps the picture
        $picture = filled($data['picture_media_asset_id'] ?? null) ? (string) $data['picture_media_asset_id'] : null;

        if ($picture === null && $category->pictureMediaAssetId() !== null) {
            $category->removePicture();
        } elseif ($picture !== null && $picture !== $category->pictureMediaAssetId()) {
            $category->setPicture($picture);
        }

        app(CategoryRepository::class)->save($category);

        // CD-1b: «Блок отгоре» is a plain column of the category row
        \Illuminate\Support\Facades\DB::table('catalog_categories')->where('id', $category->id())->update(['header_block_id' => filled($data['header_block_id'] ?? null) ? (int) $data['header_block_id'] : null]);

        return CategoryModel::find($category->id());
    }
}
