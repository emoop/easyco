<?php

namespace Tests\Feature\Sandbox;

use RuntimeException;
use Tests\TestCase;

/**
 * The database-pinning rule behind SandboxRoutesProductionTest — i.e.
 * SandboxTestEnvironment::configuredTestDatabase(), the mechanism that keeps
 * the ONE test running in a production application off the dev database.
 *
 * WHY THIS RULE GETS ITS OWN TESTS: a silent regression here does not fail a
 * page — it points RefreshDatabase at the database holding the real products.
 * The rule is therefore asserted directly, including the case that motivated
 * dropping .env from its sources: a directory containing a perfectly valid
 * .env that names a database, and no .env.testing at all, must THROW rather
 * than fall back to it.
 *
 * NO RefreshDatabase AND NO SANDBOX FLAG: this class reads files and the
 * process environment only; it queries nothing, so it needs neither. Every
 * env-file assertion uses TEMP FILES (sys_get_temp_dir(), removed in
 * tearDown()) — the worktree's real .env/.env.testing are read only by the
 * one test asserting that this run resolves to the database it is actually
 * using, and even that test never writes to them.
 *
 * THE PROCESS ENVIRONMENT IS MUTATED BY TWO OF THESE TESTS, and always
 * restored in a finally: during a real PHPUnit run Dotenv has already put
 * DB_DATABASE into $_ENV/$_SERVER (from .env.testing), so the
 * process-environment branch would otherwise always win and neither the file
 * branch nor the throwing branch would be reachable at all. The application
 * is already booted by then, so nothing re-reads its configuration — and the
 * restore is what keeps every later test in the suite unaffected.
 */
final class SandboxTestEnvironmentTest extends TestCase
{
    /** @var string[] */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->tempDirectories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            // scandir(), not glob(): the files these tests write are
            // DOT-files (.env, .env.testing), and glob('*') does not match a
            // leading dot — which is exactly how this cleanup first failed.
            foreach (scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                unlink($directory.DIRECTORY_SEPARATOR.$entry);
            }

