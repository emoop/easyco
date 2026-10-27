<?php

namespace App\Storefront\CheckoutHeader;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use InvalidArgumentException;

/**
 * The simplified checkout header's DRAFT / PUBLISHED life (DS-2b), the same pattern as HeaderDrafts and FooterDrafts: the settings, the compiler and the activity log, nothing else. The values
 * given must already be VALIDATED. Stored as `design.checkout_header.global.draft|published` (design-screen-design.md section 4).
 */
final class CheckoutHeaderDrafts
{
    /** The values an editor starts from: the draft, else the published ones, else the defaults (each validated again; a bad stored one is skipped). @return array<string, mixed> */
    public static function startingValues(): array
    {
        $settings = app(SiteSettingsRepository::class);

        foreach ([AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY, AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY] as $key) {
            $values = self::decode($settings->get($key));

            if ($values !== null) {
                [$clean, $errors] = CheckoutHeaderValidator::validate($values);

                if ($errors === []) {
                    return $clean;
                }
            }
        }

        return CheckoutHeaderCatalog::defaults();
    }

    /** The stored draft as validated values, or null when there is none (or it does not validate). @return array<string, mixed>|null */
    public static function draft(): ?array
    {
        $values = self::decode(app(SiteSettingsRepository::class)->get(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY));

        if ($values === null) {
            return null;
        }

        [$clean, $errors] = CheckoutHeaderValidator::validate($values);

        return $errors === [] ? $clean : null;
    }

    /** The PUBLISHED values (re-validated), or null when none were ever published or they do not validate. @return array<string, mixed>|null */
    public static function published(): ?array
    {
        $values = self::decode(app(SiteSettingsRepository::class)->get(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY));

        if ($values === null) {
            return null;
        }

        [$clean, $errors] = CheckoutHeaderValidator::validate($values);

        return $errors === [] ? $clean : null;
    }

    /** DS-5: is there a draft that differs from what is published (or nothing is published yet)? */
    public static function hasUnpublishedChanges(): bool
    {
        $draft = self::draft();

        return $draft !== null && $draft != self::published();
    }

    /** @param  array<string, mixed>  $values */
    public static function saveDraft(array $values): void
    {
        app(SiteSettingsRepository::class)->set(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY, (string) json_encode($values, JSON_THROW_ON_ERROR));
        self::log('draft');
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws InvalidArgumentException when the compiled copy is too large (the draft is saved already)
     */
    public static function publish(array $values): void
    {
        $settings = app(SiteSettingsRepository::class);
        $settings->set(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY, (string) json_encode($values, JSON_THROW_ON_ERROR));
        app(CheckoutHeaderCompiler::class)->publish($values, $settings);
        // DS-5: a draft that equals what is published is no draft (the compiler stores the published layout normalised)
        $settings->forget(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY);
        self::log('published');
    }

    /** «Върни публикуваното»: the draft is forgotten. */
    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::CHECKOUT_HEADER_DRAFT_KEY);
        self::log('discarded');
    }

    /** @return array<string, mixed>|null */
    private static function decode(?string $json): ?array
    {
        $values = is_string($json) ? json_decode($json, true) : null;

        return is_array($values) ? $values : null;
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_checkout_header', 'checkout_header', $action, null, 'saved');
    }
}
