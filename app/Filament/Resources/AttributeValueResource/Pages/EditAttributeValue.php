<?php

namespace App\Filament\Resources\AttributeValueResource\Pages;

use App\Filament\Resources\AttributeValueResource;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Exceptions\InvalidAttributeColourException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5. Only ever calls
 * ->rename()/->changeSortOrder() — `attribute_definition_id` is
 * ->disabledOn('edit'), so Filament never includes it in $data here
 * (same confirmed dehydration behavior as AttributeDefinitionResource's
 * disabled code/type fields).
 */
class EditAttributeValue extends EditRecord
{
    protected static string $resource = AttributeValueResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $value = app(AttributeValueRepository::class)->findById((string) $record->id);

        if ($value === null) {
            throw new RuntimeException("AttributeValue \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($value->value() !== $data['value']) {
            $value->rename($data['value']);
        }

        $newSortOrder = (int) ($data['sort_order'] ?? 0);

        if ($value->sortOrder() !== $newSortOrder) {
            $value->changeSortOrder($newSortOrder);
        }

        // CT-1: «Премахни изображението» only detaches (the asset stays in «Медия»); a save that does not touch the field keeps the picture
        $picture = filled($data['picture_media_asset_id'] ?? null) ? (string) $data['picture_media_asset_id'] : null;

        if ($picture === null && $value->pictureMediaAssetId() !== null) {
            $value->removePicture();
        } elseif ($picture !== null && $picture !== $value->pictureMediaAssetId()) {
            $value->setPicture($picture);
        }

        // AT-1: a hidden picker (the attribute is not a colour one) sends no key: the stored colour stays; a cleared picker sends null: no colour
        try {
            array_key_exists('colour', $data) && $value->setColour($data['colour'] === null ? null : (string) $data['colour']);
        } catch (InvalidAttributeColourException) {
            throw ValidationException::withMessages(['data.colour' => __('attribute_values.errors.colour')]);
        }

        app(AttributeValueRepository::class)->save($value);

        return AttributeValueModel::find($value->id());
    }
}
