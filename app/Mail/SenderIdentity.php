<?php

namespace App\Mail;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation of the sender identities and Reply-To (mail-design.md §3, §10). One place, used by the admin page:
 * a single RFC address (MailHeader::address: no commas, no CR/LF, ≤ 254) and a plain-text display name of at most
 * 80 characters with control and bidi characters REFUSED (not silently stripped). MailConfigurator still runs every
 * stored value through MailHeader again at send time — two barriers.
 */
final class SenderIdentity
{
    public const MAX_NAME = 80;

    public static function addressRule(): ValidationRule
    {
        return self::rule(fn (string $value): ?string => MailHeader::address($value) === null ? 'mail.validation.address' : null);
    }

    public static function nameRule(): ValidationRule
    {
        return self::rule(function (string $value): ?string {
            if (! MailHeader::isPlain($value)) {
                return 'mail.validation.name_plain';
            }

            return mb_strlen($value) > self::MAX_NAME ? 'mail.validation.name_length' : null;
        });
    }

    /** The domain of a single valid address, lower-cased, or null. */
    public static function domainOf(string $address): ?string
    {
        $address = MailHeader::address($address);

        return $address === null ? null : strtolower(substr($address, strrpos($address, '@') + 1));
    }

    /** @param Closure(string): ?string $check returns a lang key when the value is refused */
    private static function rule(Closure $check): ValidationRule
    {
        return new class($check) implements ValidationRule {
            public function __construct(private readonly Closure $check)
            {
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (! is_string($value)) {
                    $fail(__('mail.validation.address'));

                    return;
                }

                $key = ($this->check)($value);

                if ($key !== null) {
                    // The value itself is never put in the message.
                    $fail(__($key));
                }
            }
        };
    }
}
