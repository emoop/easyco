<?php

namespace Tests\Feature\Sandbox;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Test-only plumbing for the sandbox's BOOT-TIME flag — prompt D, D1.
 *
 * WHY THIS EXISTS AT ALL: the sandbox's routes are registered once, when
 * the application boots (App\Providers\SandboxServiceProvider::boot()),
 * from config('sandbox.enabled') and app()->isProduction(). A test that
 * calls config(['sandbox.enabled' => true]) AFTER setUp() is therefore
 * always too late to prove anything about the real boot path — the routes
 * either exist already or never will. Setting the real environment
 * variable BEFORE the application is built (Laravel's Dotenv is
 * immutable, so a real process-environment value wins over .env/.env.testing)
 * is what makes these tests exercise the same code path a real deployment
 * does, rather than a hand-crafted route registration.
 *
 * THE PRODUCTION CASE NEEDS MORE THAN APP_ENV, AND THE EXTRA LINE IS
 * LOAD-BEARING: with APP_ENV=production the framework stops loading
 * .env.testing (it looks for .env.production, does not find it, and falls
 * back to .env — which points at the DEV database, the one holding the
 * real products). The database the test run was going to use is therefore
 * read out (configuredTestDatabase(), while .env.testing is still the file
 * in play) and pinned into DB_DATABASE before the container is built, so
 * that even a mistake in a production-environment test cannot reach
 * development data. The class that uses it
 * (SandboxRoutesProductionTest) runs no migrations and queries nothing —
 * this is belt and braces on top of that.
 *
 * THE PINNED NAME IS NEVER HARDCODED, deliberately: whatever .env.testing
 * (or a real process-level DB_DATABASE — Dotenv is immutable, so that one
 * wins) says the test database is, is what gets pinned. That is what makes
 * this class work unchanged in another worktree or after this branch is
 * merged into main, where the same file points at a differently named test
 * database. If the name cannot be determined at all, this class REFUSES to
 * guess and throws — a silent fallback to .env's dev database is exactly
 * the failure this whole mechanism exists to prevent.
 *
 * RESTORE IS MANDATORY, NOT TIDINESS: these methods mutate the PHP
 * process's own environment, which survives long after one test's
 * application is torn down and would otherwise leak into every later test
 * in the same run. Every caller restores in tearDown().
 *
 * NOT A TestCase SUBCLASS AND NOT NAMED *Test, deliberately: this is
 * infrastructure, not a test. (PHPUnit's directory suite only collects
 * *Test.php files and TestCase subclasses, so this file is loaded through
 * composer's autoload-dev mapping like any other class.)
 */
final class SandboxTestEnvironment
{
    /**
     * The env file a PHPUnit run loads — Laravel picks it because
     * phpunit.xml puts APP_ENV=testing into the process environment (see
     * Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables).
     */
    private const TEST_ENVIRONMENT_FILE = '.env.testing';

    public static function enableSandboxFlag(): void
    {
        self::set('EASYCO_SANDBOX', 'true');
    }

    public static function restoreSandboxFlag(): void
    {
        self::forget('EASYCO_SANDBOX');
    }

    public static function forceProductionEnvironment(): void
    {
        // Read BEFORE APP_ENV changes: with APP_ENV=production Laravel looks
        // for .env.production, does not find it, and falls back to .env —
        // the dev database. Capturing the intended test database first is
        // what keeps this test off it.
        $testDatabase = self::configuredTestDatabase();

        self::set('APP_ENV', 'production');
        self::set('DB_DATABASE', $testDatabase);
    }

    /**
     * APP_ENV goes back to 'testing' (the value phpunit.xml itself sets),
     * and DB_DATABASE is forgotten so .env.testing supplies it again —
     * exactly the state every other test class in the suite expects.
     */
    public static function restoreProductionEnvironment(): void
    {
        self::forget('DB_DATABASE');
        self::set('APP_ENV', 'testing');
    }

    /**
     * The database this test run was configured to use — i.e. the value
     * DB_DATABASE would have had if APP_ENV had stayed 'testing'.
     *
     * Read from the environment, never from config()/app(): this runs
     * before the container is built (the caller invokes it from
     * createApplication(), before parent::createApplication()), so there is
     * no config repository to ask yet — and asking one would be circular,
     * since this is what decides what the application gets built with.
     *
     * SOURCE ORDER, matching what Laravel itself would do:
     * 1. a real process-environment DB_DATABASE (phpunit.xml's <env> block,
     *    a CI variable, an exported shell value) — Env's repository is
     *    immutable, so a process value beats any file;
     * 2. .env.testing — the file Laravel loads when APP_ENV=testing, i.e.
     *    what every other test in this suite runs against.
     * Parsed with Dotenv::parse(), the same parser Laravel uses, rather than
     * a hand-rolled regex over the file.
     *
     * .env IS DELIBERATELY NOT A SOURCE, and its absence from the list above
     * is the point of this method rather than an oversight: .env is the file
     * Laravel falls back to precisely when .env.testing is absent — i.e. the
     * dev-database case this whole pin exists to make impossible. Reading it
     * here would reinstate the failure mode the pin prevents: a
     * production-environment test quietly pointed at the database holding the
     * real products.
     *
     * $envTestingPath exists so the rule above can be TESTED without touching
     * any real env file: a test writes its own .env.testing (plus a tempting
     * sibling .env the method must ignore) into a temp directory and passes
     * the path — see SandboxTestEnvironmentTest, including the throwing case.
     *
     * Throws when neither source names a database: refusing to guess is the
     * only safe option here, because an unpinned run falls back to .env — the
     * dev database.
     */
    public static function configuredTestDatabase(?string $envTestingPath = null): string
    {
        $fromProcess = self::fromProcessEnvironment('DB_DATABASE');

        if ($fromProcess !== null) {
            return $fromProcess;
        }

        // dirname(__DIR__, 3) is the project root (this file lives at
        // tests/Feature/Sandbox/); base_path() is not usable yet — no
        // application has been booted at this point.
        $path = $envTestingPath ?? \dirname(__DIR__, 3).DIRECTORY_SEPARATOR.self::TEST_ENVIRONMENT_FILE;

        if (is_file($path)) {
            $values = Dotenv::parse((string) file_get_contents($path));

            if (isset($values['DB_DATABASE']) && $values['DB_DATABASE'] !== '') {
                return (string) $values['DB_DATABASE'];
            }
        }

        throw new RuntimeException(
            'Cannot pin the production-environment test to a test database: DB_DATABASE is absent from the '.
            "process environment, and {$path} declares none. Refusing to fall back to whatever .env points ".
            "at (the dev database) — see this class's own docblock."
        );
    }

    private static function fromProcessEnvironment(string $name): ?string
    {
        foreach ([$_SERVER, $_ENV] as $source) {
            if (isset($source[$name]) && $source[$name] !== '') {
                return (string) $source[$name];
            }
        }

        $value = getenv($name);

        return $value === false || $value === '' ? null : (string) $value;
    }

    private static function set(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private static function forget(string $name): void
    {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}