            rmdir($directory);
        }
    }

    /** A throwaway directory for env files — never the project's own. */
    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'easyco-sandbox-env-'.bin2hex(random_bytes(6));

        mkdir($directory, 0700, true);

        $this->tempDirectories[] = $directory;

        return $directory;
    }

    /**
     * Runs $callback with the process environment's DB_DATABASE REMOVED, so
     * the file branch is reachable — restored in a finally either way.
     */
    private function withoutProcessDatabase(callable $callback): mixed
    {
        $saved = [
            'server' => $_SERVER['DB_DATABASE'] ?? null,
            'env' => $_ENV['DB_DATABASE'] ?? null,
            'putenv' => getenv('DB_DATABASE'),
        ];

        unset($_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']);
        putenv('DB_DATABASE');

        try {
            return $callback();
        } finally {
            if ($saved['server'] !== null) {
                $_SERVER['DB_DATABASE'] = $saved['server'];
            }

            if ($saved['env'] !== null) {
                $_ENV['DB_DATABASE'] = $saved['env'];
            }

            $saved['putenv'] === false
                ? putenv('DB_DATABASE')
                : putenv("DB_DATABASE={$saved['putenv']}");
        }
    }

    /**
     * The property that actually matters: whatever this run was configured
     * with (process environment or .env.testing — the method's only two
     * sources) is what SandboxRoutesProductionTest will pin. If this ever
     * diverges, the production-environment test is running somewhere other
     * than where the rest of the suite runs.
     */
    public function test_the_rule_resolves_to_the_database_this_run_is_actually_using(): void
    {
        $this->assertSame(
            config('database.connections.mysql.database'),
            SandboxTestEnvironment::configuredTestDatabase()
        );
    }

    /**
     * D1 of this fix: a valid .env sitting NEXT TO the given .env.testing is
     * never consulted, even though Laravel itself would fall back to it.
     */
    public function test_it_reads_the_given_testing_env_file_and_ignores_a_sibling_dev_env_file(): void
    {
        $directory = $this->tempDirectory();

        file_put_contents($directory.DIRECTORY_SEPARATOR.'.env', "DB_DATABASE=dev_database_that_must_never_be_used\n");
        file_put_contents($directory.DIRECTORY_SEPARATOR.'.env.testing', "DB_DATABASE=testing_database\n");

        $resolved = $this->withoutProcessDatabase(
            fn (): string => SandboxTestEnvironment::configuredTestDatabase($directory.DIRECTORY_SEPARATOR.'.env.testing')
        );

        $this->assertSame('testing_database', $resolved);
    }

    /** Source order: a real process-environment value is immutable-wins, exactly as Laravel's Env treats it. */
    public function test_a_process_environment_value_wins_over_the_file(): void
    {
        $directory = $this->tempDirectory();

        file_put_contents($directory.DIRECTORY_SEPARATOR.'.env.testing', "DB_DATABASE=testing_database\n");

        $saved = [
            'server' => $_SERVER['DB_DATABASE'] ?? null,
            'env' => $_ENV['DB_DATABASE'] ?? null,
            'putenv' => getenv('DB_DATABASE'),
        ];

        putenv('DB_DATABASE=from_the_process_environment');
        $_ENV['DB_DATABASE'] = 'from_the_process_environment';
        // $_SERVER TOO, and it is the one that actually decides: the rule
        // checks $_SERVER, then $_ENV, then getenv() — the same order Env's
        // own repository uses, where Dotenv writes all three.
        $_SERVER['DB_DATABASE'] = 'from_the_process_environment';

        try {
            $this->assertSame(
                'from_the_process_environment',
                SandboxTestEnvironment::configuredTestDatabase($directory.DIRECTORY_SEPARATOR.'.env.testing')
            );
        } finally {
            $saved['server'] === null ? null : $_SERVER['DB_DATABASE'] = $saved['server'];
            $saved['env'] === null ? null : $_ENV['DB_DATABASE'] = $saved['env'];
            $saved['putenv'] === false
                ? putenv('DB_DATABASE')
                : putenv("DB_DATABASE={$saved['putenv']}");
        }
    }

    /**
     * THE THROWING CASE, and the reason .env is not a source: a database
     * named by .env (the dev database, in a real checkout) and nothing else
     * must be a hard failure, never a fallback.
     */
    public function test_it_throws_when_no_source_names_a_database(): void
    {
        $directory = $this->tempDirectory();

        file_put_contents($directory.DIRECTORY_SEPARATOR.'.env', "DB_DATABASE=dev_database_that_must_never_be_used\n");

        $this->withoutProcessDatabase(function () use ($directory): void {
            try {
                SandboxTestEnvironment::configuredTestDatabase($directory.DIRECTORY_SEPARATOR.'.env.testing');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Refusing to fall back', $exception->getMessage());
                $this->assertStringContainsString('.env.testing declares none', $exception->getMessage());

                return;
            }

            $this->fail('expected a RuntimeException: no source named a database, yet the rule resolved one');
        });
    }

    // --- force / restore must give DB_DATABASE back exactly as it was -------------------------------------------------

    /** @return array{putenv: string|false, env: array{0: bool, 1: mixed}, server: array{0: bool, 1: mixed}, app: string|false} */
    private function snapshotEnvironment(): array
    {
        return [
            'putenv' => getenv('DB_DATABASE'),
            'env' => [array_key_exists('DB_DATABASE', $_ENV), $_ENV['DB_DATABASE'] ?? null],
            'server' => [array_key_exists('DB_DATABASE', $_SERVER), $_SERVER['DB_DATABASE'] ?? null],
            'app' => getenv('APP_ENV'),
        ];
    }

    /** @param array{putenv: string|false, env: array{0: bool, 1: mixed}, server: array{0: bool, 1: mixed}, app: string|false} $saved */
    private function restoreEnvironment(array $saved): void
    {
        $saved['putenv'] === false ? putenv('DB_DATABASE') : putenv('DB_DATABASE='.$saved['putenv']);

        if ($saved['env'][0]) {
            $_ENV['DB_DATABASE'] = $saved['env'][1];
        } else {
            unset($_ENV['DB_DATABASE']);
        }

        if ($saved['server'][0]) {
            $_SERVER['DB_DATABASE'] = $saved['server'][1];
        } else {
            unset($_SERVER['DB_DATABASE']);
        }

        $saved['app'] === false ? putenv('APP_ENV') : putenv('APP_ENV='.$saved['app']);
    }

    /**
     * THE LEAK THIS PINS: restore used to FORGET DB_DATABASE, so a process-level private test database (exported for the run)
     * was lost and every later test class fell back to .env.testing, the shared test database. A value set before the force
     * must be exactly what is there after the restore, in all three sources — even when something changed it in between.
     */
    public function test_force_then_restore_leaves_a_process_database_exactly_as_it_was(): void
    {
        $saved = $this->snapshotEnvironment();

        putenv('DB_DATABASE=private_test_database');
        $_ENV['DB_DATABASE'] = 'private_test_database';
        $_SERVER['DB_DATABASE'] = 'private_test_database';

        try {
            SandboxTestEnvironment::forceProductionEnvironment();

            // The production-environment app is built with the pinned value; whatever it left behind must not survive the restore.
            putenv('DB_DATABASE=changed_in_between');
            $_ENV['DB_DATABASE'] = 'changed_in_between';
            $_SERVER['DB_DATABASE'] = 'changed_in_between';

            SandboxTestEnvironment::restoreProductionEnvironment();

            $this->assertSame('private_test_database', getenv('DB_DATABASE'));
            $this->assertSame('private_test_database', $_ENV['DB_DATABASE']);
            $this->assertSame('private_test_database', $_SERVER['DB_DATABASE']);
            $this->assertSame('testing', getenv('APP_ENV'));
        } finally {
            SandboxTestEnvironment::restoreProductionEnvironment();
            $this->restoreEnvironment($saved);
        }
    }

    public function test_force_then_restore_leaves_an_absent_database_absent(): void
    {
        $saved = $this->snapshotEnvironment();

        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);

        try {
            // With no process value the pin comes from .env.testing, and force sets it for the production application.
            SandboxTestEnvironment::forceProductionEnvironment();
            $this->assertNotFalse(getenv('DB_DATABASE'), 'force pins a database');

            SandboxTestEnvironment::restoreProductionEnvironment();

            $this->assertFalse(getenv('DB_DATABASE'));
            $this->assertArrayNotHasKey('DB_DATABASE', $_ENV);
            $this->assertArrayNotHasKey('DB_DATABASE', $_SERVER);
        } finally {
            SandboxTestEnvironment::restoreProductionEnvironment();
            $this->restoreEnvironment($saved);
        }
    }

    public function test_a_second_force_without_a_restore_does_not_lose_the_original(): void
    {
        $saved = $this->snapshotEnvironment();

        putenv('DB_DATABASE=private_test_database');
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'private_test_database';

        try {
            SandboxTestEnvironment::forceProductionEnvironment();
            putenv('DB_DATABASE=something_else');
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'something_else';
            SandboxTestEnvironment::forceProductionEnvironment();
            SandboxTestEnvironment::restoreProductionEnvironment();

            $this->assertSame('private_test_database', getenv('DB_DATABASE'), 'the original, not the value pinned by the first force');
        } finally {
            SandboxTestEnvironment::restoreProductionEnvironment();
            $this->restoreEnvironment($saved);
        }
    }

    /** The same refusal for the other half of "no source": a file that exists but names no database. */
    public function test_it_throws_when_the_given_file_names_no_database(): void
    {
        $directory = $this->tempDirectory();

        file_put_contents($directory.DIRECTORY_SEPARATOR.'.env.testing', "DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\n");

        $this->withoutProcessDatabase(function () use ($directory): void {
            try {
                SandboxTestEnvironment::configuredTestDatabase($directory.DIRECTORY_SEPARATOR.'.env.testing');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('DB_DATABASE is absent', $exception->getMessage());

                return;
            }

            $this->fail('expected a RuntimeException: the given testing env file declares no DB_DATABASE');
        });
    }
}
