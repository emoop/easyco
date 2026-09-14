<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Filament\Resources\AttributeDefinitionResource;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Write-interception per admin-panel-design.md §5. */
class CreateAttributeDefinition extends CreateRecord
{
    protected static string $resource = AttributeDefinitionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $definition = new AttributeDefinition(
            id: null,
            code: $data['code'],
            name: $data['name'],
            type: AttributeType::from($data['type']),
        );

        app(AttributeDefinitionRepository::class)->save($definition);

        return AttributeDefinitionModel::find($definition->id());
    }
}
