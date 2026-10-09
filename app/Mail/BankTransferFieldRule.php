<?php

namespace App\Mail;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The three field rules of the bank-transfer settings (mail-design.md §6.1.1), as a rule OBJECT: Filament
 * evaluates a closure handed to ->rules() with its own dependency injection, which cannot supply Laravel's
 * ($attribute, $value, $fail). Messages never repeat the submitted value (an IBAN is never echoed).
 */
final class BankTransferFieldRule implements ValidationRule
{
    public const IBAN = 'iban';

    public const BIC = 'bic';

    /** Plain text that may span lines. */
    public const MULTILINE = 'multiline';

    public function __construct(private readonly string $kind)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ($this->kind !== self::MULTILINE && trim($value) === '')) {
            return;
        }

        match ($this->kind) {
            self::IBAN => Iban::isValid($value) || $fail(__('mail.bank_transfer.iban_invalid')),
            self::BIC => preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper((string) preg_replace('/\s+/', '', $value))) === 1 || $fail(__('mail.bank_transfer.bic_invalid')),
            // Newline (CRLF counts) and tab are allowed; every other control character and the bidi controls are refused.
            self::MULTILINE => preg_match('/[\x00-\x1F\x7F-\x9F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', str_replace(["\r\n", "\r", "\n", "\t"], '', $value)) === 0 || $fail(__('validation.plain_text')),
        };
    }
}
