<?php

namespace Tests\Feature;

use App\Filament\NavigationGroup;
use App\Filament\Pages\ActivityLogJournal;
use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\AttributeDefinitionResource;
use App\Filament\Resources\AttributeValueResource;
use App\Filament\Resources\BrandResource;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\SeasonResource;
use App\Filament\Resources\StaffResource;
use App\Filament\Resources\TagResource;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Order\Enums\OrderStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * This task's own reorganization of the admin sidebar into two
 * top-level groups (Каталог/Admin — the domain owner's stated
 * structure), plus the two label corrections it bundled in. Mirrors
 * ApplyStoreLocaleTest's own "set site.locale via the repository, hit
 * a real request so ApplyStoreLocale actually applies it, then observe
 * the effect" pattern — not just confirming __() compiles, but that
 * the real pipeline drives these labels the same way it drives every
 * other translated string in the app.
 *
 * getNavigationGroup() itself returns the App\Filament\NavigationGroup
 * enum case, not a translated string (see that enum's own docblock for
 * the real Filament ordering gotcha this fixes) — so the group-name
 * tests below go through NavigationGroup::getLabel(), the real render
 * path, rather than calling getNavigationGroup() and comparing to a
 * string directly.
 *
 * The per-item sidebar behaviour that is just as easy to lose lives
 * here too: which group opens by default, and the badge on Поръчки
 * (the orders still waiting for a merchant decision).
 */
class NavigationGroupingTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    private function applyLocale(string $locale): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', $locale);

        $this->get('/admin/login');
    }

    public function test_catalog_group_items_report_the_real_translated_group_name_in_both_locales(): void
    {
        $catalogResources = [
            CategoryResource::class,
            TagResource::class,
            AttributeDefinitionResource::class,
            AttributeValueResource::class,
            BrandResource::class,
            SeasonResource::class,
        ];

        foreach ($catalogResources as $resource) {
            $this->assertSame(NavigationGroup::CATALOG, $resource::getNavigationGroup());
        }

        $this->applyLocale('bg');
        $this->assertSame('Каталог', NavigationGroup::CATALOG->getLabel());

        $this->applyLocale('en');
        $this->assertSame('Catalog', NavigationGroup::CATALOG->getLabel());
    }

    public function test_sales_group_items_report_the_real_translated_group_name_in_both_locales(): void
    {
        $this->assertSame(NavigationGroup::SALES, OrderResource::getNavigationGroup());

        $this->applyLocale('bg');
        $this->assertSame('Продажби', NavigationGroup::SALES->getLabel());

        $this->applyLocale('en');
        $this->assertSame('Sales', NavigationGroup::SALES->getLabel());
    }

    public function test_the_shipping_group_reports_the_real_translated_group_name_in_both_locales(): void
    {
        $this->assertSame(NavigationGroup::SHIPPING, ShippingOverview::getNavigationGroup());

        $this->applyLocale('bg');
        $this->assertSame('Доставка', NavigationGroup::SHIPPING->getLabel());

        $this->applyLocale('en');
        $this->assertSame('Shipping', NavigationGroup::SHIPPING->getLabel());
    }

    public function test_admin_group_items_report_the_real_translated_group_name_in_both_locales(): void
    {
        $this->assertSame(NavigationGroup::ADMIN, RoleResource::getNavigationGroup());
        $this->assertSame(NavigationGroup::ADMIN, StaffResource::getNavigationGroup());
        $this->assertSame(NavigationGroup::ADMIN, LocaleSettings::getNavigationGroup());

        $this->applyLocale('bg');
        $this->assertSame('Админ', NavigationGroup::ADMIN->getLabel());

        $this->applyLocale('en');
        $this->assertSame('Admin', NavigationGroup::ADMIN->getLabel());
    }

    public function test_the_groups_declare_in_sales_catalog_shipping_admin_render_order(): void
    {
        // Real Filament ordering gotcha this enum fixes (see its own
        // docblock): with no panel-registered groups, group render
        // order follows a UnitEnum's own cases() declaration order,
        // not any individual item's getNavigationSort() value. A
        // regression here would silently reorder the sidebar's own
        // top-level groups. SALES leads now — Продажби above Каталог,
        // the orders waiting for a decision being the first thing the
        // panel is opened for — and this asserts all four positions,
        // not just the ends.
        $cases = NavigationGroup::cases();
        $this->assertSame(NavigationGroup::SALES, $cases[0]);
        $this->assertSame(NavigationGroup::CATALOG, $cases[1]);
        $this->assertSame(NavigationGroup::SHIPPING, $cases[2]);
        $this->assertSame(NavigationGroup::ADMIN, $cases[3]);
    }

    public function test_the_six_catalog_group_items_sort_in_the_exact_stated_order(): void
    {
        $this->assertSame(20, CategoryResource::getNavigationSort());
        $this->assertSame(30, TagResource::getNavigationSort());
        $this->assertSame(40, AttributeDefinitionResource::getNavigationSort());
        $this->assertSame(50, AttributeValueResource::getNavigationSort());
        $this->assertSame(60, BrandResource::getNavigationSort());
        $this->assertSame(70, SeasonResource::getNavigationSort());
    }

    public function test_the_four_admin_group_items_sort_in_the_exact_stated_order(): void
    {
        $this->assertSame(10, RoleResource::getNavigationSort());
        $this->assertSame(20, StaffResource::getNavigationSort());
        $this->assertSame(30, LocaleSettings::getNavigationSort());
        // Right after Settings — this task's own stated position.
        $this->assertSame(40, ActivityLogJournal::getNavigationSort());
        $this->assertSame(NavigationGroup::ADMIN, ActivityLogJournal::getNavigationGroup());
    }

    public function test_attribute_definition_navigation_label_matches_its_own_plural_model_label_everywhere(): void
    {
        $this->applyLocale('bg');
        $this->assertSame('Атрибути', AttributeDefinitionResource::getNavigationLabel());
        $this->assertSame('Атрибути', AttributeDefinitionResource::getPluralModelLabel());

        $this->applyLocale('en');
        $this->assertSame('Attributes', AttributeDefinitionResource::getNavigationLabel());
        $this->assertSame('Attributes', AttributeDefinitionResource::getPluralModelLabel());
    }

    public function test_the_panel_registered_groups_keep_sales_open_the_rest_collapsed_and_the_translated_labels(): void
    {
        // Regression guard for NavigationGroup::navigationGroups(). The panel
        // registers these groups during service-provider boot, BEFORE
        // ApplyStoreLocale's middleware runs, so Filament's own eager
        // NavigationGroup::fromEnum() registration would freeze the default
        // locale's labels onto them for every request (observed as an English
        // "Catalog"/"Sales"/"Admin" sidebar under a Bulgarian site.locale).
        // The labels are therefore declared as lazy closures and must track
        // the active locale here, exactly like the enum cases above do —
        // while still carrying each group's own open/collapsed default that
        // registration exists to deliver in the first place.
        $groups = Filament::getPanel('admin')->getNavigationGroups();

        $this->assertFalse($groups['SALES']->isCollapsed(), 'Sales opens: the orders waiting are what the panel is opened for');
        $this->assertTrue($groups['CATALOG']->isCollapsed());
        $this->assertTrue($groups['SHIPPING']->isCollapsed());
        $this->assertTrue($groups['ADMIN']->isCollapsed());

        $this->applyLocale('bg');
        $this->assertSame('Каталог', $groups['CATALOG']->getLabel());
        $this->assertSame('Продажби', $groups['SALES']->getLabel());
        $this->assertSame('Админ', $groups['ADMIN']->getLabel());

        $this->applyLocale('en');
        $this->assertSame('Catalog', $groups['CATALOG']->getLabel());
        $this->assertSame('Sales', $groups['SALES']->getLabel());
        $this->assertSame('Admin', $groups['ADMIN']->getLabel());
    }

    public function test_attribute_value_navigation_label_is_deliberately_different_from_its_plural_model_label(): void
    {
        $this->applyLocale('bg');
        $this->assertSame('Атрибути стойности', AttributeValueResource::getNavigationLabel());
        $this->assertSame('Стойности на атрибути', AttributeValueResource::getPluralModelLabel());
        $this->assertNotSame(
            AttributeValueResource::getNavigationLabel(),
            AttributeValueResource::getPluralModelLabel(),
        );

        $this->applyLocale('en');
        $this->assertSame('Attribute Values', AttributeValueResource::getNavigationLabel());
        $this->assertSame('Attribute Values', AttributeValueResource::getPluralModelLabel());
    }

    // =====================================================================================================
    // The Поръчки badge: how many orders are still waiting for a decision
    // =====================================================================================================

    public function test_the_orders_navigation_badge_is_null_while_nothing_is_waiting_for_a_decision(): void
    {
        $this->assertNull(OrderResource::getNavigationBadge(), 'no placed order: no badge at all, rather than a 0');
    }

    public function test_the_orders_navigation_badge_counts_the_placed_orders(): void
    {
        $this->bankOrder(OrderStatus::PLACED);
        $this->bankOrder(OrderStatus::PLACED);
        $this->bankOrder(OrderStatus::PLACED);

        $this->assertSame('3', OrderResource::getNavigationBadge(), 'the count is handed over as a string');

        // Information, not severity — the panel's own rule for this badge.
        $this->assertSame('gray', OrderResource::getNavigationBadgeColor());
    }

    public function test_the_orders_navigation_badge_ignores_orders_a_merchant_has_already_moved(): void
    {
        $this->bankOrder(OrderStatus::PLACED);
        $this->bankOrder(OrderStatus::CONFIRMED);
        $this->bankOrder(OrderStatus::SHIPPED);
        $this->bankOrder(OrderStatus::CANCELLED);

        $this->assertSame('1', OrderResource::getNavigationBadge(), 'only the placed order is still waiting');
    }

    public function test_the_orders_navigation_badge_tooltip_is_translated_in_both_locales(): void
    {
        $this->applyLocale('bg');
        $this->assertSame('Приети поръчки, които още не са потвърдени', OrderResource::getNavigationBadgeTooltip());

        $this->applyLocale('en');
        $this->assertSame('Accepted orders not yet confirmed', OrderResource::getNavigationBadgeTooltip());
    }
}
