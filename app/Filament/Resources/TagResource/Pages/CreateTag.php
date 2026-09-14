<?php

namespace App\Filament\Resources\TagResource\Pages;

use App\Filament\Resources\TagResource;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use EasyCo\Catalog\Tag;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateTag extends CreateRecord
{
    protected static string $resource = TagResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $tag = new Tag(id: null, name: $data['name'], slug: $data['slug']);

        app(TagRepository::class)->save($tag);

        return TagModel::find($tag->id());
    }
}
