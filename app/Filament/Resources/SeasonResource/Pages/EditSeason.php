<?php

namespace App\Filament\Resources\SeasonResource\Pages;

use App\Filament\Resources\SeasonResource;
use EasyCo\Catalog\Contracts\SeasonRepository;
use EasyCo\Catalog\Persistence\Eloquent\SeasonModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Write-interception per admin-panel-design.md §5. */
class EditSeason extends EditRecord
{
    protected static string $resource = SeasonResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $season = app(SeasonRepository::class)->findById((string) $record->id);

        if ($season === null) {
            throw new RuntimeException("Season \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($season->name() !== $data['name']) {
            $season->rename($data['name']);
        }

        if ($season->slug() !== $data['slug']) {
            $season->changeSlug($data['slug']);
        }

        // CT-1.1: «Премахни изображението» sets the field to null and only detaches (the asset stays in «Медия», now unused); a save that does not touch it keeps the picture
        $picture = filled($data['picture_media_asset_id'] ?? null) ? (string) $data['picture_media_asset_id'] : null;

        if ($picture === null && $season->pictureMediaAssetId() !== null) {
            $season->removePicture();
        } elseif ($picture !== null && $picture !== $season->pictureMediaAssetId()) {
            $season->setPicture($picture);
        }

        app(SeasonRepository::class)->save($season);

        return SeasonModel::find($season->id());
    }
}
