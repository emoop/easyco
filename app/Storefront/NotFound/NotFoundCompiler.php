<?php

namespace App\Storefront\NotFound;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\CheckoutHeader\CheckoutHeaderCompiler;
use App\Storefront\Home\HomeCompiler;

/**
 * NF-1: the PUBLISHED content of the 404 page as the storefront reads it: ONE compiled copy `zones.notfound` inside the design document the appearance already reads once per request, so the 404 costs no query of its
 * own. The copy is the validated set itself (the HTML block, the tiles and the product cards are NOT in it: they are rendered once into a cached fragment, NotFoundFragment, keyed by the cache versions). A page
 * that reads it validates it again (NotFoundSet::read), so a stored value that is bad costs that value, never the page.
 *
 * @phpstan-import-type Set from NotFoundSet
 */
final class NotFoundCompiler
{
    public const ZONE = 'notfound';

    /**
     * Publishes a validated set: its JSON (the admin's source) and the compiled copy. A set equal to the defaults removes both: the 404 is the default one.
     *
     * @param  Set  $set
     */
    public function publish(array $set, SiteSettingsRepository $settings): void
    {
        $document = CheckoutHeaderCompiler::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $default = $set == NotFoundSet::defaults();

        if ($default) {
            unset($document['zones'][self::ZONE]);
        } else {
            $document['zones'][self::ZONE] = $set;
        }

        // the design document first: when it is refused (too large) the published set is not touched
        app(HomeCompiler::class)->store($document, $settings);
        $default ? $settings->forget(AppearanceSettings::NOTFOUND_PUBLISHED_KEY) : $settings->set(AppearanceSettings::NOTFOUND_PUBLISHED_KEY, (string) json_encode($set, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** The compiled copy for a draft or a published set (what an admin's signed preview injects). @param Set $set @return Set */
    public static function compiled(array $set): array
    {
        return NotFoundSet::read($set);
    }

    /** The published set from the design document, validated again; the defaults when nothing is published. @return Set */
    public static function published(?string $json): array
    {
        $entry = CheckoutHeaderCompiler::document($json)['zones'][self::ZONE] ?? null;

        return NotFoundSet::read(is_array($entry) ? $entry : null);
    }
}
