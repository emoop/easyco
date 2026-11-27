<?php

namespace App\Couriers;

use EasyCo\Shipping\Carrier\CarrierRegistration;
use EasyCo\Shipping\Carrier\CarrierRegistry;
use EasyCo\Shipping\Contracts\CourierDescriptor;
use Illuminate\Contracts\Container\Container;

/**
 * Which couriers are INSTALLED (registered in the CarrierRegistry with a CourierDescriptor) and which are ENABLED
 * (courier-integration-design.md §2.2): registration is installed, `couriers.<code>.enabled` is enabled, default off.
 *
 * A carrier registered WITHOUT a descriptor (an extension that is not an integrated courier) has no settings page and no
 * way to be switched on or off, so it is not gated: isCallable() is true for it, exactly as before this stage.
 */
final class CourierDirectory
{
    public function __construct(
        private readonly CarrierRegistry $registry,
        private readonly Container $container,
        private readonly CourierSettings $settings,
    ) {}

    public function descriptor(string $code): ?CourierDescriptor
    {
        $key = CarrierRegistration::descriptorKey($code);

        if (! $this->registry->has($code) || ! $this->container->bound($key)) {
            return null;
        }

        $descriptor = $this->container->make($key);

        return $descriptor instanceof CourierDescriptor ? $descriptor : null;
    }

    /** @return list<CourierDescriptor> the installed couriers, in registration order */
    public function installed(): array
    {
        $descriptors = [];

        foreach ($this->registry->all() as $registration) {
            $descriptor = $this->descriptor($registration->code);

            if ($descriptor !== null) {
                $descriptors[] = $descriptor;
            }
        }

        return $descriptors;
    }

    /** @return list<CourierDescriptor> installed AND switched on */
    public function enabled(): array
    {
        return array_values(array_filter($this->installed(), fn (CourierDescriptor $d): bool => $this->settings->isEnabled($d->code())));
    }

    /** The name a courier is shown under in a choice (a courier without a translated name shows its code). */
    public static function label(string $code): string
    {
        $key = 'couriers.names.'.$code;
        $text = __($key);

        return $text === $key ? $code : $text;
    }

    /** The comparison key of a courier name: lower case, every run of spaces (a no-break space too) one space, trimmed. */
    public static function nameKey(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value)));
    }

    /**
     * COUR-2a.6, THE ONE MAPPING of a stored courier text to an integrated courier's CODE: the code itself, or the courier's display name in either
     * language («Спиди», «Speedy»), compared by nameKey(). Only INSTALLED couriers (registered with a descriptor) are known: for any other text there is
     * no code to give, and the text is returned unchanged (a plain courier name stays what it is; no code is invented).
     */
    public function canonicalCode(?string $stored): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return $stored;
        }

        $key = self::nameKey($stored);

        foreach ($this->installed() as $descriptor) {
            $code = $descriptor->code();
            $names = [$code, (string) __('couriers.names.'.$code, [], 'bg'), (string) __('couriers.names.'.$code, [], 'en')];

            foreach ($names as $name) {
                if ($name !== '' && $name !== 'couriers.names.'.$code && self::nameKey($name) === $key) {
                    return $code;
                }
            }
        }

        return $stored;
    }

    /**
     * COUR-2a.6.1: the courier as a PERSON reads it, from whatever an order, a shipment or an address stored. An integrated courier (by canonicalCode: its code,
     * or its display name in either language) is shown under its name in `$locale` («Спиди», «Еконт», «BOX NOW»); any other text is shown as stored; null stays null.
     * Display only: what is stored is never rewritten (an order keeps its history).
     */
    public function displayName(?string $stored, ?string $locale = null): ?string
    {
        $code = $this->canonicalCode($stored);

        if ($code === null || $code === $stored && $this->descriptor($code) === null) {
            return $code;
        }

        $name = (string) __('couriers.names.'.$code, [], $locale);

        return $name !== 'couriers.names.'.$code && $name !== '' ? $name : $code;
    }

    /**
     * Every way a courier is written (COUR-2a.6.1): for an integrated courier its code and its display name in both languages; for any other text, that text.
     * What a page compares a free text against to ask «does this already name the courier?» (a method called «Speedy до офис» does).
     *
     * @return list<string>
     */
    public function aliases(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        $code = $this->canonicalCode($stored);

        if ($code === $stored && $this->descriptor($code) === null) {
            return [$stored];
        }

        return array_values(array_unique(array_filter([$code, (string) __('couriers.names.'.$code, [], 'bg'), (string) __('couriers.names.'.$code, [], 'en')], static fn (string $name): bool => $name !== '' && $name !== 'couriers.names.'.$code)));
    }

    /** Does `$text` (a method's name, say) already name the courier `$stored`? Case and spacing ignored, any alias of the courier counts. */
    public function mentions(string $text, ?string $stored): bool
    {
        $haystack = self::nameKey($text);

        foreach ($this->aliases($stored) as $alias) {
            if (self::nameKey($alias) !== '' && str_contains($haystack, self::nameKey($alias))) {
                return true;
            }
        }

        return false;
    }

    public function isEnabled(string $code): bool
    {
        return $this->descriptor($code) !== null && $this->settings->isEnabled($code);
    }

    /** False only for an installed courier that is switched off (treated by the resolver exactly as unknown). */
    public function isCallable(string $code): bool
    {
        return $this->descriptor($code) === null || $this->settings->isEnabled($code);
    }

    /**
     * The exact hosts $code may be called on right now: its declared hosts for its CURRENT environment, and none at all
     * while it is not installed or not enabled.
     *
     * @return list<string>
     */
    public function allowedHosts(string $code): array
    {
        $descriptor = $this->descriptor($code);

        if ($descriptor === null || ! $this->settings->isEnabled($code)) {
            return [];
        }

        $environment = $this->settings->environment($code);

        return array_values(array_unique(array_map(
            'strtolower',
            $descriptor->hosts($environment, $this->settings->plainValues($descriptor, $environment)),
        )));
    }
}
