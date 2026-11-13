<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Filament\Resources\AttributeDefinitionResource;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Exceptions\InvalidAttributeFilterException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Write-interception per admin-panel-design.md §5. Only ever calls
 * ->rename() — `code` and `type` are ->disabledOn('edit') fields on
 * the form, so Filament never includes them in $data at all here
 * (confirmed against the installed source: disabled() wires
 * ->saved(fn () => false), which controls dehydration — a disabled
 * field's key is omitted from the submitted array entirely, not merely
 * reverted to its old value), which is exactly why this handler never
 * references $data['code']/$data['type'].
 */
class EditAttributeDefinition extends EditRecord
{
    protected static string $resource = AttributeDefinitionResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $definition = app(AttributeDefinitionRepository::class)->findById((string) $record->id);

        if ($definition === null) {
            throw new RuntimeException("AttributeDefinition \"{$record->id}\" could not be reloaded from the domain layer.");
        }

        if ($definition->name() !== $data['name']) {
            $definition->rename($data['name']);
        }

        // CT-2: a hidden display select keeps its stored value (the form holds it), so saving without touching the fields keeps them.
        try {
            $definition->setFilterable((bool) ($data['is_filterable'] ?? false));
            $definition->setFilterDisplay((string) ($data['filter_display'] ?? $definition->filterDisplay()->value));
        } catch (InvalidAttributeFilterException) {
            throw ValidationException::withMessages(['data.is_filterable' => __('attribute_definitions.errors.not_closed_list')]);
        }

        // CT-3: the display type, on its own field and with its own message (the stored value stays when the form holds it).
        try {
            $definition->setDisplayType((string) ($data['display_type'] ?? $definition->displayType()->value));
        } catch (InvalidAttributeFilterException) {
            throw ValidationException::withMessages(['data.display_type' => __('attribute_definitions.errors.unknown_display_type')]);
        }

        // MEAS-3: the size checkbox (hidden and false for a type without a closed list); the change is one audit entry, old -> new
        $wasSize = $definition->isSize();
        $definition->setSize(AttributeDefinitionResource::typeCanBeSize($definition->type()->value) && (bool) ($data['is_size'] ?? false));

        app(AttributeDefinitionRepository::class)->save($definition);

        if ($wasSize !== $definition->isSize()) {
            app(ActivityLogger::class)->logFieldChanged('attribute_definition', (string) $definition->id(), 'is_size', $wasSize ? '1' : '0', $definition->isSize() ? '1' : '0');
        }

        return AttributeDefinitionModel::find($definition->id());
    }
}
