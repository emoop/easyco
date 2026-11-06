<?php

namespace App\Storefront\Sections;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\Home\HomeCompiler;
use App\Storefront\Support\DescriptionSanitizer;
use InvalidArgumentException;

/**
 * P4b: the PUBLISHED sections as the storefront reads them: one compiled copy `zones.sections` inside the design document the appearance already reads once per request (no query of its own).
 *
 *   {sections: [ {key, title, source, html} ]}
 *
 * `html` is the section's general text, already cleaned by the storefront's description sanitiser (and cleaned AGAIN when the copy is read, as the description is). A copy that does not validate on read costs
 * the sections it spoils, never the page.
 *
 * @phpstan-import-type Set from SectionSet
 */
final class SectionCompiler
{
    public const ZONE = 'sections';

    /** The copy may not make the whole design document too large (HomeCompiler::MAX_BYTES). */
    public const MAX_BYTES = 200_000;

    /**
     * @param  Set  $set  VALIDATED
     * @return array{sections: list<array{key: string, title: string, source: string, html: string}>}
     */
    public static function compiled(array $set): array
    {
        return ['sections' => array_map(static fn (array $section): array => ['key' => $section['key'], 'title' => $section['title'], 'source' => $section['source'], 'html' => DescriptionSanitizer::clean($section['text'])], $set['sections'])];
    }

    /**
     * Publishes a validated set: its JSON (the admin's source) and the compiled copy. A blank set removes both: the product page is the one it always was.
     *
     * @param  Set  $set
     * @throws InvalidArgumentException when the compiled copy is too large
     */
    public function publish(array $set, SiteSettingsRepository $settings): void
    {
        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $blank = SectionSet::isBlank($set);

        if ($blank) {
            unset($document['zones'][self::ZONE]);
        } else {
            $compiled = self::compiled($set);

            if (strlen((string) json_encode($compiled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
                throw new InvalidArgumentException('The compiled sections are too large.');
            }

            $document['zones'][self::ZONE] = $compiled;
        }

        // the design document first: when it is refused (too large) the published set is not touched
        app(HomeCompiler::class)->store($document, $settings);
        $blank ? $settings->forget(AppearanceSettings::SECTIONS_PUBLISHED_KEY) : $settings->set(AppearanceSettings::SECTIONS_PUBLISHED_KEY, (string) json_encode($set, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The compiled copy from the design document, read again: the sections validated (SectionSet::read on the shape; a bad one is dropped), the HTML cleaned AGAIN. Null when there is no section.
     *
     * @return list<array{key: string, title: string, source: string, html: string}>|null
     */
    public static function published(?string $json): ?array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][self::ZONE] ?? null;

        if (! is_array($entry) || ! is_array($entry['sections'] ?? null)) {
            return null;
        }

        $read = SectionSet::read(['sections' => array_map(static fn (mixed $section): mixed => is_array($section) ? ['key' => $section['key'] ?? null, 'title' => $section['title'] ?? null, 'source' => $section['source'] ?? null, 'text' => ''] : $section, $entry['sections'])]);
        $html = [];

        foreach ($entry['sections'] as $section) {
            is_array($section) && is_string($section['key'] ?? null) && is_string($section['html'] ?? null) && strlen($section['html']) <= 65_536 && $html[$section['key']] = DescriptionSanitizer::clean($section['html']);
        }

        $out = array_map(static fn (array $section): array => ['key' => $section['key'], 'title' => $section['title'], 'source' => $section['source'], 'html' => SectionSet::usesGlobalText($section['source']) ? ($html[$section['key']] ?? '') : ''], $read['sections']);

        return $out === [] ? null : $out;
    }
}
