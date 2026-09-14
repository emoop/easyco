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

        app(SeasonRepository::class)->save($season);

        return SeasonModel::find($season->id());
    }
}
