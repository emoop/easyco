<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use EasyCo\Catalog\Season;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateSeason extends CreateRecord
{
    protected static string $resource = SeasonResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $season = new Season(id: null, name: $data['name'], slug: $data['slug']);

        // CT-1.1: the picture is an id of the media library (the picker validated it); the asset is never touched here
        filled($data['picture_media_asset_id'] ?? null) && $season->setPicture((string) $data['picture_media_asset_id']);

        app(SeasonRepository::class)->save($season);

        return SeasonModel::find($season->id());
    }
}
