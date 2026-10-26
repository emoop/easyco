<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * CT-2: `catalog_attribute_definitions.is_filterable` (boolean, not null, default 0) and `filter_display` (string 16, not null, default 'list'). Real DDL, like every DDL
 * test of this project: down() removes both, up() brings them back, and a row that already existed reads the defaults.
 */
class CatalogAttributeFilterColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const FILE = 'packages/EasyCo/Catalog/database/migrations/2026_10_27_000003_add_filter_fields_to_catalog_attribute_definitions_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        Schema::hasColumn('catalog_attribute_definitions', 'is_filterable') && Schema::hasColumn('catalog_attribute_definitions', 'filter_display') || $this->migration()->up();

        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function column(string $name): ?object
    {
        return collect(DB::select("show columns from catalog_attribute_definitions where Field = '{$name}'"))->first();
    }

    public function test_both_columns_exist_with_their_type_null_rule_and_default(): void
    {
        $filterable = $this->column('is_filterable');
        $display = $this->column('filter_display');

        $this->assertSame(['tinyint(1)', 'NO', '0'], [$filterable->Type, $filterable->Null, $filterable->Default]);
        $this->assertMatchesRegularExpression('/^varchar\(16\)$/', $display->Type);
        $this->assertSame(['NO', 'list'], [$display->Null, $display->Default]);
    }

    public function test_down_removes_both_columns_up_brings_them_back_and_up_again_is_harmless(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('catalog_attribute_definitions', 'is_filterable'));
        $this->assertFalse(Schema::hasColumn('catalog_attribute_definitions', 'filter_display'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('catalog_attribute_definitions', 'is_filterable'));
        $this->assertTrue(Schema::hasColumn('catalog_attribute_definitions', 'filter_display'));

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('catalog_attribute_definitions', 'filter_display'));
    }

    public function test_a_row_that_existed_before_reads_no_filter_and_the_list_display(): void
    {
        $id = DB::table('catalog_attribute_definitions')->insertGetId(['code' => 'old-row', 'name' => 'Old', 'type' => 'select', 'created_at' => now(), 'updated_at' => now()]);

        $row = DB::table('catalog_attribute_definitions')->where('id', $id)->first();

        $this->assertSame([0, 'list'], [(int) $row->is_filterable, $row->filter_display]);
    }
}
