<?php

namespace Tests\Feature;

use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Site Settings' first real consumer (site-settings-design.md). Exercised
 * against a real 'web'-group route (routes/web.php's own '/') and the
 * real admin panel login page — confirming both of the two genuinely
 * separate pipelines this middleware had to be registered in (see
 * ApplyStoreLocale's own docblock) actually apply the setting.
 */
class ApplyStoreLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_locale_middleware_applies_the_stored_setting(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'en');

        $this->get('/');

        $this->assertSame('en', App::getLocale());

        // The admin panel is a genuinely separate middleware pipeline
        // (confirmed directly against the installed Filament v5.8.1
        // source — panel routes never run through Laravel's global
        // 'web' group) — proving the setting is applied there too, not
        // just on a plain 'web'-group route.
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');

        $this->get('/admin/login');

        $this->assertSame('bg', App::getLocale());
    }

    public function test_the_locale_middleware_falls_back_to_the_config_default_when_unset(): void
    {
        config(['app.locale' => 'bg']);

        $this->get('/');

        $this->assertSame('bg', App::getLocale());
    }
}
