<?php

namespace App\Storefront\CheckoutHeader;

use App\Settings\AppearanceSettings;
use App\Settings\Contracts\SiteSettingsRepository;
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

        return $errors === [] ? $clean : null;
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
