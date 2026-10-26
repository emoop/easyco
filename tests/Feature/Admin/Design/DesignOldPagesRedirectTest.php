<?php

namespace Tests\Feature\Admin\Design;

use App\Filament\Pages\Design\DesignScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * DS-3: the old admin pages «Хедър» (`/admin/header-settings`) and «Футър» (`/admin/footer`) are gone. Their urls redirect (302) to the «Дизайн» screen with the zone selected (`?zone=`, validated
 * against the zones of the screen), behind the panel's login and the screen's own `content_manage` check; the navigation does not list them any more.
 */
class DesignOldPagesRedirectTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->withoutVite();
        app()->setLocale('en');
    }

    public function test_both_old_urls_redirect_with_a_302_to_the_screen_on_the_right_zone(): void
    {
        $this->actingAsStaff('Administrator');

        foreach (['/admin/header-settings' => 'header', '/admin/footer' => 'footer'] as $old => $zone) {
            $response = $this->get($old);

            $response->assertStatus(302)->assertRedirect(DesignScreen::getUrl(['zone' => $zone]));
            $this->assertStringEndsWith('/admin/design?zone='.$zone, (string) $response->headers->get('Location'));
        }
    }

    public function test_the_screen_opens_on_the_zone_of_the_url_and_ignores_an_unknown_one(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::withQueryParams(['zone' => 'footer'])->test(DesignScreen::class)->assertSet('zone', 'footer');
        Livewire::withQueryParams(['zone' => 'checkout'])->test(DesignScreen::class)->assertSet('zone', 'checkout');
        Livewire::withQueryParams(['zone' => 'nope'])->test(DesignScreen::class)->assertSet('zone', 'header');
        Livewire::withQueryParams(['zone' => ['footer']])->test(DesignScreen::class)->assertSet('zone', 'header');
        Livewire::test(DesignScreen::class)->assertSet('zone', 'header');
    }

    public function test_the_followed_redirect_lands_on_the_screen(): void
    {
        $this->actingAsStaff('Administrator');

        $this->followingRedirects()->get('/admin/footer')->assertOk()->assertSee('Footer');
    }

    public function test_a_role_without_the_permission_gets_the_403_the_old_pages_gave_and_a_guest_the_login(): void
    {
        $this->actingAsStaff('Product Entry');

        foreach (['/admin/header-settings', '/admin/footer'] as $old) {
            $this->get($old)->assertForbidden();
        }

        auth('staff')->logout();
        $this->app['auth']->forgetGuards();

        foreach (['/admin/header-settings', '/admin/footer'] as $old) {
            $this->get($old)->assertRedirect('/admin/login');
        }
    }

    public function test_the_navigation_lists_the_screen_and_no_old_entry(): void
    {
        $this->actingAsStaff('Administrator');
        $html = $this->get(DesignScreen::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('href="'.DesignScreen::getUrl().'"', $html);
        $this->assertStringNotContainsString('/admin/header-settings', $html);
        $this->assertStringNotContainsString('/admin/footer', $html);
        $this->assertFalse(class_exists(\App\Filament\Pages\Settings\HeaderSettings::class));
        $this->assertFalse(class_exists(\App\Filament\Pages\Design\Footer::class));
    }
}
