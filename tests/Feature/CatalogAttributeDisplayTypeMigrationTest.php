<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * CT-3: `catalog_attribute_definitions.display_type` (string 16, not null, default 'dropdown'). Real DDL, like every DDL test of this project: down() removes it,
 * up() brings it back, and a row that already existed reads the default.
 */
class CatalogAttributeDisplayTypeMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const FILE = 'packages/EasyCo/Catalog/database/migrations/2026_10_27_000004_add_display_type_to_catalog_attribute_definitions_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        Schema::hasColumn('catalog_attribute_definitions', 'display_type') || $this->migration()->up();

        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    public function test_the_column_exists_with_its_type_null_rule_and_default(): void
    {
        $column = collect(DB::select("show columns from catalog_attribute_definitions where Field = 'display_type'"))->first();

        $this->assertMatchesRegularExpression('/^varchar\(16\)$/', $column->Type);
        $this->assertSame(['NO', 'dropdown'], [$column->Null, $column->Default]);
    }

    public function test_down_removes_the_column_up_brings_it_back_and_up_again_is_harmless(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('catalog_attribute_definitions', 'display_type'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('catalog_attribute_definitions', 'display_type'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('catalog_attribute_definitions', 'display_type'));
    }

    public function test_a_row_that_existed_before_reads_the_dropdown_display(): void
    {
        $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'old-display', 'name' => 'Old', 'type' => 'select', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('dropdown', DB::table('catalog_attribute_definitions')->where('id', $id)->value('display_type'));
    }
}
