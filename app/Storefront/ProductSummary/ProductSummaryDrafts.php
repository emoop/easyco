<?php

namespace App\Storefront\ProductSummary;

use App\Services\ActivityLogger;
use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * The DRAFT / PUBLISHED life of the right column of the product page, in one place (SC-1; the pattern of HeaderDrafts and FooterDrafts). The «Дизайн» screen calls it; the storefront NEVER does (it
 * reads the compiled published layout only). A draft that equals the published layout (the default one when nothing is published) is no draft: its key is forgotten. The layout it is given must
 * already be VALIDATED.
 */
final class ProductSummaryDrafts
{
    /** The layout an editor starts from: the draft, else the published one, else the default (each re-validated; a bad stored one is skipped). @return array{components: list<array<string, mixed>>} */
    public static function startingLayout(): array
    {
        return self::stored(AppearanceSettings::SUMMARY_DRAFT_KEY) ?? self::published();
    }

    /** The stored draft as a validated layout, or null when there is none (or it does not validate). */
    public static function draft(): ?array
    {
        return self::stored(AppearanceSettings::SUMMARY_DRAFT_KEY);
    }

    /** The layout the shop prints now: the stored published one (re-validated), else the default. */
    public static function published(): array
    {
        return self::stored(AppearanceSettings::SUMMARY_PUBLISHED_KEY) ?? ProductSummaryLayout::default();
    }

    /** Is there a draft that differs from what is published? */
    public static function hasUnpublishedChanges(): bool
    {
        $draft = self::draft();

        return $draft !== null && $draft != self::published();
    }

    /** @param  array<string, mixed>  $layout  validated */
    public static function saveDraft(array $layout): void
    {
        $settings = app(SiteSettingsRepository::class);

        if ($layout == self::published()) {
            $settings->forget(AppearanceSettings::SUMMARY_DRAFT_KEY);
        } else {
            $settings->set(AppearanceSettings::SUMMARY_DRAFT_KEY, (string) json_encode($layout, JSON_THROW_ON_ERROR));
        }

        self::log('draft');
    }

    /** @param  array<string, mixed>  $layout  validated */
    public static function publish(array $layout): void
    {
        $settings = app(SiteSettingsRepository::class);
        app(ProductSummaryCompiler::class)->publish($layout, $settings);
        $settings->forget(AppearanceSettings::SUMMARY_DRAFT_KEY);
        self::log('published');
    }

    /** «Върни публикуваното»: the draft is forgotten. */
    public static function discard(): void
    {
        app(SiteSettingsRepository::class)->forget(AppearanceSettings::SUMMARY_DRAFT_KEY);
        self::log('discarded');
    }

    /** @return array{components: list<array<string, mixed>>}|null */
    private static function stored(string $key): ?array
    {
        $json = app(SiteSettingsRepository::class)->get($key);
        $layout = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($layout)) {
            return null;
        }

        [$clean, $errors] = ProductSummaryValidator::validate($layout);

        return $errors === [] ? $clean : null;
    }

    private static function log(string $action): void
    {
        app(ActivityLogger::class)->logFieldChanged('storefront_product_summary', 'product_summary', $action, null, 'saved');
    }
}
