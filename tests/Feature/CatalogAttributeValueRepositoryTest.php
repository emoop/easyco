<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\AttributeValue;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Contracts\AttributeValueRepository;
use EasyCo\Catalog\Enums\AttributeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentAttributeValueRepository's rename()/changeSortOrder()
 * round trip against real MySQL — the save/findById shape itself
 * predates this task; only the new mutators' persistence needs proving
 * here.
 */
class CatalogAttributeValueRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function persistedDefinition(): AttributeDefinition
    {
        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        app(AttributeDefinitionRepository::class)->save($definition);

        return $definition;
    }

    public function test_rename_then_save_persists_the_new_value(): void
    {
        $repository = app(AttributeValueRepository::class);
        $definition = $this->persistedDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black');
        $repository->save($value);

        $value->rename('Jet Black');
        $repository->save($value);

        $reloaded = $repository->findById($value->id());

        $this->assertSame('Jet Black', $reloaded->value());
    }

    public function test_change_sort_order_then_save_persists_the_new_order(): void
    {
        $repository = app(AttributeValueRepository::class);
        $definition = $this->persistedDefinition();

        $value = new AttributeValue(id: null, attributeDefinitionId: $definition->id(), value: 'Black', sortOrder: 0);
        $repository->save($value);

        $value->changeSortOrder(5);
        $repository->save($value);

        $reloaded = $repository->findById($value->id());

        $this->assertSame(5, $reloaded->sortOrder());
    }
}
