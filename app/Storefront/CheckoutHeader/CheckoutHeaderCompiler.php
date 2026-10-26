<?php

namespace App\Storefront\CheckoutHeader;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Storefront\Design\LogoImage;
use App\Storefront\Header\AppearanceRecompiler;
use InvalidArgumentException;

/**
 * PUBLISH of the simplified checkout header (DS-2b): validates the values, stores them as `design.checkout_header.global.published` and as the zone's entry of `design.compiled`
 * (`{hash, zones: {checkout_header: {global: values}}}`, at most 256 KB, part of the ONE cached appearance read), recompiles the token stylesheet (the colours and logo heights reach the
 * page as CSS variables, never inline styles) and replaces the reader's cache entry. `design.compiled` is shared by every zone the Design screen stores this way: the other zones' entries are kept.
 */
final class CheckoutHeaderCompiler
{
    public const MAX_BYTES = 262_144;

    /**
     * @param  array<string, mixed>  $values
     * @return array{hash: string, values: array<string, mixed>}
     *
     * @throws InvalidArgumentException when the values do not validate
     */
    public static function compiled(array $values): array
    {
        [$clean, $errors] = CheckoutHeaderValidator::validate($values);

        if ($errors !== []) {
            throw new InvalidArgumentException('The checkout header is not valid.');
        }

        return ['hash' => substr(sha1((string) json_encode($clean)), 0, 12), 'values' => $clean];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{hash: string, values: array<string, mixed>}
     *
     * @throws InvalidArgumentException when the values do not validate or the compiled copy is too large
     */
    public function publish(array $values, SiteSettingsRepository $settings): array
    {
        $compiled = self::compiled($values);
        $document = self::document($settings->get(AppearanceSettings::DESIGN_COMPILED_KEY));
        $document['zones'][CheckoutHeaderCatalog::ZONE][CheckoutHeaderCatalog::SCOPE] = $compiled['values'];
        // LG-1: the rendition of the logo picture beside the values (not in them: the values are validated again on every read), or none
        $logo = self::logoOf($compiled['values']);
        unset($document['zones'][CheckoutHeaderCatalog::ZONE]['logo']);
        $logo !== null && $document['zones'][CheckoutHeaderCatalog::ZONE]['logo'] = $logo;
        $document['hash'] = substr(sha1((string) json_encode($document['zones'])), 0, 12);
        $json = (string) json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The compiled design is too large.');
        }

        $settings->set(AppearanceSettings::CHECKOUT_HEADER_PUBLISHED_KEY, (string) json_encode($compiled['values'], JSON_THROW_ON_ERROR));
        $settings->set(AppearanceSettings::DESIGN_COMPILED_KEY, $json);

        app(AppearanceRecompiler::class)->run($settings);

        return $compiled;
    }

    /** The stored `design.compiled` as {hash, zones}; anything that is not that is an empty document. @return array{hash: string, zones: array<string, mixed>} */
    public static function document(?string $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        $zones = is_array($data) && is_array($data['zones'] ?? null) ? $data['zones'] : [];

        return ['hash' => is_string($data['hash'] ?? null) ? $data['hash'] : '', 'zones' => $zones];
    }

    /** The values of the PUBLISHED simplified header, validated again, or null (never published, invalid). @return array<string, mixed>|null */
    public static function published(?string $json): ?array
    {
        $values = self::document($json)['zones'][CheckoutHeaderCatalog::ZONE][CheckoutHeaderCatalog::SCOPE] ?? null;

        if (! is_array($values)) {
            return null;
        }

        [$clean, $errors] = CheckoutHeaderValidator::validate($values);

        if ($errors !== []) {
            return null;
        }

        // LG-1: the compiled rendition of the logo picture, only when it is the picture the values name (no query: it is part of the one cached payload)
        $logo = self::document($json)['zones'][CheckoutHeaderCatalog::ZONE]['logo'] ?? null;

        return is_int($clean['logo_image'] ?? null) ? self::withRendition($clean, $logo) : $clean;
    }

    /**
     * LG-1: the rendition of the logo picture the values name — {id, url, width, height} — or null (no picture, or one that is missing, not ready or not a PNG / WebP: the static logo then). The widest
     * use decides the size (the desktop and the phone height).
     *
     * @param  array<string, mixed>  $values  validated
     * @return array{id: int, url: string, width: int, height: int}|null
     */
    public static function logoOf(array $values): ?array
    {
        $id = $values['logo_image'] ?? null;

        if (! is_int($id)) {
            return null;
        }

        $rendition = LogoImage::rendition($id, max((int) ($values['logo_height_d'] ?? 0), (int) ($values['logo_height_m'] ?? 0)));

        return $rendition === null ? null : ['id' => $id] + $rendition;
    }

    /** The values with `logo` ({url, width, height}) for the view: the compiled entry, when it is well formed and for THIS picture. @param  array<string, mixed>  $values  @return array<string, mixed> */
    public static function withRendition(array $values, mixed $logo): array
    {
        if (is_array($logo) && ((int) ($logo['id'] ?? 0)) === (int) ($values['logo_image'] ?? -1) && is_string($logo['url'] ?? null) && preg_match('#^(/|https?://)[^\s"<>\\\\]{1,500}$#', $logo['url']) === 1
            && is_int($logo['width'] ?? null) && is_int($logo['height'] ?? null) && $logo['width'] > 0 && $logo['height'] > 0) {
            $values['logo'] = ['url' => $logo['url'], 'width' => $logo['width'], 'height' => $logo['height']];
        }

        return $values;
    }

    /** LG-1, the PREVIEW of a draft: the draft's values with the rendition of its logo picture looked up now (a preview may query; the public page never does). @param  array<string, mixed>  $values  validated @return array<string, mixed> */
    public static function withLogo(array $values): array
    {
        return self::withRendition($values, self::logoOf($values));
    }

    /**
     * The CSS variables of a validated value, only for what differs from the storefront's own: a colour that is set, a logo height that is not the default.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public static function vars(array $values): array
    {
        $vars = [];

        foreach (['bg' => '--sf-chdr-bg', 'fg' => '--sf-chdr-fg'] as $key => $variable) {
            is_string($values[$key] ?? null) && $vars[$variable] = strtolower($values[$key]);
        }

        $defaults = CheckoutHeaderCatalog::defaults();

        foreach (['logo_height_d' => '--sf-chdr-logo-d-h', 'logo_height_m' => '--sf-chdr-logo-m-h'] as $key => $variable) {
            if (is_int($values[$key] ?? null) && $values[$key] !== $defaults[$key]) {
                $vars[$variable] = $values[$key].'px';
            }
        }

        return $vars;
    }
}
