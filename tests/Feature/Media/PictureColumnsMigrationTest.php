<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * CT-1: the migration of `picture_media_asset_id` on `catalog_categories` and `catalog_attribute_values`: the nullable column, the index and the foreign key with their explicit short names and RESTRICT, and a
 * `down()` that works (real DDL: the class wears RestoresSchemaWithoutRemigrating like every DDL test of this project).
 */
class PictureColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const FILE = 'packages/EasyCo/Catalog/database/migrations/2026_10_27_000001_add_picture_media_asset_id_to_catalog_categories_and_attribute_values.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        // whatever the test did, the project's final shape is what the next test finds
        if (! Schema::hasColumn('catalog_categories', 'picture_media_asset_id') || ! Schema::hasColumn('catalog_attribute_values', 'picture_media_asset_id')) {
            Schema::hasColumn('catalog_attribute_values', 'picture_media_asset_id') && $this->migration()->down();
            $this->migration()->up();
        }

        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    /** @return array<string, string> constraint name => delete rule */
    private function foreignKeys(): array
    {
        return collect(DB::select('select constraint_name n, delete_rule d from information_schema.referential_constraints where constraint_schema = database() and constraint_name in (?, ?)', ['cc_picture_media_fk', 'cav_picture_media_fk']))
            ->mapWithKeys(fn ($row): array => [$row->n => $row->d])->all();
    }

    public function test_the_columns_the_foreign_keys_and_the_indexes_exist_with_their_short_names(): void
    {
        foreach (['catalog_categories', 'catalog_attribute_values'] as $table) {
            $column = collect(DB::select("show columns from {$table} where Field = 'picture_media_asset_id'"))->first();

            $this->assertNotNull($column, $table);
            $this->assertSame('YES', $column->Null, 'nullable');
            $this->assertStringContainsString('bigint', strtolower($column->Type));
            $this->assertStringContainsString('unsigned', strtolower($column->Type), 'exactly like the brand\'s column');
        }

        $this->assertEquals(['cc_picture_media_fk' => 'RESTRICT', 'cav_picture_media_fk' => 'RESTRICT'], $this->foreignKeys());

        $indexes = collect(DB::select("select index_name n from information_schema.statistics where table_schema = database() and index_name in ('cc_picture_media_idx', 'cav_picture_media_idx')"))->pluck('n')->unique()->sort()->values()->all();
        $this->assertSame(['cav_picture_media_idx', 'cc_picture_media_idx'], $indexes);

        foreach (['cc_picture_media_fk', 'cc_picture_media_idx', 'cav_picture_media_fk', 'cav_picture_media_idx'] as $name) {
            $this->assertLessThanOrEqual(30, strlen($name), $name.' is short (CLAUDE.md rule 5)');
        }
    }

    public function test_the_foreign_key_refuses_to_delete_a_picture_a_category_or_a_value_shows(): void
    {
        $asset = DB::table('catalog_media')->insertGetId(['type' => 'image', 'disk' => 'public', 'path' => 'uploads/x.jpg', 'processing_status' => 'ready', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('catalog_categories')->insertGetId(['name' => 'C', 'slug' => 'c', 'picture_media_asset_id' => $asset, 'created_at' => now(), 'updated_at' => now()]);

        try {
            DB::table('catalog_media')->where('id', $asset)->delete();
            $this->fail('the database must refuse');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0], 'SQLSTATE, never the message (CLAUDE.md rule 3)');
            $this->assertSame(1451, (int) $e->errorInfo[1]);
        }

        // deleting the category only drops the reference: the picture stays and can then be deleted
        DB::table('catalog_categories')->where('id', $category)->delete();
        $this->assertSame(1, DB::table('catalog_media')->where('id', $asset)->count());
    }

    public function test_down_removes_the_foreign_keys_indexes_and_columns_and_up_brings_them_back(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('catalog_categories', 'picture_media_asset_id'));
        $this->assertFalse(Schema::hasColumn('catalog_attribute_values', 'picture_media_asset_id'));
        $this->assertSame([], $this->foreignKeys());

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('catalog_categories', 'picture_media_asset_id'));
        $this->assertTrue(Schema::hasColumn('catalog_attribute_values', 'picture_media_asset_id'));
        $this->assertEquals(['cc_picture_media_fk' => 'RESTRICT', 'cav_picture_media_fk' => 'RESTRICT'], $this->foreignKeys());
    }
}
