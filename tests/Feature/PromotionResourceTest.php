<?php

namespace Tests\Feature;

use App\Filament\NavigationGroup;
use App\Filament\StaffPanelUser;
use App\Filament\Resources\PromotionResource;
use App\Filament\Resources\PromotionResource\Pages\CreatePromotion;
use App\Filament\Resources\PromotionResource\Pages\EditPromotion;
use App\Filament\Resources\PromotionResource\Pages\ListPromotions;
use App\Services\PromotionWriter;
use EasyCo\Catalog\Brand;
use EasyCo\Catalog\Contracts\BrandRepository;
use EasyCo\Pricing\Money;
use EasyCo\Promotions\Contracts\PromotionRepository;
use EasyCo\Promotions\Contracts\PromotionScopeRepository;
use EasyCo\Promotions\Enums\PromotionDiscountType;
use EasyCo\Promotions\Enums\PromotionScopeMode;
use EasyCo\Promotions\Enums\PromotionScopeType;
use EasyCo\Promotions\Persistence\Eloquent\PromotionModel;
use EasyCo\Promotions\Persistence\Eloquent\PromotionScopeModel;
use EasyCo\Promotions\Promotion;
use EasyCo\Promotions\PromotionScope;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P5b: the admin «Промокодове» screens under «Маркетинг»: who may open them, the menu order, the list (and its bounded number of queries), the create / edit form (what is editable and what is not, the field errors, the
 * money / percent round trip, the scopes) and the public switch with its warning. Every write goes through PromotionWriter; the API stays as it is.
 */
class PromotionResourceTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    /** the form state of a valid percentage code, overridden by $over */
    private function state(array $over = []): array
    {
        return $over + ['code' => 'ADM'.(++self::$n), 'discount_type' => 'percentage', 'percentage' => '10', 'is_active' => true, 'scopes' => []];
    }

    private function create(array $over = []): PromotionModel
    {
        $promotion = app(PromotionWriter::class)->create($this->state($over));

        return PromotionModel::query()->findOrFail($promotion->id());
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): void
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    /** @return list<string> every label of the sidebar the signed-in staff member sees */
    private function navigationItemLabels(): array
    {
        return collect(Filament::getNavigation())->flatMap(fn ($group) => collect($group->getItems())->map(fn ($item) => $item->getLabel()))->values()->all();
    }

    private function brand(string $name = 'Nike'): string
    {
        $brand = new Brand(id: null, name: $name, slug: strtolower($name).self::$n++);
        app(BrandRepository::class)->save($brand);

        return (string) $brand->id();
    }

    // ---- who may open it, the menu -------------------------------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(PromotionResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    /** ★ with promotion_manage every page opens; without it every page is a 403 and the item is not in the navigation */
    public function test_the_resource_needs_promotion_manage(): void
    {
        $promotion = $this->create();
        $this->actingAsCustomRole([Permission::PROMOTION_MANAGE]);

        $this->get(PromotionResource::getUrl('index'))->assertOk();
        $this->get(PromotionResource::getUrl('create'))->assertOk();
        $this->get(PromotionResource::getUrl('edit', ['record' => $promotion]))->assertOk();
        $this->assertTrue(PromotionResource::canViewAny());
        $this->assertContains('Promo codes', $this->navigationItemLabels());

        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]);

        $this->get(PromotionResource::getUrl('index'))->assertForbidden();
        $this->get(PromotionResource::getUrl('create'))->assertForbidden();
        $this->get(PromotionResource::getUrl('edit', ['record' => $promotion]))->assertForbidden();
        $this->assertFalse(PromotionResource::canViewAny());
    }

    /** ★ the item is not in the sidebar of a staff member without the permission (a fresh request: Filament memoizes the navigation) */
    public function test_the_item_is_not_in_the_navigation_without_promotion_manage(): void
    {
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW]);

        $this->assertNotContains('Promo codes', $this->navigationItemLabels());
    }

    /** a promotion is never deleted: there is no delete permission and no delete action */
    public function test_a_promotion_can_never_be_deleted(): void
    {
        $promotion = $this->create();
        $this->actingAsAdministrator();

        $this->assertFalse(PromotionResource::canDelete($promotion));
        Livewire::test(ListPromotions::class)->assertTableActionDoesNotExist('delete_promotion');
        $this->assertStringNotContainsString('DeleteAction', (string) file_get_contents(app_path('Filament/Resources/PromotionResource/Pages/EditPromotion.php')));
    }

    /** ★ «Маркетинг» sits after «Доставка» and before «Съдържание» (the enum's case order is the render order) */
    public function test_the_menu_group_sits_between_shipping_and_content(): void
    {
        $this->actingAsAdministrator();

        $this->assertSame(NavigationGroup::MARKETING, PromotionResource::getNavigationGroup());

        $cases = array_map(static fn (NavigationGroup $g): string => $g->name, NavigationGroup::cases());
        $this->assertSame(array_search('SHIPPING', $cases, true) + 1, array_search('MARKETING', $cases, true));
        $this->assertSame(array_search('MARKETING', $cases, true) + 1, array_search('CONTENT', $cases, true));
        $this->assertTrue(NavigationGroup::MARKETING->isCollapsed(), 'collapsed by default like the other non-sales groups');

        // the real sidebar: the groups in the order they render
        app()->setLocale('bg');
        $groups = collect(Filament::getNavigation())->map(fn ($group) => $group->getLabel())->filter()->values()->all();
        $this->assertTrue(array_search('Доставка', $groups, true) < array_search('Маркетинг', $groups, true), implode(',', $groups));
        $this->assertTrue(array_search('Маркетинг', $groups, true) < array_search('Съдържание', $groups, true), implode(',', $groups));
    }

    public function test_the_group_and_item_are_translated_in_both_languages(): void
    {
        $this->assertSame('Маркетинг', __('navigation.groups.marketing', [], 'bg'));
        $this->assertSame('Marketing', __('navigation.groups.marketing', [], 'en'));
        $this->assertSame('Промокодове', __('promotions.navigation_label', [], 'bg'));
        $this->assertSame('Promo codes', __('promotions.navigation_label', [], 'en'));
        $this->assertSame(array_keys(trans('promotions', [], 'bg')), array_keys(trans('promotions', [], 'en')));
    }

    // ---- the list ------------------------------------------------------------------------------------------------

    public function test_the_list_shows_code_discount_conditions_validity_used_status_and_the_public_badge(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();
        $this->create(['code' => 'ten', 'percentage' => '12.5', 'minimum_spend' => '79.99', 'is_public' => true, 'public_description' => 'Ten off', 'valid_until' => '2030-12-31 00:00:00']);
        $this->create(['code' => 'five', 'discount_type' => 'fixed_amount', 'discount_amount' => '5.00', 'is_active' => false]);

        Livewire::test(ListPromotions::class)
            ->assertCanSeeTableRecords(PromotionModel::query()->get())
            ->assertSee('ten')
            ->assertSee('12,5 %')
            ->assertSee('мин.')->assertSee('79')
            ->assertSee('31.12.2030')
            ->assertSee('Публичен')
            ->assertSee('Изключен')
            ->assertSee('без срок');
    }

    public function test_the_list_filters_by_status_and_by_public(): void
    {
        $this->actingAsAdministrator();
        $open = $this->create(['code' => 'openone', 'is_public' => true, 'public_description' => 'x']);
        $quiet = $this->create(['code' => 'quietone', 'is_active' => false]);

        Livewire::test(ListPromotions::class)
            ->filterTable('is_public', true)->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$quiet])
            ->resetTableFilters()
            ->filterTable('status', 'inactive')->assertCanSeeTableRecords([$quiet])->assertCanNotSeeTableRecords([$open]);
    }

    public function test_the_empty_list_says_so_and_offers_the_create_button(): void
    {
        app()->setLocale('bg');
        $this->actingAsAdministrator();

        Livewire::test(ListPromotions::class)->assertSee('Още няма промокодове')->assertSee('Нов промокод');
    }

    public function test_the_list_is_newest_first(): void
    {
        $this->actingAsAdministrator();
        $old = $this->create(['code' => 'older']);
        $new = $this->create(['code' => 'newer']);

        Livewire::test(ListPromotions::class)->assertCanSeeTableRecords([$new, $old], inOrder: true);
    }

    /** ★ the used count and the scope count come from ONE grouped query each: the list costs the same with 1 and with 30 promotions */
    public function test_the_list_costs_the_same_number_of_queries_with_one_and_thirty_promotions(): void
    {
        $this->actingAsAdministrator();
        $brand = $this->brand();
        $this->create(['code' => 'solo', 'scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $brand]]]);

        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            Livewire::test(ListPromotions::class)->assertOk();

            return $n;
        };

        $count(); // warm-up
        $one = $count();

        for ($i = 0; $i < 29; $i++) {
            $this->create(['code' => 'many'.$i, 'scopes' => [['type' => 'brand', 'mode' => 'include', 'reference' => $brand]], 'usage_limit_total' => 5]);
        }

        $thirty = $count();
        fwrite(STDERR, "\n[query-count] promotions list: 1 promotion {$one}, 30 promotions {$thirty}\n");

        $this->assertSame($one, $thirty);
    }

    public function test_the_used_count_does_not_count_a_released_redemption(): void
    {
        $this->actingAsAdministrator();
        $promotion = $this->create(['code' => 'usedcode']);

        Schema::disableForeignKeyConstraints();
        foreach ([null, now()] as $released) {
            DB::table('promotion_redemptions')->insert(['promotion_id' => $promotion->id, 'order_id' => '1', 'account_id' => null, 'redeemed_at' => now(), 'released_at' => $released, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::enableForeignKeyConstraints();

        $this->assertSame(1, app(\App\Services\PromotionAdminReader::class)->stats([$promotion->id])[(string) $promotion->id]['used']);
    }
}
