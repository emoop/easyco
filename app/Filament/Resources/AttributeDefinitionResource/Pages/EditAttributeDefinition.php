<?php

namespace App\Filament\Resources\AttributeDefinitionResource\Pages;

use App\Filament\Resources\AttributeDefinitionResource;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Exceptions\InvalidAttributeFilterException;
use EasyCo\Catalog\Persistence\Eloquent\AttributeDefinitionModel;
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

        app(AttributeDefinitionRepository::class)->save($definition);

        return AttributeDefinitionModel::find($definition->id());
    }
}
