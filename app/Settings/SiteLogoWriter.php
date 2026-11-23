<?php

namespace App\Settings;

use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Media\Contracts\MediaStorageAdapter;
use EasyCo\Media\Persistence\Eloquent\MediaAssetModel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * LOGO-1 (site-logo.md): reads and writes the choices of the «Лого» tab. Two steps, so that ALL validation happens before anything is written:
 *
 *   prepare($input)  validates the name and every CHANGED picture (the original is read from the library: it needs no queued WebP variants), generates the derivatives IN MEMORY, and returns a plan;
 *                    a refusal is a ValidationException keyed by form field (`data.site_name`, `data.site_icon`, `data.logo_main`, `data.logo_dark`), nothing written.
 *   commit($plan)    writes the generated files, the settings and `logo.compiled`, then deletes the files of the previous generation. Returns whether anything changed.
 *
 * GENERATION RUNS ONLY WHEN A PICTURE CHANGED: a picture whose id equals the stored one and whose compiled part is intact is neither read nor regenerated, so an unrelated save (the locale, the
 * time zone) costs nothing here. Only `icon-*` and `logo-*` files of the shape SiteLogo::FILE are ever deleted; `tokens-*`, `cookie-preview-*` and the rest of the directory are never touched.
 */
class SiteLogoWriter
{
    /** form field => [kind, setting key, label key] */
    private const PICTURES = [
        'site_icon' => ['icon', SiteLogo::ICON],
        'logo_main' => ['main', SiteLogo::MAIN],
        'logo_dark' => ['dark', SiteLogo::DARK],
    ];

    public function __construct(
        private readonly SiteSettingsRepository $settings,
        private readonly MediaStorageAdapter $storage,
        private readonly LogoGenerator $generator,
    ) {
    }

