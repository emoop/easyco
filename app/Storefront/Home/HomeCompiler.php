<?php

namespace App\Storefront\Home;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Appearance\StorefrontAppearance;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\Header\AppearanceRecompiler;
use InvalidArgumentException;

/**
 * PUBLISH of the home layout (HP-1; the pattern of ProductSummaryCompiler): validates the layout, stores it as `design.home.global.published` and as the zone's entry of `design.compiled`
 * (`{hash, zones: {home: {global: {layout, blocks}}}}`: the validated layout PLUS the sanitised HTML of every HTML block it shows, so that printing the home page costs no query), and replaces the
 * appearance reader's cache entry. `design.compiled` is shared by every zone the Design screen stores this way: the other zones' entries are kept. A layout that is the DEFAULT (no sections) is not
 * stored at all: the shop then prints the home page it always printed. The «Показвай, когато…» conditions of an HTML block are about a PRODUCT: like the footer and the menus, the home page ignores them.
 */
final class HomeCompiler
{
    public const MAX_BYTES = 262_144;

    /**
     * What a layout needs from the rest of the shop, as it is NOW: the sanitised HTML of its HTML blocks (ONE batched read for all of them).
     *
     * @param  array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}  $layout  VALIDATED
     * @return array{layout: array<string, mixed>, blocks: array<string, string>}
     */
    public static function compiled(array $layout): array
    {
        $ids = [];

        foreach ($layout['sections'] as $section) {
            if ($section['type'] === 'html_block' && is_int($section['settings']['html_block'] ?? null)) {
                $ids[] = $section['settings']['html_block'];
            }
        }

        $blocks = [];

        if ($ids !== []) {
            foreach (HtmlBlockModel::renderMany(array_values(array_unique($ids))) as $id => $html) {
                if ($html !== '') {
                    $blocks[(string) $id] = $html;
                }
            }
        }

        return ['layout' => $layout, 'blocks' => $blocks];
    }

    /**
     * Publishes a layout (validated again here).
     *
     * @param  array<string, mixed>  $layout
     * @throws InvalidArgumentException when the layout does not validate or the compiled document is too large
     */
    public function publish(array $layout, SiteSettingsRepository $settings): void
    {
        [$clean, $errors] = HomeValidator::validate($layout);

        if ($errors !== []) {
            throw new InvalidArgumentException('The home layout is not valid.');
        }

        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));

        if ($clean == HomeLayout::default()) {
            $settings->forget(AppearanceSettings::HOME_PUBLISHED_KEY);
            unset($document['zones'][HomeCatalog::ZONE]);
        } else {
            $document['zones'][HomeCatalog::ZONE][HomeCatalog::SCOPE] = self::compiled($clean);
            $settings->set(AppearanceSettings::HOME_PUBLISHED_KEY, (string) json_encode($clean, JSON_THROW_ON_ERROR));
        }

        $this->store($document, $settings);
    }

    /** Forgets the published layout: the shop prints the home page it always printed. */
    public function unpublish(SiteSettingsRepository $settings): void
    {
        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        unset($document['zones'][HomeCatalog::ZONE]);
        $settings->forget(AppearanceSettings::HOME_PUBLISHED_KEY);
        $this->store($document, $settings);
    }

    /** @param  array{hash: string, zones: array<string, mixed>}  $document */
    private function store(array $document, SiteSettingsRepository $settings): void
    {
        if ($document['zones'] === []) {
            $settings->forget(AppearanceSettings::DESIGN_COMPILED_KEY);
        } else {
            $document['hash'] = substr(sha1((string) json_encode($document['zones'])), 0, 12);
            $json = (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            if (strlen($json) > self::MAX_BYTES) {
                throw new InvalidArgumentException('The compiled design is too large.');
            }

            $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, $json);
        }

        // the token stylesheet is rebuilt from what is stored now (the same as the other zones' publish does), then the reader's cache entry is replaced
        app(AppearanceRecompiler::class)->run($settings);
        app(StorefrontAppearance::class)->refresh();
    }

    /**
     * Rebuilds the compiled copy from the PUBLISHED layout when something it shows changed (an HTML block): nothing happens when none is published or it no longer validates. Returns whether it changed.
     */
    public function refresh(): bool
    {
        $settings = app(SiteSettingsRepository::class);
        $json = $settings->get(AppearanceSettings::HOME_PUBLISHED_KEY);
        $layout = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($layout)) {
            return false;
        }

        [$clean, $errors] = HomeValidator::validate($layout);

        if ($errors !== []) {
            return false;
        }

        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $compiled = self::compiled($clean);

        if (($document['zones'][HomeCatalog::ZONE][HomeCatalog::SCOPE] ?? null) == $compiled) {
            return false;
        }

        $document['zones'][HomeCatalog::ZONE][HomeCatalog::SCOPE] = $compiled;
        $this->store($document, $settings);

        return true;
    }

    /**
     * The PUBLISHED layout and the sanitised HTML of its blocks, read from the compiled document and validated AGAIN (a layout that does not validate, or has no section, = null: the page prints the old home).
     *
     * @return array{layout: array{spacing: string, sections: list<array{type: string, settings: array<string, mixed>}>}, blocks: array<string, string>}|null
     */
    public static function published(?string $json): ?array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][HomeCatalog::ZONE][HomeCatalog::SCOPE] ?? null;

        if (! is_array($entry) || ! is_array($entry['layout'] ?? null)) {
            return null;
        }

        [$clean, $errors] = HomeValidator::validate($entry['layout']);

        if ($errors !== [] || $clean['sections'] === []) {
            return null;
        }

        $blocks = [];

        foreach (is_array($entry['blocks'] ?? null) ? $entry['blocks'] : [] as $id => $html) {
            if (is_string($html) && strlen($html) <= 65_536) {
                $blocks[(string) $id] = $html;
            }
        }

        return ['layout' => $clean, 'blocks' => $blocks];
    }
}
