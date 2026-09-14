<?php

namespace App\Filament\Resources\AttributeValueResource\Pages;

use App\Filament\Resources\AttributeValueResource;
use EasyCo\Catalog\AttributeValue;
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

        app(AttributeValueRepository::class)->save($value);

        return AttributeValueModel::find($value->id());
    }
}
