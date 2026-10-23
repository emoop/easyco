<?php

namespace App\Storefront\Footer;

use App\Storefront\Header\HeaderCatalog;
use EasyCo\Extensibility\Hook;
use Illuminate\Support\Facades\Log;

/**
 * The blocks a footer column may hold (FT-1): a CLOSED list, each with its own validated settings and its view. Extensions add blocks through the filter
 * `storefront.footer.blocks` (same pattern as `storefront.header.elements`): a built-in type must come back unchanged (otherwise the whole filter result is ignored and logged
 * once); an extension block is accepted only with a well-formed descriptor and a schema of the simple kinds (int, enum, text, url, bool, textarea).
 *
 * Setting kinds of the built-ins: `text`, `textarea` (plain lines), `enum`, `email`, `menu` (the id of a menu or null = the menu of the footer location), `html_block` (an id),
 * `social` (the header's closed list of networks, https only) and `payments` (a subset of PAYMENTS).
 */
final class FooterCatalog
{
    public const HOOK = 'storefront.footer.blocks';

    /** The payment marks, a closed list (also the order the editor offers). */
    public const PAYMENTS = ['visa', 'mastercard', 'maestro', 'apple_pay', 'google_pay', 'paypal', 'bank_transfer', 'cash_on_delivery'];

    /** DS-1: the options this catalog adds to a field's spec for FieldKinds: an int fails with `bad_choice` (no params), a list that is too long with `too_many_social`. */
    public const KIND_OPTIONS = ['int_code' => 'bad_choice', 'too_many_code' => 'too_many_social'];

    /** The route that must exist for the newsletter block to print anything. */
    public const NEWSLETTER_ROUTE = 'storefront.newsletter.subscribe';

    private static bool $warned = false;

    /** @return array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}> */
    public static function builtin(): array
    {
        $types = [
            'menu' => ['menu' => ['kind' => 'menu', 'default' => null], 'heading' => ['kind' => 'text', 'max' => 60, 'default' => '']],
            'html_block' => ['html_block' => ['kind' => 'html_block', 'default' => null, 'required' => true]],
            'text' => ['heading' => ['kind' => 'text', 'max' => 60, 'default' => ''], 'body' => ['kind' => 'textarea', 'max' => 400, 'default' => '']],
            'newsletter' => [
                'heading' => ['kind' => 'text', 'max' => 60, 'default' => ''], 'placeholder' => ['kind' => 'text', 'max' => 60, 'default' => ''],
                'button' => ['kind' => 'text', 'max' => 30, 'default' => ''], 'consent' => ['kind' => 'text', 'max' => 200, 'default' => ''],
            ],
            'social' => ['items' => ['kind' => 'social', 'max' => 8, 'default' => []]],
            'payments' => ['methods' => ['kind' => 'payments', 'default' => []]],
            'contacts' => [
                'phone' => ['kind' => 'text', 'max' => 40, 'default' => ''], 'email' => ['kind' => 'email', 'max' => 120, 'default' => ''],
                'address' => ['kind' => 'textarea', 'max' => 200, 'default' => ''], 'hours' => ['kind' => 'textarea', 'max' => 200, 'default' => ''],
            ],
            'spacer' => ['size' => ['kind' => 'enum', 'options' => ['s', 'm', 'l'], 'default' => 'm']],
        ];
        $out = [];

        foreach ($types as $type => $schema) {
            $out[$type] = ['label' => 'footer.blocks.'.$type, 'schema' => $schema, 'view' => 'storefront.footer.'.$type, 'builtin' => true];
        }

        return $out;
    }

    /** @return array<string, array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}> */
    public static function all(): array
    {
        $builtin = self::builtin();
        $filtered = Hook::apply(self::HOOK, $builtin);

        if (! is_array($filtered)) {
            return $builtin;
        }

        foreach ($builtin as $type => $descriptor) {
            if (! isset($filtered[$type]) || $filtered[$type] != $descriptor) {
                self::warnOnce('a filter tried to replace or remove the built-in block "'.$type.'"');

                return $builtin;
            }
        }

        $catalog = $builtin;

        foreach ($filtered as $type => $descriptor) {
            if (isset($builtin[$type])) {
                continue;
            }

            $clean = self::cleanExtension($type, $descriptor);

            if ($clean === null) {
                self::warnOnce('an extension block "'.mb_substr((string) $type, 0, 30).'" is not valid and was skipped');

                continue;
            }

            $catalog[$type] = $clean;
        }

        return $catalog;
    }

    /** @return array{label: string, schema: array<string, array<string, mixed>>, view: string, builtin: bool}|null */
    private static function cleanExtension(mixed $type, mixed $descriptor): ?array
    {
        if (! is_string($type) || preg_match('/^[a-z][a-z0-9_]{1,30}$/', $type) !== 1 || ! is_array($descriptor)) {
            return null;
        }

        $label = $descriptor['label'] ?? null;
        $view = $descriptor['view'] ?? null;
        $schema = $descriptor['schema'] ?? null;

        if (! is_string($label) || $label === '' || mb_strlen($label) > 80 || ! is_string($view) || preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $view) !== 1 || ! is_array($schema)) {
            return null;
        }

        $clean = [];

        foreach ($schema as $name => $field) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) !== 1 || ! is_array($field) || ! in_array($field['kind'] ?? null, ['int', 'enum', 'text', 'textarea', 'url', 'bool'], true)) {
                return null;
            }

            $clean[$name] = $field;
        }

        return ['label' => $label, 'schema' => $clean, 'view' => $view, 'builtin' => false];
    }

    private static function warnOnce(string $message): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;
        Log::warning('storefront.footer.blocks: '.$message);
    }

    /** Forgets the "logged once" flag (tests only). */
    public static function forgetWarnings(): void
    {
        self::$warned = false;
    }

    /** The defaults of one type's settings. @return array<string, mixed> */
    public static function defaultsFor(string $type): array
    {
        $defaults = [];

        foreach ((self::all()[$type]['schema'] ?? []) as $name => $field) {
            $defaults[$name] = $field['default'] ?? ($field['kind'] === 'bool' ? false : ($field['kind'] === 'int' ? ($field['min'] ?? 0) : ''));
        }

        return $defaults;
    }

    /** The networks of the social block: the header's closed list. @return list<string> */
    public static function networks(): array
    {
        return HeaderCatalog::SOCIAL_NETWORKS;
    }
}
