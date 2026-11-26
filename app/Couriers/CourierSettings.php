<?php

namespace App\Couriers;

use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Carrier\CourierCredentials;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierField;
use EasyCo\Shipping\Contracts\CourierDescriptor;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * The courier settings vault (courier-integration-design.md §5, §10.1), one place that knows the keys:
 *
 *   couriers.<code>.enabled            '1' or absent (default OFF)
 *   couriers.<code>.enabled_at         ISO instant the courier was last switched on (the stale-sync clock for a never-run courier)
 *   couriers.<code>.environment        test | production (absent = test)
 *   couriers.<code>.map_enabled        '1' or absent (the checkout map, used from COUR-2b)
 *   couriers.<code>.<env>.<field>      a per-environment field; a SECRET is ENCRYPTED with Crypt (APP_KEY)
 *
 * TEST AND PRODUCTION ARE SEPARATE KEYS, so switching the environment never sends one world's credentials to the other.
 * A secret leaves this class only inside a CourierCredentials (never printable), built at the moment of a call.
 * Same write-only/encrypted pattern as MailConfigurator (mail-design.md §2.1).
 */
final class CourierSettings
{
    public const ENABLED = 'enabled';

    public const ENABLED_AT = 'enabled_at';

    public const ENVIRONMENT = 'environment';

    public const MAP_ENABLED = 'map_enabled';

    public function __construct(private readonly SiteSettingsRepository $settings) {}

    public function key(string $code, string $name, ?CourierEnvironment $environment = null): string
    {
        return $environment === null ? "couriers.{$code}.{$name}" : "couriers.{$code}.{$environment->value}.{$name}";
    }

    /** The key of a field: per-environment fields carry the environment. */
    public function fieldKey(string $code, CourierField $field, CourierEnvironment $environment): string
    {
        return $this->key($code, $field->name, $field->perEnvironment ? $environment : null);
    }

    public function isEnabled(string $code): bool
    {
        return $this->settings->get($this->key($code, self::ENABLED)) === '1';
    }

    public function mapEnabled(string $code): bool
    {
        return $this->settings->get($this->key($code, self::MAP_ENABLED)) === '1';
    }

    public function environment(string $code): CourierEnvironment
    {
        return CourierEnvironment::fromStored($this->settings->get($this->key($code, self::ENVIRONMENT)));
    }

    /**
     * The non-secret values of one environment, read from the stored keys. A HOST value that is not on its allowlist is
     * dropped here (a tampered row can never become an outbound host).
     *
     * @return array<string, string>
     */
    public function plainValues(CourierDescriptor $descriptor, CourierEnvironment $environment): array
    {
        $values = [];

        foreach ($descriptor->fields() as $field) {
            if ($field->isSecret()) {
                continue;
            }

            $raw = trim((string) $this->settings->get($this->fieldKey($descriptor->code(), $field, $environment)));

            if ($raw === '') {
                continue;
            }

            if ($field->kind === CourierField::HOST) {
                $raw = (string) $field->cleanHost($raw);
            }

            if ($raw !== '') {
                $values[$field->name] = $raw;
            }
        }

        return $values;
    }

    /** `none`, `saved` or `unreadable` (APP_KEY changed); never the value. */
    public function secretState(string $code, CourierField $field, CourierEnvironment $environment): string
    {
        try {
            return $this->secret($code, $field, $environment) === null ? 'none' : 'saved';
        } catch (CourierSecretUnreadableException) {
            return 'unreadable';
        }
    }

    /**
     * Everything the adapter needs for the courier's CURRENT environment, secrets decrypted. Call it inside the job or the
     * action that is about to use it, never at boot.
     *
     * @param  bool  $tolerateUnreadable  leave a secret that cannot be decrypted out of the credentials instead of throwing
     *                                    (for a call that does not need it, e.g. a public list: the adapter that does need
     *                                    it then reports `not_configured` by itself)
     *
     * @throws CourierSecretUnreadableException
     */
    public function credentials(CourierDescriptor $descriptor, bool $tolerateUnreadable = false): CourierCredentials
    {
        $environment = $this->environment($descriptor->code());
        $values = $this->plainValues($descriptor, $environment);

        foreach ($descriptor->fields() as $field) {
            if ($field->isSecret()) {
                try {
                    $secret = $this->secret($descriptor->code(), $field, $environment);
                } catch (CourierSecretUnreadableException $e) {
                    if (! $tolerateUnreadable) {
                        throw $e;
                    }

                    $secret = null;
                }

                if ($secret !== null) {
                    $values[$field->name] = $secret;
                }
            }
        }

        return new CourierCredentials($descriptor->code(), $environment, $values);
    }

    /** @throws CourierSecretUnreadableException */
    private function secret(string $code, CourierField $field, CourierEnvironment $environment): ?string
    {
        $stored = trim((string) $this->settings->get($this->fieldKey($code, $field, $environment)));

        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            throw new CourierSecretUnreadableException;
        }
    }
}
