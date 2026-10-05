<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\StaffPanelUser;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin top bar's two actions: Filament's own light/dark/system theme
 * switcher, and the "View store" link out to the storefront. Both are
 * deliberately in the top bar itself, immediately before the user avatar —
 * Filament renders that switcher inside the user-menu dropdown by default,
 * which is exactly what AdminPanelProvider's ->themeSwitcher(false) +
 * ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, ...) pair changes.
 *
 * Real HTTP GETs against a real panel page (the OrderResource list), like
 * NavigationGroupingTest and RoleResourceTest, rather than Livewire::test():
 * only a real panel request renders Filament's own layout — and so runs the
 * render hook this task added — and only a real request runs this panel's
 * middleware pipeline (ApplyStoreLocale), which the Bulgarian assertion
 * below depends on. Those two tests' discovered gotcha applies here too: a
 * plain StaffModel fails Filament\Http\Middleware\Authenticate's
 * FilamentUser check with a blanket 403 regardless of permissions, so it is
 * the panel-backed StaffPanelUser instance that must be actingAs()'d.
 */
class AdminTopbarActionsTest extends TestCase
{
    use RefreshDatabase;
    private function actingAsPanelAdministrator(): void
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName('Administrator');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName('Administrator');
        }

        $staff = Staff::create(
            'topbar-administrator@example.com',
            app(PasswordHasher::class)->hash('password123'),
            'Topbar Administrator',
            $role,
        );
        app(StaffRepository::class)->save($staff);

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        // The same reset RoleResourceTest performs before a real panel GET:
        // Filament's AuthenticateSession middleware compares against
        // whichever password hash the session already carries.
        session()->forget('password_hash_staff');
    }

    private function adminPanelHtml(string $locale): string
    {
        // The store's own locale reader, applied by ApplyStoreLocale on this
        // panel's middleware stack — the real pipeline, not App::setLocale().
        app(SiteSettingsRepository::class)->set('site.locale', $locale);

        $this->actingAsPanelAdministrator();

        $response = $this->get(OrderResource::getUrl('index'));

        $response->assertOk();

        return $response->getContent();
    }

    /**
     * The top bar's own right-hand cluster (topbar.blade.php's
     * .fi-topbar-end), which holds the user menu. Everything this task added
     * is asserted inside this slice, so a passing assertion cannot be
     * satisfied by markup that rendered somewhere else on the page.
     */
    private function topbarEndRegion(string $html): string
    {
        $start = strpos($html, 'class="fi-topbar-end"');

        $this->assertNotFalse($start, 'The panel top bar rendered no .fi-topbar-end region.');

        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_the_top_bar_offers_all_three_theme_choices_immediately_before_the_user_avatar(): void
    {
        $region = $this->topbarEndRegion($this->adminPanelHtml('en'));

        $this->assertStringContainsString('class="fi-theme-switcher"', $region);

        // All three choices, asserted through Filament's own translated
        // button labels rather than through our own markup.
        foreach (['light', 'dark', 'system'] as $theme) {
            $this->assertStringContainsString(
                'aria-label="'.__("filament-panels::layout.actions.theme_switcher.{$theme}.label").'"',
                $region,
                "The top bar's theme switcher was expected to offer the \"{$theme}\" choice.",
            );
        }

        $this->assertSame(3, substr_count($region, 'class="fi-theme-switcher-btn"'));

        // Filament's switcher keeps the choice in localStorage and applies it
        // through the window-level 'theme-changed' event dark-mode.js listens
        // for — asserted, not assumed, because that client-side persistence
        // is what lets this ship with no server-side theme setting of ours.
        $this->assertStringContainsString("localStorage.getItem('theme')", $region);
        $this->assertStringContainsString('theme-changed', $region);

        $userMenuPosition = strpos($region, 'fi-user-menu');

        $this->assertNotFalse($userMenuPosition, 'The panel top bar rendered no user menu.');

        $this->assertLessThan(
            $userMenuPosition,
            strpos($region, 'class="fi-theme-switcher"'),
            'The theme switcher was expected before the user avatar, not after it.',
        );
    }

    public function test_the_theme_switcher_renders_exactly_once_because_its_user_menu_copy_is_switched_off(): void
    {
        $html = $this->adminPanelHtml('en');

        // The panel-level flag AdminPanelProvider sets, asserted directly so
        // that turning it back on fails here with an explanation, rather than
        // only as a mystery duplicate in the avatar dropdown.
        $this->assertFalse(Filament::getPanel('admin')->hasThemeSwitcher());

        // Whole page: one switcher, three buttons. Filament's own
        // user-menu.blade.php copy is the duplication this rules out.
        $this->assertSame(1, substr_count($html, 'class="fi-theme-switcher"'));
        $this->assertSame(3, substr_count($html, 'class="fi-theme-switcher-btn"'));

        // And the one copy really is the top bar's.
        $this->assertStringContainsString('class="fi-theme-switcher"', $this->topbarEndRegion($html));
    }

    public function test_the_view_store_link_opens_the_storefront_home_in_a_new_tab_and_is_labelled(): void
    {
        $region = $this->topbarEndRegion($this->adminPanelHtml('en'));

        // / is the storefront's own home URL (storefront-frontend-design.md
        // §7: `GET / → HomeController@index`). No named storefront route
        // exists yet — the storefront is not built — so url('/') is the
        // address it will occupy, not a guess at a route name, and the
        // sandbox is explicitly not the storefront.
        $this->assertStringContainsString('href="'.url('/').'"', $region);
        $this->assertStringNotContainsString('/_sandbox', $region);

        $this->assertStringContainsString('target="_blank"', $region);

        // generate_href_html() emits target="_blank" but no rel (verified in
        // filament/support's helpers.php), so the rel the partial passes
        // explicitly is the only thing keeping the new tab from reaching
        // back into this panel through window.opener.
        $this->assertStringContainsString('rel="noopener noreferrer"', $region);

        $label = __('navigation.topbar.view_store', [], 'en');

        $this->assertSame('View store', $label);
        $this->assertStringContainsString('aria-label="'.$label.'"', $region);
        $this->assertStringContainsString('title="'.$label.'"', $region);

        // An icon button, as the top bar's own controls are — and an icon,
        // not just a label: the <a> must actually wrap an <svg>.
        $this->assertStringContainsString('class="fi-icon-btn', $region);

        $linkStart = strpos($region, 'rel="noopener noreferrer"');
        $linkHtml = substr($region, $linkStart, strpos($region, '</a>', $linkStart) - $linkStart);

        $this->assertStringContainsString('<svg', $linkHtml);

        // rel="noopener noreferrer" appears nowhere else in the top bar, so
        // it is an unambiguous marker of this link's position.
        $this->assertLessThan(
            strpos($region, 'fi-user-menu'),
            strpos($region, 'rel="noopener noreferrer"'),
            'The "View store" link was expected before the user avatar, not after it.',
        );
    }

    public function test_the_view_store_link_follows_the_merchants_configured_locale(): void
    {
        $region = $this->topbarEndRegion($this->adminPanelHtml('bg'));

        $label = __('navigation.topbar.view_store', [], 'bg');

        $this->assertSame('Към магазина', $label);
        $this->assertStringContainsString('aria-label="'.$label.'"', $region);
        $this->assertStringContainsString('title="'.$label.'"', $region);

        // The link itself is locale-independent.
        $this->assertStringContainsString('href="'.url('/').'"', $region);
    }
}
