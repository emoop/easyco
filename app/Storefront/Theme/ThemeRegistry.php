<?php

namespace App\Storefront\Theme;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * The themes of the storefront (storefront-customization-design.md 2): reads and validates `resources/themes/{key}/theme.json`, memoised for the
 * request. A theme is a deployed folder (C-D2): nothing here reads anything an admin typed. VALIDATION is strict on purpose: the file is at most 64 KB,
 * valid JSON, an object, with exactly the known top-level keys (an unknown one is refused, a missing one too), `key` equal to its folder name and shaped
 * `^[a-z0-9-]{1,40}$`. A manifest that breaks a rule is REFUSED (InvalidThemeException), never half-used.
 *
 * `active()` takes the configured key (the setting `storefront.theme`, read by StorefrontAppearance together with the rest of the appearance: no query
 * of its own). Absent, empty or UNKNOWN (or invalid) means `easyco`; an unknown key is logged ONCE per process at warning level, no stack trace.
 */
#[Scoped]
final class ThemeRegistry
{
    public const DEFAULT_KEY = 'easyco';

    public const MAX_BYTES = 65536;

    private const TOP_LEVEL_KEYS = ['key', 'name', 'version', 'extends', 'views', 'assets', 'tokens', 'header', 'slots'];

    /** @var array<string, bool> keys already warned about, per process */
    private static array $warned = [];

    /** @var array<string, ThemeManifest> */
    private array $memo = [];

    public function __construct(private readonly ?string $basePath = null)
    {
    }

    private function base(): string
    {
        return $this->basePath ?? resource_path('themes');
    }

    /** @return list<string> the keys of every theme folder that has a manifest (valid or not) */
    public function keys(): array
    {
        $keys = [];

        foreach (glob($this->base().'/*/theme.json') ?: [] as $file) {
            $keys[] = basename(dirname($file));
        }

        sort($keys);

        return $keys;
    }

    /** @throws InvalidThemeException */
    public function get(string $key): ThemeManifest
    {
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (preg_match('/^[a-z0-9-]{1,40}$/', $key) !== 1) {
            throw new InvalidThemeException('The theme key is not a valid key.');
        }

        $file = $this->base().'/'.$key.'/theme.json';

        if (! is_file($file)) {
            throw new InvalidThemeException("There is no theme \"{$key}\".");
        }

        return $this->memo[$key] = $this->read($key, $file);
    }

    /** The theme to show: the configured one when it exists and is valid, else `easyco` (an unknown key is logged once). */
    public function active(?string $configured): ThemeManifest
    {
        $key = $configured === null || trim($configured) === '' ? self::DEFAULT_KEY : trim($configured);

        if ($key !== self::DEFAULT_KEY) {
            try {
                return $this->get($key);
            } catch (InvalidThemeException $e) {
                $this->warnOnce($key, $e->getMessage());
            }
        }

        return $this->get(self::DEFAULT_KEY);
    }

    private function warnOnce(string $key, string $reason): void
    {
        $id = $this->base().'|'.$key;

        if (isset(self::$warned[$id])) {
            return;
        }

        self::$warned[$id] = true;
        // The configured key is shown cut: a log line never carries a long free text.
        Log::warning('storefront.theme_unknown: falling back to the default theme', ['theme' => mb_substr($key, 0, 40), 'reason' => $reason]);
    }

    /** Forgets what was warned about (tests only). */
    public static function forgetWarnings(): void
    {
        self::$warned = [];
    }

    private function read(string $folderKey, string $file): ThemeManifest
    {
        $size = filesize($file);

        if ($size === false || $size > self::MAX_BYTES) {
            throw new InvalidThemeException('The theme manifest is larger than 64 KB.');
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidThemeException('The theme manifest is not valid JSON.');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidThemeException('The theme manifest must be a JSON object.');
        }

        $unknown = array_diff(array_keys($data), self::TOP_LEVEL_KEYS);

        if ($unknown !== []) {
            throw new InvalidThemeException('The theme manifest has an unknown key: '.mb_substr((string) reset($unknown), 0, 40).'.');
        }

        $missing = array_diff(self::TOP_LEVEL_KEYS, array_keys($data));

        if ($missing !== []) {
            throw new InvalidThemeException('The theme manifest lacks the key "'.reset($missing).'".');
        }

        $key = $data['key'];

        if (! is_string($key) || preg_match('/^[a-z0-9-]{1,40}$/', $key) !== 1 || $key !== $folderKey) {
            throw new InvalidThemeException('The theme key must equal its folder name and match ^[a-z0-9-]{1,40}$.');
        }

        $this->assertString($data['name'], 100, 'name');
        $this->assertString($data['version'], 20, 'version');

        if (preg_match('/^\d+\.\d+\.\d+$/', $data['version']) !== 1) {
            throw new InvalidThemeException('The theme version must look like 1.0.0.');
        }

        // V1: a theme extends nothing, or the default theme.
        if ($data['extends'] !== null && $data['extends'] !== self::DEFAULT_KEY) {
            throw new InvalidThemeException('A theme may extend only "easyco" in this version.');
        }

        if ($data['views'] !== null && (! is_string($data['views']) || preg_match('/^[a-z0-9_-]{1,40}$/', $data['views']) !== 1 || ! is_dir($this->base().'/'.$key.'/'.$data['views']))) {
            throw new InvalidThemeException('The theme "views" must be null or the name of a folder inside the theme.');
        }

        $assets = $data['assets'];

        if (! is_array($assets) || array_is_list($assets) || array_diff(array_keys($assets), ['css', 'js']) !== [] || count(array_filter($assets, 'is_string')) !== count($assets)) {
            throw new InvalidThemeException('The theme "assets" must be an object with string "css" and "js".');
        }

        $tokens = $data['tokens'];

        if (! is_array($tokens) || count($tokens) > 64 || count(array_filter($tokens, 'is_string')) !== count($tokens) || ($tokens !== [] && array_is_list($tokens))) {
            throw new InvalidThemeException('The theme "tokens" must be an object of at most 64 strings.');
        }

        $header = $data['header'];

        if (! is_array($header) || array_diff(array_keys($header), ['rows', 'elements']) !== [] || ! $this->isListOfStrings($header['rows'] ?? null, 8) || ! $this->isListOfStrings($header['elements'] ?? null, 64)) {
            throw new InvalidThemeException('The theme "header" must have "rows" and "elements" lists of strings.');
        }

        if (! is_array($data['slots']) || ! array_is_list($data['slots']) || count($data['slots']) > 200) {
            throw new InvalidThemeException('The theme "slots" must be a list.');
        }

        return new ThemeManifest($key, $data['name'], $data['version'], $data['extends'], $data['views'], $assets, $tokens, ['rows' => $header['rows'], 'elements' => $header['elements']], $data['slots'], $this->base().'/'.$key);
    }

    private function assertString(mixed $value, int $max, string $name): void
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $max) {
            throw new InvalidThemeException("The theme \"{$name}\" must be a non-empty string of at most {$max} characters.");
        }
    }

    private function isListOfStrings(mixed $value, int $max): bool
    {
        return is_array($value) && array_is_list($value) && count($value) <= $max && count(array_filter($value, 'is_string')) === count($value);
    }
}