    /** @return array{site_name: ?string, site_icon: ?int, logo_main: ?int, logo_dark: ?int} what the tab shows */
    public function current(): array
    {
        $raw = $this->settings->getMany([SiteLogo::NAME, SiteLogo::ICON, SiteLogo::MAIN, SiteLogo::DARK]);
        $id = static fn (?string $value): ?int => $value !== null && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;

        return [
            'site_name' => SiteLogo::cleanName($raw[SiteLogo::NAME] ?? null),
            'site_icon' => $id($raw[SiteLogo::ICON] ?? null),
            'logo_main' => $id($raw[SiteLogo::MAIN] ?? null),
            'logo_dark' => $id($raw[SiteLogo::DARK] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  {site_name, site_icon, logo_main, logo_dark}
     *
     * @throws ValidationException
     */
    public function prepare(array $input): SiteLogoPlan
    {
        $errors = [];
        $plan = new SiteLogoPlan;
        $stored = $this->current();
        $compiled = SiteLogo::parseCompiled($this->settings->get(SiteLogo::COMPILED)) ?? ['icon' => null, 'main' => null, 'dark' => null];
        $plan->compiled = $compiled;

        // ---- the name
        $name = $input['site_name'] ?? null;
        $name = is_string($name) ? trim($name) : null;

        if ($name !== null && $name !== '' && SiteLogo::cleanName($name) === null) {
            $errors['data.site_name'] = __('logo_admin.errors.name', ['max' => SiteLogo::NAME_MAX]);
        }

        $plan->name = $name === '' ? null : $name;
        $plan->nameChanged = ($plan->name ?? null) !== $stored['site_name'];

        // ---- the pictures
        foreach (self::PICTURES as $field => [$kind, $key]) {
            $id = filled($input[$field] ?? null) ? (int) $input[$field] : null;
            $plan->ids[$kind] = $id;

            if ($id === null) {
                if ($stored[$field] !== null || $compiled[$kind] !== null) {
                    $plan->compiled[$kind] = null;
                    $plan->changed[$kind] = true;
                }

                continue;
            }

            if ($id === $stored[$field] && $compiled[$kind] !== null) {
                continue; // nothing changed: neither read nor regenerated
            }

            try {
                [$bytes, $width, $height] = $this->source($id, $kind);
                $plan->generate($kind, $bytes, $this->generator);
                $plan->changed[$kind] = true;
            } catch (ValidationException $e) {
                $errors["data.{$field}"] = (string) collect($e->errors())->flatten()->first();
            } catch (RuntimeException $e) {
                $errors["data.{$field}"] = __('logo_admin.errors.generate');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $plan;
    }

    /**
     * @throws RuntimeException a file could not be written (nothing is left behind)
     */
    public function commit(SiteLogoPlan $plan): bool
    {
        if (! $plan->hasChanges()) {
            return false;
        }

        $disk = Storage::disk('public');
        $written = [];

        try {
            foreach ($plan->files as $path => $bytes) {
                if (! $disk->put($path, $bytes)) {
                    throw new RuntimeException('A logo file could not be written.');
                }

                $written[] = $path;
            }
        } catch (Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        foreach (self::PICTURES as [$kind, $key]) {
            ($plan->changed[$kind] ?? false) && ($plan->ids[$kind] === null ? $this->settings->forget($key) : $this->settings->set($key, (string) $plan->ids[$kind]));
        }

        $parts = array_filter($plan->compiled, static fn (mixed $part): bool => $part !== null);
        $parts === [] ? $this->settings->forget(SiteLogo::COMPILED) : $this->settings->set(SiteLogo::COMPILED, (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $plan->nameChanged && ($plan->name === null ? $this->settings->forget(SiteLogo::NAME) : $this->settings->set(SiteLogo::NAME, $plan->name));

        $this->cleanup($parts);

        return true;
    }

    /**
     * The original of a picture, checked: a PNG or WebP picture of the library, readable, not too big, and the right shape. Reads the stored ORIGINAL (no `ready` status, no variants needed).
     *
     * @return array{0: string, 1: int, 2: int} bytes, width, height
     *
     * @throws ValidationException
     */
    private function source(int $id, string $kind): array
    {
        $fail = static fn (string $message): never => throw ValidationException::withMessages(['x' => $message]);
        $row = MediaAssetModel::query()->find($id);

        if ($row === null) {
            $fail(__('logo_admin.errors.missing'));
        }

        if ($row->type !== 'image' || ! in_array((string) $row->mime, SiteLogo::MIMES, true)) {
            $fail(__('logo_admin.errors.type', ['types' => 'PNG, WebP']));
        }

        $type = (string) $row->mime === 'image/png' ? IMG_PNG : IMG_WEBP;

        if (! function_exists('imagetypes') || (imagetypes() & $type) === 0) {
            $fail(__('logo_admin.errors.no_gd', ['type' => $row->mime === 'image/png' ? 'PNG' : 'WebP']));
        }

        try {
            $bytes = $this->storage->get((string) $row->disk, (string) $row->path);
        } catch (Throwable) {
            $fail(__('logo_admin.errors.unreadable'));
        }

        // the HEADER is read, nothing is decoded yet: the content decides the type and the size, never the file name or the stored values
        $size = strlen($bytes) > SiteLogo::MAX_BYTES ? false : @getimagesizefromstring($bytes);
        $expected = (string) $row->mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_WEBP;

        if ($size === false || ($size[2] ?? null) !== $expected || ($size[0] ?? 0) < 1 || ($size[1] ?? 0) < 1) {
            $fail(strlen($bytes) > SiteLogo::MAX_BYTES ? __('logo_admin.errors.too_big_file', ['mb' => (int) (SiteLogo::MAX_BYTES / 1_000_000)]) : __('logo_admin.errors.unreadable'));
        }

        [$width, $height] = [(int) $size[0], (int) $size[1]];

        if ($width * $height > SiteLogo::MAX_PIXELS) {
            $fail(__('logo_admin.errors.too_many_pixels', ['w' => $width, 'h' => $height, 'mp' => (int) (SiteLogo::MAX_PIXELS / 1_000_000)]));
        }

        if ($kind === 'icon') {
            if ($width !== $height) {
                $fail(__('logo_admin.errors.icon_square', ['w' => $width, 'h' => $height, 'min' => SiteLogo::ICON_MIN]));
            }

            $width < SiteLogo::ICON_MIN && $fail(__('logo_admin.errors.icon_small', ['w' => $width, 'h' => $height, 'min' => SiteLogo::ICON_MIN]));
        } elseif ($width < SiteLogo::LOGO_MIN_WIDTH) {
            $fail(__('logo_admin.errors.logo_small', ['w' => $width, 'min' => SiteLogo::LOGO_MIN_WIDTH]));
        }

        return [$bytes, $width, $height];
    }

    /**
     * Deletes the generated files that the new value no longer names. ONLY names of the shape SiteLogo::FILE: the directory also holds the token stylesheets and the previews.
     *
     * @param  array<string, array<string, mixed>>  $parts
     */
    private function cleanup(array $parts): void
    {
        $keep = [];
        array_walk_recursive($parts, static function (mixed $value, mixed $key) use (&$keep): void {
            if ($key === 'url' && is_string($value)) {
                $keep[basename($value)] = true;
            }
        });

        $disk = Storage::disk('public');

        foreach ($disk->files(SiteLogo::DIRECTORY) as $file) {
            $name = basename($file);

            if (preg_match(SiteLogo::FILE, $name) === 1 && ! isset($keep[$name])) {
                $disk->delete($file);
            }
        }
    }
}
