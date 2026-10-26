<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Filament\Resources\AttributeDefinitionResource;
use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use EasyCo\Catalog\Exceptions\InvalidAttributeFilterException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Write-interception per admin-panel-design.md §5. */
class CreateAttributeDefinition extends CreateRecord
{
    protected static string $resource = AttributeDefinitionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            // CT-2: the filter settings go through the domain, which refuses a filter on an attribute without a closed list.
            $definition = new AttributeDefinition(
                id: null,
                code: $data['code'],
                name: $data['name'],
                type: AttributeType::from($data['type']),
                filterable: (bool) ($data['is_filterable'] ?? false),
            );
            $definition->setFilterDisplay((string) ($data['filter_display'] ?? 'list'));
        } catch (InvalidAttributeFilterException $exception) {
            throw ValidationException::withMessages(['data.is_filterable' => __('attribute_definitions.errors.not_closed_list')]);
        }

        // CT-3: the display type is refused on its own field, with its own message.
        try {
            $definition->setDisplayType((string) ($data['display_type'] ?? 'dropdown'));
        } catch (InvalidAttributeFilterException) {
            throw ValidationException::withMessages(['data.display_type' => __('attribute_definitions.errors.unknown_display_type')]);
        }

        app(AttributeDefinitionRepository::class)->save($definition);

        return AttributeDefinitionModel::find($definition->id());
    }
}
