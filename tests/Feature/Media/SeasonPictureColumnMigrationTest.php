<?php

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * CT-1.1: the migration of `catalog_seasons.picture_media_asset_id` — the nullable column, the index and the RESTRICT foreign key with their explicit short names (not shared with the category's or the
 * attribute value's), and a `down()` that works (real DDL: RestoresSchemaWithoutRemigrating, like every DDL test of this project).
 */
class SeasonPictureColumnMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    private const FILE = 'packages/EasyCo/Catalog/database/migrations/2026_10_27_000002_add_picture_media_asset_id_to_catalog_seasons_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        Schema::hasColumn('catalog_seasons', 'picture_media_asset_id') || $this->migration()->up();

        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function migration(): object
    {
        return require base_path(self::FILE);
    }

    private function foreignKey(): ?string
    {
        return DB::selectOne('select delete_rule d from information_schema.referential_constraints where constraint_schema = database() and constraint_name = ?', ['cs_picture_media_fk'])?->d;
    }

    public function test_the_column_the_foreign_key_and_the_index_exist_with_their_short_names(): void
    {
        $column = collect(DB::select("show columns from catalog_seasons where Field = 'picture_media_asset_id'"))->first();

        $this->assertNotNull($column);
        $this->assertSame('YES', $column->Null, 'nullable');
        $this->assertStringContainsString('bigint', strtolower($column->Type));
        $this->assertStringContainsString('unsigned', strtolower($column->Type));
        $this->assertSame('RESTRICT', $this->foreignKey());
        $this->assertSame(1, DB::selectOne("select count(distinct index_name) c from information_schema.statistics where table_schema = database() and index_name = 'cs_picture_media_idx'")->c);

        foreach (['cs_picture_media_fk', 'cs_picture_media_idx'] as $name) {
            $this->assertLessThanOrEqual(30, strlen($name), $name.' is short (CLAUDE.md rule 5)');
        }

        $others = DB::select("select constraint_name n from information_schema.referential_constraints where constraint_schema = database() and constraint_name in ('cc_picture_media_fk', 'cav_picture_media_fk')");
        $this->assertCount(2, $others, 'the names are not shared with the category\'s and the value\'s');
    }

    public function test_down_removes_the_foreign_key_the_index_and_the_column_and_up_brings_them_back(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('catalog_seasons', 'picture_media_asset_id'));
        $this->assertNull($this->foreignKey());

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('catalog_seasons', 'picture_media_asset_id'));
        $this->assertSame('RESTRICT', $this->foreignKey());
    }
}
