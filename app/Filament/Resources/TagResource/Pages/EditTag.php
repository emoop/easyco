<?php

namespace App\Filament\Resources\TagResource\Pages;

use App\Filament\Resources\TagResource;
use EasyCo\Catalog\Contracts\TagRepository;
use EasyCo\Catalog\Persistence\Eloquent\TagModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Write-interception per admin-panel-design.md §5. */
class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $tag = app(TagRepository::class)->findById((string) $record->id);

        if ($tag === null) {
            throw new RuntimeException("Tag \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($tag->name() !== $data['name']) {
            $tag->rename($data['name']);
        }

        if ($tag->slug() !== $data['slug']) {
            $tag->changeSlug($data['slug']);
        }

        app(TagRepository::class)->save($tag);

        return TagModel::find($tag->id());
    }
}
