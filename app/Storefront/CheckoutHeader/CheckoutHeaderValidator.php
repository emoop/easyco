<?php

namespace App\Storefront\CheckoutHeader;

use App\Storefront\Design\FieldKinds;

/**
 * Validates the simplified checkout header's values (DS-2b) against CheckoutHeaderCatalog and returns them NORMALISED (every key present, the colours lower case, an empty address `/`). It runs
 * on SAVE, on PUBLISH and again whenever the compiled copy is READ by the storefront: a hand-edited row cannot print an unsafe link, a bad number or an unknown setting; such a value is
 * simply refused (the shop then keeps its full header).
 *
 * @phpstan-type Result array{0: array<string, mixed>, 1: array<string, string>}
 */
final class CheckoutHeaderValidator
{
    /** @return array{0: array<string, mixed>, 1: array<string, string>} the normalised values and the messages by field */
    public static function validate(mixed $values): array
    {
        $errors = [];
        $fail = static function (string $path, string $code, array $params = []) use (&$errors): void {
            $errors[$path] ??= (string) __('header.errors.'.$code, $params);
        };

        if (! is_array($values)) {
            $fail('values', 'bad_element');

            return [CheckoutHeaderCatalog::defaults(), $errors];
        }

        $fields = CheckoutHeaderCatalog::fields();

        foreach (array_diff(array_keys($values), array_keys($fields)) as $key) {
            $fail((string) $key, 'unknown_setting', ['key' => mb_substr((string) $key, 0, 30)]);
        }

        $out = [];

        foreach ($fields as $name => $spec) {
            $value = array_key_exists($name, $values) ? $values[$name] : $spec['default'];
            $clean = FieldKinds::validate($value, $spec, $name, $fail);

            if ($name === 'back_url' && $clean === '') {
                $clean = '/';
            }

            // The phone kind alone lets a number through when no country is named, so the rule here is the strict one: an INTERNATIONAL number (`+` or `00`, then digits, spaces, dashes,
            // dots and brackets), 7 to 15 digits. It is what the `tel:` link and the printed text can safely hold.
            if ($name === 'phone' && is_string($clean) && $clean !== '' && ! self::isInternationalNumber($clean)) {
                $fail($name, 'bad_phone');
                $clean = '';
            }

            if (in_array($name, ['bg', 'fg'], true) && is_string($clean) && strlen($clean) === 4) {
                $clean = '#'.$clean[1].$clean[1].$clean[2].$clean[2].$clean[3].$clean[3];
            }

            // LG-1: an empty picture field is not stored (a checkout header without a logo picture is what it was)
            if ($clean === null && ($spec['kind'] ?? null) === 'media') {
                continue;
            }

            $out[$name] = $clean;
        }

        return [$out, $errors];
    }

    private static function isInternationalNumber(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return preg_match('/^(?:\+|00)[0-9][0-9 ().\-]{5,30}$/', $phone) === 1 && strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    /** Is this stored / compiled value valid? Used by the storefront's read, which wants nothing but a yes. */
    public static function passes(mixed $values): bool
    {
        return self::validate($values)[1] === [];
    }
}
