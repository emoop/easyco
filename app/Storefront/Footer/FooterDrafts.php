<?php

namespace App\Storefront\Footer;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use InvalidArgumentException;

/**
 * The footer's DRAFT / PUBLISHED life, in one place (DS-2b): the old «Футър» page and the «Дизайн» screen both call it, so a draft saved by one is what the other shows after a reload (the same
 * pattern as HeaderDrafts). It touches the settings, the compiler and the activity log only: no form, no notification. The layout it is given must already be VALIDATED.
 */
final class FooterDrafts
{
    /** The layout an editor starts from: the draft, else the published one, else the seeded default (each re-validated; a bad stored one is skipped). @return array<string, mixed> */
    public static function startingLayout(): array
    {
        $settings = app(SiteSettingsRepository::class);

        foreach ([AppearanceSettings::FOOTER_DRAFT_KEY, AppearanceSettings::FOOTER_PUBLISHED_KEY] as $key) {
            $json = $settings->get($key);
            $layout = is_string($json) ? json_decode($json, true) : null;

            if (is_array($layout)) {
                $validation = FooterValidator::validate($layout);

                if ($validation->passes()) {
                    return $validation->layout;
                }
            }
        }

        return FooterLayout::default();
    }

    /** The stored draft as a validated layout, or null when there is none (or it does not validate). @return array<string, mixed>|null */
    public static function draft(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::FOOTER_DRAFT_KEY);
        $layout = is_string($json) ? json_decode($json, true) : null;
        $validation = is_array($layout) ? FooterValidator::validate($layout) : null;

        return $validation !== null && $validation->passes() ? $validation->layout : null;
    }

    /** The PUBLISHED layout (re-validated), or null when none was ever published or it does not validate. @return array<string, mixed>|null */
    public static function published(): ?array
    {
        $json = app(SiteSettingsRepository::class)->get(AppearanceSettings::FOOTER_PUBLISHED_KEY);
        $layout = is_string($json) ? json_decode($json, true) : null;
        $validation = is_array($layout) ? FooterValidator::validate($layout) : null;

        return $validation !== null && $validation->passes() ? $validation->layout : null;
    }

    /** DS-5: is there a draft that differs from what is published (or nothing is published yet)? */
    public static function hasUnpublishedChanges(): bool
    {
        $draft = self::draft();

        return $draft !== null && $draft != self::published();
    }

    /** @param  array<string, mixed>  $layout */
    public static function saveDraft(array $layout): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::FOOTER_DRAFT_KEY, (string) json_encode($layout, JSON_THROW_ON_ERROR));
        self::log('draft');
    }

    /**
     * @param  array<string, mixed>  $layout
     *
     * @throws InvalidArgumentException when the compiled copy is too large (the draft is saved already)
     */
    public static function publish(array $layout): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::FOOTER_DRAFT_KEY, (string) json_encode($layout, JSON_THROW_ON_ERROR));
        app(FooterCompiler::class)->publish($layout, $settings);
        // DS-5: a draft that equals what is published is no draft (the compiler stores the published layout normalised)
        $settings->forget(AppearanceSettings::FOOTER_DRAFT_KEY);
        self::log('published');
    }

    /** «Върни публикуваното»: the draft is forgotten. */
    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::FOOTER_DRAFT_KEY);
        self::log('discarded');
    }

    /** One activity-log line: who (the logger knows) and which action; never a setting value. */
    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_footer', 'footer', $action, null, 'saved');
    }
}
