<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Filament\Resources\CollectionResource;
use EasyCo\Catalog\Contracts\CollectionRepository;
use EasyCo\Catalog\Persistence\Eloquent\CollectionModel;
use EasyCo\Catalog\Collection;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateCollection extends CreateRecord
{
    protected static string $resource = CollectionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $collection = new Collection(id: null, name: $data['name'], slug: $data['slug']);

        // CT-1.1: the picture is an id of the media library (the picker validated it); the asset is never touched here
        filled($data['picture_media_asset_id'] ?? null) && $collection->setPicture((string) $data['picture_media_asset_id']);

        app(CollectionRepository::class)->save($collection);

        return CollectionModel::find($collection->id());
    }
}
