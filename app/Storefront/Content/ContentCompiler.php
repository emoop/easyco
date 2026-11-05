<?php

namespace App\Storefront\Content;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\Home\HomeCompiler;
use InvalidArgumentException;

/**
 * P3: the PUBLISHED listing-page templates as the storefront reads them: one compiled copy `zones.content` inside the design document the appearance already reads once per request (no query of its own).
 *
 *   {blocks: {id: sanitised HTML}, kinds: {categories: {default: Template|null, own: {"12": Template}}, …}}
 *
 * The HTML of every block any template shows is read in ONE batch and kept once (a block shared by 80 templates is stored once). The page reads the copy and validates ONLY the template it prints (ContentTemplate::read),
 * so a stored value that is bad costs that slot, never the page. A change of an HTML block recompiles the copy (refresh(), called where the home layout's is).
 *
 * @phpstan-import-type Set from ContentSet
 */
final class ContentCompiler
{
    public const ZONE = 'content';

    /** The copy may not make the whole design document too large (HomeCompiler::MAX_BYTES): a template set that does is refused with the zone's «too large» notice. */
    public const MAX_BYTES = 200_000;

    /**
     * @param  Set  $set  VALIDATED
     * @return array{blocks: array<string, string>, kinds: array<string, array{default: array<string, mixed>|null, own: array<string, array<string, mixed>>}>}
     */
    public static function compiled(array $set): array
    {
        $ids = [];
        $kinds = [];

        foreach ($set as $kind => $entry) {
            if ($entry['default'] === null && $entry['own'] === []) {
                continue;
            }

            $own = [];

            foreach ($entry['own'] as $id => $template) {
                $own[(string) $id] = $template;
                array_push($ids, ...ContentTemplate::blockIds($template));
            }

            $entry['default'] === null || array_push($ids, ...ContentTemplate::blockIds($entry['default']));
            $kinds[$kind] = ['default' => $entry['default'], 'own' => $own];
        }

        $blocks = [];

        if ($ids !== []) {
            foreach (HtmlBlockModel::renderMany(array_values(array_unique($ids))) as $id => $html) {
                $html === '' || $blocks[(string) $id] = $html;
            }
        }

        return ['blocks' => $blocks, 'kinds' => $kinds];
    }

    /**
     * Publishes a validated set: its JSON (the admin's source) and the compiled copy. A blank set removes both: the shop prints the pages it always printed.
     *
     * @param  Set  $set
     * @throws InvalidArgumentException when the compiled copy is too large
     */
    public function publish(array $set, SiteSettingsRepository $settings): void
    {
        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));

        $blank = ContentSet::isBlank($set);

        if ($blank) {
            unset($document['zones'][self::ZONE]);
        } else {
            $compiled = self::compiled($set);

            if (strlen((string) json_encode($compiled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
                throw new InvalidArgumentException('The compiled templates are too large.');
            }

            $document['zones'][self::ZONE] = $compiled;
        }

        // the design document first: when it is refused (too large) the published set is not touched
        app(HomeCompiler::class)->store($document, $settings);
        $blank ? $settings->forget(AppearanceSettings::CONTENT_PUBLISHED_KEY) : $settings->set(AppearanceSettings::CONTENT_PUBLISHED_KEY, (string) json_encode($set, JSON_THROW_ON_ERROR));
    }

    /** Rebuilds the compiled copy from the PUBLISHED set when something it shows changed (an HTML block). Returns whether it changed; nothing is published = nothing happens. */
    public function refresh(): bool
    {
        $settings = app(SiteSettingsRepository::class);
        $json = $settings->get(AppearanceSettings::CONTENT_PUBLISHED_KEY);
        $stored = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($stored)) {
            return false;
        }

        $set = ContentSet::read($stored);

        if (ContentSet::isBlank($set)) {
            return false;
        }

        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $compiled = self::compiled($set);

        if (($document['zones'][self::ZONE] ?? null) == $compiled) {
            return false;
        }

        $document['zones'][self::ZONE] = $compiled;
        app(HomeCompiler::class)->store($document, $settings);

        return true;
    }

    /**
     * The compiled copy from the design document, with the HTML of the blocks kept only when it is a string of a sane size; null when there is none. The TEMPLATES are not validated here (see template()).
     *
     * @return array{blocks: array<string, string>, kinds: array<string, mixed>}|null
     */
    public static function published(?string $json): ?array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][self::ZONE] ?? null;

        if (! is_array($entry) || ! is_array($entry['kinds'] ?? null)) {
            return null;
        }

        $blocks = [];

        foreach (is_array($entry['blocks'] ?? null) ? $entry['blocks'] : [] as $id => $html) {
            is_string($html) && strlen($html) <= 65_536 && $blocks[(string) $id] = $html;
        }

        return ['blocks' => $blocks, 'kinds' => $entry['kinds']];
    }

    /**
     * The template a page prints from a compiled copy: its own when it has one, else the kind's default, else null; ONLY that one is validated (ContentTemplate::read).
     *
     * @param  array{blocks: array<string, string>, kinds: array<string, mixed>}  $compiled
     * @return array{cloud: bool, top: int|null, bottom: array<string, mixed>|null}|null
     */
    public static function template(array $compiled, string $kind, int $id): ?array
    {
        $entry = is_array($compiled['kinds'][$kind] ?? null) ? $compiled['kinds'][$kind] : [];
        $own = is_array($entry['own'] ?? null) ? $entry['own'] : [];
        $stored = array_key_exists((string) $id, $own) ? $own[(string) $id] : ($entry['default'] ?? null);

        return $stored === null ? null : ContentTemplate::read($stored);
    }
}
