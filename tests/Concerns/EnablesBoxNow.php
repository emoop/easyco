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

    protected function boxNowFixture(): string
    {
        return (string) file_get_contents(base_path('packages/EasyCo/CourierBoxNow/tests/fixtures/apms_bg-BG.sample.json'));
    }
}
