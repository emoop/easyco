<?php

namespace App\Mail\Transports;

/**
 * One input of a mail transport's settings form, described as data so the admin page can render any
 * transport without knowing which one it is (mail-design.md §2: an API provider is one more registry entry).
 */
final class TransportField
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const SELECT = 'select';

    /** Write-only: never rendered back, stored encrypted (Crypt), an empty submit keeps the stored value. */
    public const SECRET = 'secret';

    /**
     * @param string                $name       form field name, unique across transports
     * @param string                $settingKey the site_settings key it is stored under
     * @param string                $label      lang key under `mail.`
     * @param array<string, string> $options    SELECT only: value => lang key under `mail.`
     * @param list<string>          $rules      Laravel validation rules
     */
    public function __construct(
        public readonly string $name,
        public readonly string $settingKey,
        public readonly string $kind,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly ?string $default = null,
        public readonly array $options = [],
        public readonly array $rules = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {
    }

    public function isSecret(): bool
    {
        return $this->kind === self::SECRET;
    }
}
