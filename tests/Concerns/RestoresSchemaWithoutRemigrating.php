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
    /** A table that held at most this many rows in setUp() has its rows kept, so restoreFingerprintedState() can put them back. */
    private const SNAPSHOT_ROWS = 300;

    /** @var array<string, array{create: string, rows: int, raw: string, maxId: int|null, sum: int|null, data: list<array<string, mixed>>|null}> table => fingerprint taken in setUp() */
    private array $databaseFingerprint = [];

    /** null = not checked yet, true = proven identical, false = differs. */
    private ?bool $databaseMatchedFingerprint = null;

    /** Set by restoreFingerprintedState(): the class has said it restores the database, so a verdict that still differs FAILS the test (see skipRemigrationIfClean()). */
    private bool $databaseMustMatch = false;

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
     * Puts back, for real, the two things a DDL test leaves behind that are NOT a schema change — so the verdict of
     * databaseMatchesFingerprint() can be a clean one and the next test is spared a full `migrate:fresh` (~18 s).
     * Call it in the class's own cleanup, right BEFORE databaseMatchesFingerprint(). It never touches the verdict:
     * the comparison that follows is still exact, and anything this cannot put back stays a difference.
     *
     *  1. ROWS. A DDL statement commits the test's transaction, so every row the test planted before it is real and
     *     survives the rollback. A row added since the fingerprint is deleted: all rows of a table that was empty,
     *     and for a table that held rows (the seeded ones) only those above its highest id then.
     *  1b. A table that held a few rows in setUp() (the seeded ones: settings, menus ...) whose CONTENT changed — an UPDATE
     *     a DDL committed — gets its setUp() rows put back as they were.
     *  2. COLUMN ORDER. A migration's down() then up() re-adds a column at the END of the table, while the original
     *     chain put it in the middle. The columns are moved back — MODIFY with the column's own definition line from
     *     the table's SHOW CREATE TABLE taken in setUp(), AFTER its predecessor — only when the table has exactly the
     *     same columns in another order. A table with a missing or an extra column is left alone (a real difference).
     *  3. CHECK TEXT. A constraint a later migration widened and an older migration's up() brought back in its old form
     *     gets the text it had in setUp() again — only when the same constraints exist.
     *
     * An ALTER the server rejects is skipped (the table stays as it is and the verdict stays a difference).
     */
    protected function restoreFingerprintedState(): void
    {
        $this->databaseMustMatch = true;

        $current = $this->databaseState();
        $foreignKeyChecks = DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS v');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->databaseFingerprint as $table => $expected) {
                if (! array_key_exists($table, $current)) {
                    continue;
                }

                if ($current[$table]['rows'] > $expected['rows']) {
                    if ($expected['rows'] === 0) {
                        DB::table($table)->delete();
                    } elseif ($expected['maxId'] !== null) {
                        DB::table($table)->where('id', '>', $expected['maxId'])->delete();
                    }
                }

                if ($expected['data'] !== null && $current[$table]['sum'] !== $expected['sum']) {
                    DB::table($table)->delete();

                    foreach (array_chunk($expected['data'], 100) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }

                if ($current[$table]['create'] !== $expected['create']) {
                    $this->putSchemaBack($table, $expected['raw'], $current[$table]['raw']);
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS='.((int) ($foreignKeyChecks->v ?? 1)));
        }
    }

    private function contentChecksum(string $table): ?int
    {
        $row = DB::selectOne('CHECKSUM TABLE `'.$table.'`');

        return $row === null || ! isset($row->Checksum) ? null : (int) $row->Checksum;
    }

    private function putSchemaBack(string $table, string $expectedCreate, string $currentCreate): void
    {
        $expected = $this->columnDefinitions($expectedCreate);
        $current = $this->columnDefinitions($currentCreate);

        if (array_keys($expected) !== array_keys($current) && $this->sameMembers(array_keys($expected), array_keys($current))) {
            $clauses = [];
            $previous = null;

            foreach ($expected as $column => $definition) {
                $clauses[] = 'MODIFY COLUMN `'.$column.'` '.$this->withoutTableDefaultCollation($definition, $expectedCreate).($previous === null ? ' FIRST' : ' AFTER `'.$previous.'`');
                $previous = $column;
            }

            $this->alterOrLeave($table, $clauses);
        }

        // A CHECK constraint a later migration widened, brought back by an older migration's up() in its old form: put back the
        // text it had in setUp(). Only when the same constraints exist; one added or dropped is a real difference and stays one.
        $expectedChecks = $this->checkDefinitions($expectedCreate);
        $currentChecks = $this->checkDefinitions($currentCreate);

        if ($this->sameMembers(array_keys($expectedChecks), array_keys($currentChecks))) {
            $clauses = [];

            foreach ($expectedChecks as $name => $definition) {
                if ($currentChecks[$name] !== $definition) {
                    $clauses[] = 'DROP CHECK `'.$name.'`';
                    $clauses[] = 'ADD '.$definition;
                }
            }

            $clauses === [] || $this->alterOrLeave($table, $clauses);
        }
    }

    /** A rejected ALTER leaves the table as it is: the verdict then stays a difference and the safety net (the re-migration) stays. */
    private function alterOrLeave(string $table, array $clauses): void
    {
        try {
            DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
        } catch (\Illuminate\Database\QueryException $e) {
        }
    }

    /**
     * A column typed `COLLATE x` in SHOW CREATE TABLE only because x is the table's own default collation was declared
     * WITHOUT it; saying it again in a MODIFY would store an explicit CHARACTER SET and print differently.
     */
    private function withoutTableDefaultCollation(string $definition, string $create): string
    {
        if (preg_match('/ COLLATE=(\w+)\s*$/', $create, $table) !== 1 || str_contains($definition, 'CHARACTER SET')) {
            return $definition;
        }

        return str_replace(' COLLATE '.$table[1], '', $definition);
    }

    /** @param list<string> $a @param list<string> $b */
    private function sameMembers(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Column name => its definition (the text after the name, without the trailing comma), in table order, from a
     * SHOW CREATE TABLE text.
     *
     * @return array<string, string>
     */
    private function columnDefinitions(string $create): array
    {
        $columns = [];

        foreach (explode("\n", $create) as $line) {
            if (preg_match('/^  `([^`]+)` (.+?),?$/', $line, $match) === 1) {
                $columns[$match[1]] = $match[2];
            }
        }

        return $columns;
    }

    /**
     * CHECK constraint name => its definition line ("CONSTRAINT `x` CHECK (...)"), in table order.
     *
     * @return array<string, string>
     */
    private function checkDefinitions(string $create): array
    {
        $checks = [];

        foreach (explode("\n", $create) as $line) {
            if (preg_match('/^  (CONSTRAINT `([^`]+)` CHECK .+?),?$/', $line, $match) === 1) {
                $checks[$match[2]] = $match[1];
            }
        }

        return $checks;
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
            } elseif ($current[$table]['sum'] !== $expected['sum']) {
                $differences[] = $table.': contents differ';
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

        // Run after parent::tearDown(), so a failure here cannot skip the app's own teardown. A class that calls
        // restoreFingerprintedState() promises a clean verdict; one that still differs would cost the next test a full migrate:fresh.
        if ($this->databaseMustMatch) {
            static::assertTrue($this->databaseMatchedFingerprint === true, 'the test left the database different from setUp(): '.implode('; ', $this->databaseFingerprintDifferences));
        }
    }

    /**
     * Every base table of the current database mapped to a hash of its
     * `SHOW CREATE TABLE` and its exact row count.
     *
     * @return array<string, array{create: string, rows: int, raw: string, maxId: int|null, sum: int|null, data: list<array<string, mixed>>|null}>
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

            $raw = $this->normalizeCreateTable((string) ($create[0]->{'Create Table'} ?? ''));

            $state[$table] = [
                'create' => hash('sha256', $raw),
                'rows' => $counts[$index],
                // Not part of the verdict: what restoreFingerprintedState() needs to put the table back (see there).
                'raw' => $raw,
                'maxId' => $counts[$index] > 0 && str_contains($raw, "\n  `id` ") ? (int) DB::table($table)->max('id') : null,
                // The CONTENT of a table that holds rows (the few seeded ones): a count alone misses an UPDATE that a DDL committed.
                'sum' => $counts[$index] > 0 ? $this->contentChecksum($table) : null,
                'data' => $counts[$index] > 0 && $counts[$index] <= self::SNAPSHOT_ROWS ? DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all() : null,
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
        $lines = explode("\n", (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $create));

        // The ORDER in which SHOW CREATE TABLE lists the (non-primary) keys is the order they were created in: a migration's
        // down() then up() re-creates a key last, and no query, constraint or later test can tell where in the list a key
        // stands. The keys themselves — name, columns, uniqueness — stay in the text, so a missing, extra or changed key is
        // still a difference.
        $slots = array_keys(array_filter($lines, static fn (string $line): bool => preg_match('/^  (?:UNIQUE |FULLTEXT |SPATIAL )?KEY `/', $line) === 1));
        $keys = array_map(static fn (int $slot): string => rtrim($lines[$slot], ','), $slots);
        sort($keys);

        foreach ($slots as $position => $slot) {
            $lines[$slot] = $keys[$position].($position === count($slots) - 1 ? '' : ',');
        }

        return implode("\n", $lines);
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
