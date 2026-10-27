<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * TS-2: the fingerprint of Tests\Concerns\RestoresSchemaWithoutRemigrating must NEVER call a really different database
 * clean — that is what keeps the re-migration safety net honest — while restoreFingerprintedState() may put back only
 * what is NOT a schema decision (rows a DDL committed, the order of columns, the text of a CHECK a later migration
 * widened) and the order in which SHOW CREATE TABLE lists the keys is not a difference at all.
 *
 * Every test changes ONE real table of the test database (`jobs`, `sessions`, `site_settings`, `payment_refunds` — none
 * of them is referenced by a foreign key from a table the tests use), asserts the verdict, and puts the table back by
 * hand in a `finally`. The class wears the trait the way a DDL test class is meant to, so a table that was NOT put
 * back would fail the class's own check and cost the next test a migrate:fresh.
 */
class RestoresSchemaFingerprintStrictnessTest extends TestCase
{
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        $this->restoreFingerprintedState();
        $this->databaseMatchesFingerprint();

        parent::tearDown();

        $this->skipRemigrationIfClean();
    }

    private function differences(): string
    {
        $this->assertFalse($this->databaseMatchesFingerprint());

        return implode('; ', $this->databaseFingerprintDifferences);
    }

    /** Commits the open transaction with a DDL that changes nothing, so what the test writes next is real, as after a migration. */
    private function commitLikeADdlTest(): void
    {
        DB::statement('ALTER TABLE `jobs` ENGINE=InnoDB');
    }

    // ------------------------------------------------------------------ a real difference is still a difference

    public function test_a_changed_column_type_is_a_difference(): void
    {
        DB::statement('ALTER TABLE `jobs` MODIFY `attempts` int unsigned NOT NULL');

        try {
            $this->assertStringContainsString('jobs: schema differs', $this->differences());
        } finally {
            DB::statement('ALTER TABLE `jobs` MODIFY `attempts` smallint unsigned NOT NULL');
        }
    }

    public function test_a_changed_default_is_a_difference(): void
    {
        DB::statement('ALTER TABLE `jobs` ALTER COLUMN `available_at` SET DEFAULT 5');

        try {
            $this->assertStringContainsString('jobs: schema differs', $this->differences());
        } finally {
            DB::statement('ALTER TABLE `jobs` ALTER COLUMN `available_at` DROP DEFAULT');
        }
    }

    public function test_a_dropped_index_is_a_difference(): void
    {
        DB::statement('ALTER TABLE `jobs` DROP INDEX `jobs_queue_index`');

        try {
            $this->assertStringContainsString('jobs: schema differs', $this->differences());
        } finally {
            DB::statement('ALTER TABLE `jobs` ADD INDEX `jobs_queue_index` (`queue`)');
        }
    }

    public function test_a_dropped_table_is_a_difference(): void
    {
        // the columns' own COLLATE is the table's default one: said again in a CREATE it would be printed with an explicit CHARACTER SET
        $create = str_replace(' COLLATE utf8mb4_unicode_ci', '', (string) DB::selectOne('SHOW CREATE TABLE `jobs`')->{'Create Table'});
        DB::statement('DROP TABLE `jobs`');

        try {
            $this->assertStringContainsString('jobs: table missing', $this->differences());
        } finally {
            DB::statement($create);
        }
    }

    public function test_an_extra_column_is_a_difference_and_the_restore_does_not_hide_it(): void
    {
        DB::statement('ALTER TABLE `jobs` ADD COLUMN `ts2_extra` int NULL');

        try {
            $this->restoreFingerprintedState();

            $this->assertStringContainsString('jobs: schema differs', $this->differences());
        } finally {
            DB::statement('ALTER TABLE `jobs` DROP COLUMN `ts2_extra`');
        }
    }

    public function test_a_changed_check_that_cannot_be_put_back_stays_a_difference(): void
    {
        DB::statement('ALTER TABLE `payment_refunds` ADD CONSTRAINT `ts2_extra_check` CHECK (`amount_minor` >= 0)');

        try {
            // a constraint that did not exist before is not "the same constraints with another text": left alone
            $this->restoreFingerprintedState();

            $this->assertStringContainsString('payment_refunds: schema differs', $this->differences());
        } finally {
            DB::statement('ALTER TABLE `payment_refunds` DROP CHECK `ts2_extra_check`');
        }
    }

    // ------------------------------------------------------------------ what is NOT a difference

    public function test_the_order_in_which_the_keys_are_listed_is_not_a_difference(): void
    {
        // `sessions` has two keys; dropping the first and creating it again (two statements: in ONE the key keeps its place) lists it last
        DB::statement('ALTER TABLE `sessions` DROP INDEX `sessions_user_id_index`');
        DB::statement('ALTER TABLE `sessions` ADD INDEX `sessions_user_id_index` (`user_id`)');

        $this->assertTrue($this->databaseMatchesFingerprint(), implode('; ', $this->databaseFingerprintDifferences));
    }

    // ------------------------------------------------------------------ what the restore puts back, and only that

    public function test_rows_a_ddl_committed_are_deleted_by_the_restore(): void
    {
        $this->commitLikeADdlTest();
        DB::table('jobs')->insert(['queue' => 'ts2', 'payload' => '{}', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);

        try {
            $this->assertStringContainsString('jobs: 0 rows -> 1 rows', $this->differences());

            $this->restoreFingerprintedState();

            $this->assertTrue($this->databaseMatchesFingerprint(), implode('; ', $this->databaseFingerprintDifferences));
        } finally {
            DB::table('jobs')->delete();
        }
    }

    public function test_a_changed_value_in_a_table_that_held_rows_is_a_difference_and_the_restore_puts_it_back(): void
    {
        $this->commitLikeADdlTest();
        $before = DB::table('site_settings')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        DB::table('site_settings')->where('id', $before[0]['id'])->update(['value' => 'ts2-changed']);

        try {
            $this->assertStringContainsString('site_settings: contents differ', $this->differences());

            $this->restoreFingerprintedState();

            $this->assertTrue($this->databaseMatchesFingerprint(), implode('; ', $this->databaseFingerprintDifferences));
            $this->assertSame($before, DB::table('site_settings')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        } finally {
            DB::table('site_settings')->where('id', $before[0]['id'])->update(['value' => $before[0]['value']]);
        }
    }

    public function test_the_columns_moved_to_the_end_are_put_back_in_their_place(): void
    {
        DB::statement('ALTER TABLE `jobs` MODIFY `queue` varchar(255) NOT NULL AFTER `created_at`');

        try {
            $this->assertStringContainsString('jobs: schema differs', $this->differences());

            $this->restoreFingerprintedState();

            $this->assertTrue($this->databaseMatchesFingerprint(), implode('; ', $this->databaseFingerprintDifferences));
        } finally {
            DB::statement('ALTER TABLE `jobs` MODIFY `queue` varchar(255) NOT NULL AFTER `id`');
        }
    }

    public function test_a_check_in_its_old_text_is_put_back_in_the_text_it_had(): void
    {
        $original = (string) DB::selectOne('SHOW CREATE TABLE `payment_refunds`')->{'Create Table'};
        preg_match('/CONSTRAINT `pay_refunds_deduction_check` CHECK .+?(?=,\n|\n\))/', $original, $match);
        $this->assertNotEmpty($match, 'the table is expected to carry pay_refunds_deduction_check');

        DB::statement('ALTER TABLE `payment_refunds` DROP CHECK `pay_refunds_deduction_check`');
        DB::statement('ALTER TABLE `payment_refunds` ADD CONSTRAINT `pay_refunds_deduction_check` CHECK (`deduction_minor` >= 0)');

        try {
            $this->assertStringContainsString('payment_refunds: schema differs', $this->differences());

            $this->restoreFingerprintedState();

            $this->assertTrue($this->databaseMatchesFingerprint(), implode('; ', $this->databaseFingerprintDifferences));
        } finally {
            DB::statement('ALTER TABLE `payment_refunds` DROP CHECK `pay_refunds_deduction_check`');
            DB::statement('ALTER TABLE `payment_refunds` ADD '.$match[0]);
        }
    }
}
