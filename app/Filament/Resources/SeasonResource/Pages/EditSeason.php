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

        app(SeasonRepository::class)->save($season);

        return SeasonModel::find($season->id());
    }
}
