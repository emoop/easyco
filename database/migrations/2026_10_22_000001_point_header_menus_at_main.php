<?php

use App\Content\Menus\MenuModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Header\HeaderCompiler;
use App\Storefront\Header\HeaderValidator;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * UX-0: a header published BEFORE D3 has `menu.source = categories` (or no source at all), so the menus built in "Menus" never show in it. Every `menu`
     * element of the stored DRAFT and of the PUBLISHED layout whose source is missing or `categories` becomes `main` (the merchant's main menu), and the published
     * layout is compiled again (`header.compiled` and the token stylesheet follow, the appearance cache entry is replaced).
     *
     * Only when a `main` menu exists. Idempotent: a layout that already points at a menu is not touched, so a second run changes nothing. A stored layout that
     * does not pass the validator is left exactly as it is (the Header page will say what is wrong); the draft is not validated here (the page validates it on save).
     */
    public function up(): void
    {
        if (! MenuModel::query()->where('key', 'main')->exists()) {
            return;
        }

        $settings = app(SiteSettingsRepository::class);

        $draft = $this->decode($settings->get(AppearanceSettings::HEADER_DRAFT_KEY));

        if ($draft !== null && self::repoint($draft)) {
            $settings->set(AppearanceSettings::HEADER_DRAFT_KEY, (string) json_encode($draft, JSON_THROW_ON_ERROR));
        }

        // The published layout; when only the compiled copy exists (nothing else ever read the key), that copy is the one the shop shows.
        $published = $this->decode($settings->get(AppearanceSettings::HEADER_PUBLISHED_KEY))
            ?? ($this->decode($settings->get(AppearanceSettings::HEADER_COMPILED_KEY))['layout'] ?? null);

        if ($published !== null && self::repoint($published)) {
            $validation = HeaderValidator::validate($published);

            if ($validation->passes()) {
                app(HeaderCompiler::class)->publish($validation->layout, $settings);
            }
        }
    }

    /** The header is the merchant's data now: rolling back changes nothing. */
    public function down(): void
    {
    }

    /** @return array<string, mixed>|null */
    private function decode(mixed $json): ?array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Points every `menu` element at `main` when its source is missing or `categories`. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $layout
     */
    private static function repoint(array &$layout): bool
    {
        $changed = false;
        $fix = static function (mixed &$elements) use (&$changed): void {
            if (! is_array($elements)) {
                return;
            }

            foreach ($elements as &$element) {
                if (is_array($element) && ($element['type'] ?? null) === 'menu') {
                    $source = $element['settings']['source'] ?? null;

                    if ($source === null || $source === '' || $source === 'categories') {
                        $element['settings']['source'] = 'main';
                        $changed = true;
                    }
                }
            }
        };

        foreach (['desktop', 'mobile'] as $name) {
            if (! isset($layout[$name]['rows']) || ! is_array($layout[$name]['rows'])) {
                continue;
            }

            foreach ($layout[$name]['rows'] as &$row) {
                foreach (['left', 'center', 'right'] as $slot) {
                    if (isset($row[$slot])) {
                        $fix($row[$slot]);
                    }
                }
            }

            unset($row);
        }

        if (isset($layout['mobile']['drawer'])) {
            $fix($layout['mobile']['drawer']);
        }

        return $changed;
    }
};
