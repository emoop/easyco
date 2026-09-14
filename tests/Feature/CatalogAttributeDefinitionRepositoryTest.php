<?php

namespace Tests\Feature;

use EasyCo\Catalog\AttributeDefinition;
use EasyCo\Catalog\Contracts\AttributeDefinitionRepository;
use EasyCo\Catalog\Enums\AttributeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests EloquentAttributeDefinitionRepository's rename() round trip
 * against real MySQL — the save/findById/all shape itself is already
 * exercised elsewhere (this repository predates this task); only the
 * new mutator's persistence needs proving here.
 */
class CatalogAttributeDefinitionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_rename_then_save_persists_the_new_name(): void
    {
        $repository = app(AttributeDefinitionRepository::class);

        $definition = new AttributeDefinition(id: null, code: 'color', name: 'Color', type: AttributeType::SELECT);
        $repository->save($definition);

        $definition->rename('Colour');
        $repository->save($definition);

        $reloaded = $repository->findById($definition->id());

        $this->assertSame('Colour', $reloaded->name());
        $this->assertSame('color', $reloaded->code());
        $this->assertSame(AttributeType::SELECT, $reloaded->type());
    }
}
