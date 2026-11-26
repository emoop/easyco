<?php

namespace Tests\Concerns;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\Crypt;

/** Switches the installed BOX NOW courier on with FAKE values (never a real credential) for the courier tests. */
trait EnablesBoxNow
{
    protected const FAKE_API_HOST = 'api-stage.boxnow.bg';

    protected const FAKE_SECRET = 'fake-client-secret-for-tests-only';

    protected function enableBoxNow(string $environment = 'test', bool $withCredentials = true): void
    {
        $settings = app(SiteSettingsRepository::class);

        $settings->set('couriers.boxnow.enabled', '1');
        $settings->set('couriers.boxnow.environment', $environment);

        if ($withCredentials) {
            $settings->set("couriers.boxnow.{$environment}.api_host", self::FAKE_API_HOST);
            $settings->set("couriers.boxnow.{$environment}.client_id", 'fake-client-id');
            $settings->set("couriers.boxnow.{$environment}.client_secret", Crypt::encryptString(self::FAKE_SECRET));
        }
    }

    /** Switches Econt or Speedy on with FAKE credentials (never a real one) in the TEST environment. */
    protected function enableCourier(string $code, string $user = 'fake-user-for-tests', string $password = 'fake-password-for-tests'): void
    {
        $settings = app(SiteSettingsRepository::class);

        $settings->set("couriers.{$code}.enabled", '1');
        $settings->set("couriers.{$code}.environment", 'test');
        $settings->set("couriers.{$code}.test.username", $user);
        $settings->set("couriers.{$code}.test.password", Crypt::encryptString($password));
    }

    protected function courierFixture(string $package, string $file): string
    {
        return (string) file_get_contents(base_path("packages/EasyCo/{$package}/tests/fixtures/{$file}"));
    }

    protected function boxNowFixture(): string
    {
        return (string) file_get_contents(base_path('packages/EasyCo/CourierBoxNow/tests/fixtures/apms_bg-BG.sample.json'));
    }
}
