<?php

namespace Tests\Feature\Cookies;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CC-1: the migration that adds the menu item kind `consent` to `menu_items_type_check`: it replaces an OLD CHECK (the five final kinds), is idempotent, and adds the CHECK when it is missing.
 * MySQL DDL commits by itself, so this test touches nothing but the CHECK (no row is written) and leaves the table with the new CHECK, as the migration does.
 */
class ConsentKindMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = 'database/migrations/2026_11_23_000003_add_consent_kind_to_menu_items_type_check.php';

    private function clause(): ?string
    {
        $row = DB::table('information_schema.check_constraints')
            ->where('constraint_schema', DB::getDatabaseName())->where('constraint_name', 'menu_items_type_check')->selectRaw('check_clause as clause')->first();

        return $row === null ? null : (string) $row->clause;
    }

    private function up(): void
    {
        (require base_path(self::FILE))->up();
    }

    /** ★ an old CHECK is replaced; a second run changes nothing; a missing CHECK is added */
    public function test_up_replaces_the_old_check_is_idempotent_and_adds_a_missing_one(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The CHECK is MySQL / MariaDB only.');
        }

        try {
            // the database as it was before this package: the five final kinds
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
            DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','column'))");
            $this->assertStringNotContainsString('consent', (string) $this->clause());

            $this->up();
            $replaced = (string) $this->clause();
            foreach (['link', 'category', 'page', 'product', 'column', 'consent'] as $kind) {
                $this->assertStringContainsString($kind, $replaced, $kind);
            }

            $this->up();
            $this->assertSame($replaced, $this->clause(), 'a second run changes nothing');

            // no CHECK at all (a half-run migration): it is added
            DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
            $this->assertNull($this->clause());
            $this->up();
            $this->assertSame($replaced, $this->clause());
        } finally {
            // whatever happened, leave the table as the migrations leave it
            if ($this->clause() === null || ! str_contains((string) $this->clause(), 'consent')) {
                $this->clause() === null || DB::statement('ALTER TABLE menu_items DROP CHECK menu_items_type_check');
                DB::statement("ALTER TABLE menu_items ADD CONSTRAINT menu_items_type_check CHECK (type IN ('link','category','page','product','column','consent'))");
            }
        }
    }
}
