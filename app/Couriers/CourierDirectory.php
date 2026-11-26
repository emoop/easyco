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
