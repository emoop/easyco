<?php

namespace App\Filament\Resources\AttributeValueResource\Pages;

use App\Filament\Resources\AttributeValueResource;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Exceptions\InvalidAttributeColourException;
use Illuminate\Validation\ValidationException;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Persistence\Eloquent\AttributeValueModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateAttributeValue extends CreateRecord
{
    protected static string $resource = AttributeValueResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $value = new AttributeValue(
            id: null,
            attributeDefinitionId: (string) $data['attribute_definition_id'],
            value: $data['value'],
            sortOrder: (int) ($data['sort_order'] ?? 0),
        );

        // CT-1: the picture is an id of the media library (the picker validated it)
        filled($data['picture_media_asset_id'] ?? null) && $value->setPicture((string) $data['picture_media_asset_id']);

        $this->applyColour($value, $data);

        app(AttributeValueRepository::class)->save($value);

        return AttributeValueModel::find($value->id());
    }

    /** AT-1: a colour comes only from the visible picker; a refused one is a field error and nothing is saved. */
    private function applyColour(AttributeValue $value, array $data): void
    {
        try {
            array_key_exists('colour', $data) && $value->setColour($data['colour'] === null ? null : (string) $data['colour']);
        } catch (InvalidAttributeColourException) {
            throw ValidationException::withMessages(['data.colour' => __('attribute_values.errors.colour')]);
        }
    }
}
