<?php

namespace App\Mail;

use App\Rules\PlainText;
use App\Settings\Contracts\SiteSettingsRepository;

/**
 * The shop's bank-transfer instructions (mail-design.md §6.1.1, decided by the owner 2026-10-09): the
 * settings group `payment.bank_transfer.*`, read ONLY through SiteSettingsRepository.
 *
 * Plain text everywhere — nothing here is ever rendered as HTML (the mail block escapes it). The
 * settings page and this class share the keys and the rules, so the page cannot accept what the mail
 * would refuse.
 */
final class BankTransferDetails
{
    public const HOLDER = 'payment.bank_transfer.account_holder';

    public const BANK = 'payment.bank_transfer.bank_name';

    public const IBAN = 'payment.bank_transfer.iban';

    public const BIC = 'payment.bank_transfer.bic';

    public const DEADLINE_DAYS = 'payment.bank_transfer.deadline_days';

    public const INSTRUCTIONS = 'payment.bank_transfer.instructions';

    public const MAX_NAME = 120;

    public const MAX_INSTRUCTIONS = 1000;

    public function __construct(
        public readonly ?string $accountHolder,
        public readonly ?string $bankName,
        public readonly ?string $iban,
        public readonly ?string $bic,
        public readonly ?int $deadlineDays,
        public readonly ?string $instructions,
    ) {
    }

    public static function fromSettings(SiteSettingsRepository $settings): self
    {
        $text = static function (?string $value): ?string {
            $value = $value === null ? '' : trim($value);

            return $value === '' ? null : $value;
        };

        $days = $text($settings->get(self::DEADLINE_DAYS));
        $iban = $text($settings->get(self::IBAN));

        return new self(
            $text($settings->get(self::HOLDER)),
            $text($settings->get(self::BANK)),
            $iban === null ? null : Iban::normalize($iban),
            $text($settings->get(self::BIC)),
            $days !== null && ctype_digit($days) && (int) $days >= 1 && (int) $days <= 60 ? (int) $days : null,
            $text($settings->get(self::INSTRUCTIONS)),
        );
    }

    /** A block counts as present when an IBAN or instructions exist (a shop may say "we will send an invoice" in the text only). */
    public function isEmpty(): bool
    {
        return $this->iban === null && $this->instructions === null;
    }

    /**
     * The validation rules of the settings page, keyed by form field. The IBAN is checked on its normalised
     * form and never echoed in a message.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'account_holder' => ['nullable', 'string', 'max:'.self::MAX_NAME, new PlainText],
            'bank_name' => ['nullable', 'string', 'max:'.self::MAX_NAME, new PlainText],
            'iban' => ['nullable', 'string', 'max:60', new BankTransferFieldRule(BankTransferFieldRule::IBAN)],
            'bic' => ['nullable', 'string', 'max:20', new BankTransferFieldRule(BankTransferFieldRule::BIC)],
            'deadline_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'instructions' => ['nullable', 'string', 'max:'.self::MAX_INSTRUCTIONS, new BankTransferFieldRule(BankTransferFieldRule::MULTILINE)],
        ];
    }
}
