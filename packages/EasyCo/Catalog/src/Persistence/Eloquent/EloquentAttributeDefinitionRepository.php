<?php

namespace EasyCo\Catalog\Persistence\Eloquent;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;

/**
 * Maps AttributeDefinition onto catalog_attribute_definitions via
 * AttributeDefinitionModel. Mirrors EloquentProductRepository's
 * id-or-new + assignId() pattern for the insert case; there is no
 * collision-retry here (unlike base_sku/slug/sku) — a duplicate `code`
 * is a plain unique-constraint violation that propagates as a
 * QueryException, since AttributeDefinition has no generated-candidate
 * concept to retry with.
 */
final class EloquentAttributeDefinitionRepository implements AttributeDefinitionRepository
{
    public function save(AttributeDefinition $definition): void
    {
        $model = $definition->id() !== null
            ? AttributeDefinitionModel::findOrFail($definition->id())
            : new AttributeDefinitionModel();

        $model->code = $definition->code();
        $model->name = $definition->name();
        $model->type = $definition->type()->value;
        $model->save();

        if ($definition->id() === null) {
            $definition->assignId((string) $model->id);
        }
    }

    public function findById(string $id): ?AttributeDefinition
    {
        $model = AttributeDefinitionModel::find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    /** @return AttributeDefinition[] */
    public function all(): array
    {
        return AttributeDefinitionModel::all()
            ->map(fn (AttributeDefinitionModel $model) => $this->toDomain($model))
            ->all();
    }

    private function toDomain(AttributeDefinitionModel $model): AttributeDefinition
    {
        return new AttributeDefinition(
            id: (string) $model->id,
            code: $model->code,
            name: $model->name,
            type: AttributeType::from($model->type),
        );
    }
}
