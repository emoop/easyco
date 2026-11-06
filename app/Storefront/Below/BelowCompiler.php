<?php

namespace App\Storefront\Below;

use App\Content\HtmlBlocks\HtmlBlockModel;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\Conditions\ProductConditions;
use App\Storefront\Home\HomeCompiler;
use InvalidArgumentException;

/**
 * P4: the PUBLISHED slots below the product page's main area as the storefront reads them: one compiled copy `zones.below` inside the design document the appearance already reads once per request (no query of its own).
 *
 *   {blocks: {id: sanitised HTML}, conditions: {id: conditions | false}, slots: [Slot]}
 *
 * The HTML of every block the slots show is read in ONE batch and kept once; the «Показвай, когато…» conditions of the blocks of the `html_block` slots travel with it (HB-2: checked against the CURRENT product on
 * the page; `false` = invalid, never shown). The page reads the copy and validates it again (BelowSlots::read), so a stored value that is bad costs that slot, never the page. A change of an HTML block recompiles
 * the copy (refresh(), called where the home layout's is).
 *
 * @phpstan-import-type Set from BelowSlots
 */
final class BelowCompiler
{
    public const ZONE = 'below';

    /** The copy may not make the whole design document too large (HomeCompiler::MAX_BYTES). */
    public const MAX_BYTES = 200_000;

    /**
     * @param  Set  $set  VALIDATED
     * @return array{blocks: array<string, string>, conditions: array<string, mixed>, slots: list<array<string, mixed>>}
     */
    public static function compiled(array $set): array
    {
        $ids = BelowSlots::blockIds($set);
        $blocks = [];

        if ($ids !== []) {
            foreach (HtmlBlockModel::renderMany($ids) as $id => $html) {
                $html === '' || $blocks[(string) $id] = $html;
            }
        }

        $conditions = [];

        foreach (ProductConditions::storedFor(BelowSlots::conditionalBlockIds($set)) as $id => $stored) {
            $conditions[(string) $id] = $stored;
        }

        return ['blocks' => $blocks, 'conditions' => $conditions, 'slots' => $set['slots']];
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
        $blank = BelowSlots::isBlank($set);

        if ($blank) {
            unset($document['zones'][self::ZONE]);
        } else {
            $compiled = self::compiled($set);

            if (strlen((string) json_encode($compiled, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
                throw new InvalidArgumentException('The compiled slots are too large.');
            }

            $document['zones'][self::ZONE] = $compiled;
        }

        // the design document first: when it is refused (too large) the published set is not touched
        app(HomeCompiler::class)->store($document, $settings);
        $blank ? $settings->forget(AppearanceSettings::BELOW_PUBLISHED_KEY) : $settings->set(AppearanceSettings::BELOW_PUBLISHED_KEY, (string) json_encode($set, JSON_THROW_ON_ERROR));
    }

    /** Rebuilds the compiled copy from the PUBLISHED set when something it shows changed (an HTML block or its conditions). Returns whether it changed; nothing published = nothing happens. */
    public function refresh(): bool
    {
        $settings = app(SiteSettingsRepository::class);
        $json = $settings->get(AppearanceSettings::BELOW_PUBLISHED_KEY);
        $stored = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($stored)) {
            return false;
        }

        $set = BelowSlots::read($stored);

        if (BelowSlots::isBlank($set)) {
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
     * The compiled copy from the design document, read again: the slots (BelowSlots::read), the HTML of the blocks kept only when it is a string of a sane size, the conditions checked for their shape
     * (ProductConditions::active). Null when there is no slot to show.
     *
     * @return array{blocks: array<string, string>, conditions: array<string, array<string, mixed>|false>, slots: list<array<string, mixed>>}|null
     */
    public static function published(?string $json): ?array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][self::ZONE] ?? null;

        if (! is_array($entry)) {
            return null;
        }

        $slots = BelowSlots::read($entry)['slots'];

        if ($slots === []) {
            return null;
        }

        $blocks = [];

        foreach (is_array($entry['blocks'] ?? null) ? $entry['blocks'] : [] as $id => $html) {
            is_string($html) && strlen($html) <= 65_536 && $blocks[(string) $id] = $html;
        }

        $conditions = [];

        foreach (is_array($entry['conditions'] ?? null) ? $entry['conditions'] : [] as $id => $stored) {
            $active = ProductConditions::active($stored);
            $active === null || $conditions[(string) $id] = $active;
        }

        return ['blocks' => $blocks, 'conditions' => $conditions, 'slots' => $slots];
    }
}
