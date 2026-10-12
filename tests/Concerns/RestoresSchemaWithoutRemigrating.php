<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;

/**
 * Lets a test class that must run REAL DDL (running a migration's up()/down()
 * directly, on the test database) keep RefreshDatabase's safety net without
 * making the NEXT test pay for a full `migrate:fresh`.
 *
 * Why that happens: MySQL/MariaDB DDL commits implicitly, so after the test's
 * first ALTER/CREATE `PDO::inTransaction()` is false; at teardown
 * RefreshDatabase sees that and sets `RefreshDatabaseState::$migrated = false`
 * (vendor/laravel/framework/src/Illuminate/Foundation/Testing/RefreshDatabase.php:158),
 * which makes the following test re-migrate the whole database (~11-13 s here).
 * That re-migration is an accidental safety net: it repairs any schema change
 * or row the DDL test leaked.
 *
 * This trait KEEPS that safety net and only skips the re-migration when the
 * database is PROVEN identical to how it was before the test. It fingerprints
 * every table in setUp (a hash of `SHOW CREATE TABLE` plus the exact `COUNT(*)`)
 * and re-checks the fingerprint at the END of the class's own cleanup, before
 * parent::tearDown(). Only a clean verdict re-arms RefreshDatabaseState::$migrated
 * (after parent::tearDown(), where RefreshDatabase's app-destroy callback runs);
 * a mismatch leaves the flag false, so the next test self-heals with
 * `migrate:fresh` exactly as today.
 *
 * Usage in a DDL test class:
 *
 *     use RestoresSchemaWithoutRemigrating;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->captureDatabaseFingerprint();
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         // ... the class's own schema/row cleanup ...
 *         $this->databaseMatchesFingerprint();
 *         parent::tearDown();
 *         $this->skipRemigrationIfClean();
 *     }
 */
trait RestoresSchemaWithoutRemigrating
{
    /** @var array<string, array{create: string, rows: int}> table => fingerprint taken in setUp() */
    private array $databaseFingerprint = [];

    /** null = not checked yet, true = proven identical, false = differs. */
    private ?bool $databaseMatchedFingerprint = null;

    /** @var list<string> the tables (and why) whose fingerprint differed — for the report. */
    private array $databaseFingerprintDifferences = [];

    /** Fingerprint every table in the current database. Call right after parent::setUp(). */
    protected function captureDatabaseFingerprint(): void
    {
        $this->databaseFingerprint = $this->databaseState();
        $this->databaseMatchedFingerprint = null;
        $this->databaseFingerprintDifferences = [];
    }

    /**
     * Recompute the fingerprint and compare it with the one taken in setUp().
     * Call at the END of the class's own cleanup, before parent::tearDown().
     */
    protected function databaseMatchesFingerprint(): bool
    {
        $current = $this->databaseState();
        $differences = [];

        foreach ($this->databaseFingerprint as $table => $expected) {
            if (! array_key_exists($table, $current)) {
                $differences[] = $table.': table missing';
                continue;
            }

            if ($current[$table]['create'] !== $expected['create']) {
                $differences[] = $table.': schema differs';
            }

            if ($current[$table]['rows'] !== $expected['rows']) {
                $differences[] = sprintf('%s: %d rows -> %d rows', $table, $expected['rows'], $current[$table]['rows']);
            }
        }

        foreach ($current as $table => $unused) {
            if (! array_key_exists($table, $this->databaseFingerprint)) {
                $differences[] = $table.': table was not there before';
            }
        }

        $this->databaseFingerprintDifferences = $differences;
        $this->databaseMatchedFingerprint = $differences === [];

        return $this->databaseMatchedFingerprint;
    }

    /**
     * Re-arm RefreshDatabase's "already migrated" flag only when the database was
     * proven identical to before the test. Call AFTER parent::tearDown() has
     * returned (RefreshDatabase's app-destroy callback runs inside it and would
     * otherwise set the flag false for a DDL test). Left as-is on a mismatch, so
     * the next test still self-heals with `migrate:fresh`.
     */
    protected function skipRemigrationIfClean(): void
    {
        if ($this->databaseMatchedFingerprint === true) {
            RefreshDatabaseState::$migrated = true;
        }
    }

    /**
     * Every base table of the current database mapped to a hash of its
     * `SHOW CREATE TABLE` and its exact row count.
     *
     * @return array<string, array{create: string, rows: int}>
     */
    private function databaseState(): array
    {
        $tables = $this->databaseTableNames();

        if ($tables === []) {
            return [];
        }

        // One round trip for every table's exact count, rather than one query per table.
        $union = implode(' UNION ALL ', array_map(
            static fn (string $table): string => 'SELECT COUNT(*) AS n FROM `'.$table.'`',
            $tables,
        ));
        $counts = array_map(static fn (object $row): int => (int) $row->n, DB::select($union));

        $state = [];
        foreach ($tables as $index => $table) {
            $create = DB::select('SHOW CREATE TABLE `'.$table.'`');

            $state[$table] = [
                'create' => hash('sha256', $this->normalizeCreateTable((string) ($create[0]->{'Create Table'} ?? ''))),
                'rows' => $counts[$index],
            ];
        }

        return $state;
    }

    /**
     * MySQL renders the table's current AUTO_INCREMENT counter inside SHOW CREATE TABLE
     * (e.g. `... ENGINE=InnoDB AUTO_INCREMENT=7 ...`). That counter advances on every insert
     * — including one a test rolls back, since InnoDB does not roll back the counter — and is
     * reset only by a migrate:fresh, so it differs between setUp() and teardown for any test
     * that inserts a row. It is a counter, not table structure, so it is stripped before
     * hashing: the exact COUNT(*) below is what proves no row was leaked, and the remaining
     * DDL text still proves no column/index/constraint/CHECK was left behind.
     */
    private function normalizeCreateTable(string $create): string
    {
        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
    }

    /** @return list<string> */
    private function databaseTableNames(): array
    {
        $rows = DB::select('SHOW TABLES');
        $key = 'Tables_in_'.DB::getDatabaseName();

        return array_values(array_map(
            static fn (object $table): string => (string) ($table->{$key} ?? array_values((array) $table)[0]),
            $rows,
        ));
    }
}
