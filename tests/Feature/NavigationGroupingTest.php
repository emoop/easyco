<?php

namespace Tests\Feature;

use App\Filament\NavigationGroup;
use App\Filament\Pages\Settings\LocaleSettings;
use App\Filament\Resources\AttributeDefinitionResource;
use App\Filament\Resources\AttributeValueResource;
use App\Filament\Resources\BrandResource;
use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\SeasonResource;
use App\Filament\Resources\StaffResource;
use App\Filament\Resources\TagResource;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 */
class NavigationGroupingTest extends TestCase
{
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

    public function test_the_catalog_group_declares_before_the_admin_group_so_it_renders_first(): void
    {
        // Real Filament ordering gotcha this enum fixes (see its own
        // docblock): with no panel-registered groups, group render
        // order follows a UnitEnum's own cases() declaration order,
        // not any individual item's getNavigationSort() value. A
        // regression here would silently put Admin back above Catalog.
        $cases = NavigationGroup::cases();
        $this->assertSame(NavigationGroup::CATALOG, $cases[0]);
        $this->assertSame(NavigationGroup::ADMIN, $cases[1]);
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

    public function test_the_three_admin_group_items_sort_in_the_exact_stated_order(): void
    {
        $this->assertSame(10, RoleResource::getNavigationSort());
        $this->assertSame(20, StaffResource::getNavigationSort());
        $this->assertSame(30, LocaleSettings::getNavigationSort());
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
}
