<?php

namespace Tests\Feature\Privacy;

use App\Filament\NavigationGroup;
use App\Filament\Pages\PrivacyLog;
use App\Filament\Pages\Settings\PrivacySettingsPage;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * ACC-4f: one «Поверителност» menu: the forget log first, the settings second, the full page title as the tooltip of each item, the URLs as they were.
 */
class PrivacyMenuTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    /** ★ */
    public function test_both_pages_sit_in_the_privacy_group_in_order_with_the_same_permission(): void
    {
        $this->assertSame(NavigationGroup::PRIVACY, PrivacyLog::getNavigationGroup());
        $this->assertSame(NavigationGroup::PRIVACY, PrivacySettingsPage::getNavigationGroup());
        $this->assertLessThan(PrivacySettingsPage::getNavigationSort(), PrivacyLog::getNavigationSort(), 'the log first, the settings second');

        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);
        $this->assertFalse(PrivacyLog::canAccess());
        $this->assertFalse(PrivacySettingsPage::canAccess());
        $this->actingAsStaff('Administrator');
        $this->assertTrue(PrivacyLog::canAccess());
        $this->assertTrue(PrivacySettingsPage::canAccess());
    }

    public function test_the_group_label_does_not_depend_on_the_locale(): void
    {
        foreach (['bg', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(__('navigation.groups.privacy'), NavigationGroup::PRIVACY->getLabel(), $locale);
        }
        $this->assertSame('Поверителност', __('navigation.groups.privacy', [], 'bg'));
    }

    public function test_the_urls_are_unchanged(): void
    {
        $this->assertStringEndsWith('/admin/privacy-log', PrivacyLog::getUrl());
        $this->assertStringEndsWith('/admin/privacy-settings', PrivacySettingsPage::getUrl());
        $this->actingAsStaff('Administrator');
        $this->get('/admin/privacy-log')->assertOk();
        $this->get('/admin/privacy-settings')->assertOk();
    }

    /** ★ */
    public function test_each_item_carries_its_full_page_title_as_the_tooltip(): void
    {
        foreach (['bg', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertSame(__('privacy_admin.log.title'), PrivacyLog::getNavigationItems()[0]->getExtraAttributes()['title']);
            $this->assertSame(__('privacy_admin.settings.title'), PrivacySettingsPage::getNavigationItems()[0]->getExtraAttributes()['title']);
        }

        app()->setLocale('bg');
        $this->assertSame('Дневник на забравените', __('privacy_admin.log.navigation_label'));
        $this->assertSame('Настройки', __('privacy_admin.settings.navigation_label'));
        $this->assertSame('Настройки за поверителност', __('privacy_admin.settings.title'));

        $this->actingAsStaff('Administrator');
        $html = (string) $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('title="Дневник на забравените"', $html);
        $this->assertStringContainsString('title="Настройки за поверителност"', $html);
    }
}
