<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use App\Services\ProductTimelinePromoter;
use DateTimeImmutable;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin panel's product timeline — default sort (D5) and the
 * promote/unpromote row actions.
 */
class ProductTimelineAdminTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithRole(string $roleName): StaffPanelUser
    {
        $roleRepository = app(RoleRepository::class);
        $role = $roleRepository->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roleRepository);
            $role = $roleRepository->findSystemRoleByName($roleName);
        }

        $email = strtolower(str_replace(' ', '.', $roleName)).'@example.com';
        $staff = Staff::create($email, app(PasswordHasher::class)->hash('password123'), $roleName, $role);
        app(StaffRepository::class)->save($staff);

        return StaffPanelUser::find($staff->id());
    }

    private function actingAsStaffRole(string $roleName): StaffPanelUser
    {
        $model = $this->staffWithRole($roleName);
        $this->actingAs($model, 'staff');

        return $model;
    }

    private function persistedProduct(string $name, string $slug): ProductModel
    {
        $product = Product::createSimple($name, 'SKU-'.strtoupper($slug), $slug);
        app(ProductRepository::class)->save($product);

        return ProductModel::find($product->id());
    }

    /**
     * Directly overwrites created_at/timeline_at on an already-persisted
     * row — the only way to construct a "created in the past/future"
     * fixture at all, since Product::createSimple() always defaults
     * both to "now" (Commit 1's own design) and there is no public,
     * legitimate domain path to backdate/forward-date a real creation.
     */
    private function setTimeline(ProductModel $model, DateTimeImmutable $createdAt, ?DateTimeImmutable $timelineAt = null): void
    {
        $model->created_at = $createdAt;
        $model->timeline_at = $timelineAt ?? $createdAt;
        $model->save();
    }

    private function listedSlugsInOrder(): array
    {
        return Livewire::test(ListProducts::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->pluck('slug')
            ->values()
            ->all();
    }

    public function test_an_old_product_promoted_today_lists_first_and_a_product_created_tomorrow_lists_above_it(): void
    {
        $this->actingAsStaffRole('Administrator');

        $old = $this->persistedProduct('Old Product', 'old-product');
        $this->setTimeline($old, new DateTimeImmutable('-30 days'));

        $newer = $this->persistedProduct('Newer Product', 'newer-product');
        $this->setTimeline($newer, new DateTimeImmutable('-1 day'));

        // Promote the OLD product to "today" — it must now list ahead
        // of the newer, un-promoted product.
        app(ProductTimelinePromoter::class)->promote((string) $old->id, new DateTimeImmutable());

        $slugs = $this->listedSlugsInOrder();
        $this->assertSame('old-product', $slugs[0], 'the promoted old product must list first');
        $this->assertSame('newer-product', $slugs[1]);

        // A product genuinely CREATED tomorrow must list ABOVE the
        // promoted-to-today one — timeline_at is a real comparable
        // instant, not merely "is/isn't promoted".
        $tomorrow = $this->persistedProduct('Tomorrow Product', 'tomorrow-product');
        $this->setTimeline($tomorrow, new DateTimeImmutable('+1 day'));

        $slugsAfter = $this->listedSlugsInOrder();
        $this->assertSame('tomorrow-product', $slugsAfter[0]);
        $this->assertSame('old-product', $slugsAfter[1]);
    }

    public function test_unpromote_returns_a_product_to_its_natural_created_at_position(): void
    {
        $this->actingAsStaffRole('Administrator');

        $old = $this->persistedProduct('Old Product', 'old-product');
        $this->setTimeline($old, new DateTimeImmutable('-30 days'));

        $newer = $this->persistedProduct('Newer Product', 'newer-product');
        $this->setTimeline($newer, new DateTimeImmutable('-1 day'));

        Livewire::test(ListProducts::class)
            ->callTableAction('promote', ProductModel::find($old->id));

        $this->assertSame('old-product', $this->listedSlugsInOrder()[0]);

        Livewire::test(ListProducts::class)
            ->callTableAction('unpromote', ProductModel::find($old->id));

        // Back to natural created_at order: newer, then old.
        $slugs = $this->listedSlugsInOrder();
        $this->assertSame('newer-product', $slugs[0]);
        $this->assertSame('old-product', $slugs[1]);
    }

    public function test_two_products_with_equal_timeline_at_order_deterministically_by_id_desc(): void
    {
        $this->actingAsStaffRole('Administrator');

        $sameInstant = new DateTimeImmutable('-1 day');

        $first = $this->persistedProduct('First', 'first-product');
        $this->setTimeline($first, $sameInstant);

        $second = $this->persistedProduct('Second', 'second-product');
        $this->setTimeline($second, $sameInstant);

        // Higher id (created later, all else equal) must list first —
        // Filament's own automatic id-DESC tie-break (D5), confirmed
        // against the installed source in this task's own report.
        $slugs = $this->listedSlugsInOrder();
        $this->assertSame('second-product', $slugs[0]);
        $this->assertSame('first-product', $slugs[1]);

        // Deterministic across repeated renders, not merely by luck once.
        $this->assertSame($slugs, $this->listedSlugsInOrder());
    }

    public function test_sorting_by_a_sortable_column_overrides_the_default_timeline_sort(): void
    {
        $this->actingAsStaffRole('Administrator');

        // Timeline order (newest/most-recent first) would be:
        // zebra (newest), apple (middle), mango (oldest) — deliberately
        // NOT alphabetical, so a real override is provable.
        $mango = $this->persistedProduct('Mango', 'mango');
        $this->setTimeline($mango, new DateTimeImmutable('-3 days'));
        $apple = $this->persistedProduct('Apple', 'apple');
        $this->setTimeline($apple, new DateTimeImmutable('-2 days'));
        $zebra = $this->persistedProduct('Zebra', 'zebra');
        $this->setTimeline($zebra, new DateTimeImmutable('-1 day'));

        $this->assertSame(['zebra', 'apple', 'mango'], $this->listedSlugsInOrder());

        $slugsByName = Livewire::test(ListProducts::class)
            ->sortTable('name', 'asc')
            ->instance()
            ->getTable()
            ->getRecords()
            ->pluck('slug')
            ->values()
            ->all();

        $this->assertSame(['apple', 'mango', 'zebra'], $slugsByName);
    }

    public function test_unpromote_is_hidden_when_the_product_is_not_promoted(): void
    {
        $this->actingAsStaffRole('Administrator');

        $product = $this->persistedProduct('Air Max', 'air-max');

        Livewire::test(ListProducts::class)
            ->assertTableActionHidden('unpromote', $product)
            ->assertTableActionVisible('promote', $product);
    }

    public function test_promote_and_unpromote_are_both_hidden_for_an_archived_product(): void
    {
        $this->actingAsStaffRole('Administrator');

        $domainProduct = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $domainProduct->archive();
        app(ProductRepository::class)->save($domainProduct);
        // Promote it directly at the domain level (bypassing the
        // service's own archived-refusal) purely so unpromote's own
        // "hidden for archived" rule — not merely its "not promoted"
        // rule — is what is actually being proven here.
        $domainProduct->promote($domainProduct->createdAt()->modify('+1 day'));
        app(ProductRepository::class)->save($domainProduct);

        $product = ProductModel::find($domainProduct->id());

        // Archived products are hidden from the table by default
        // (ProductResource::table()'s own modifyQueryUsing() —
        // unrelated to this pass, deliberately untouched) — the
        // "archived_only" filter must be active for the row to even be
        // present to assert its actions against.
        Livewire::test(ListProducts::class)
            ->filterTable('archived_only', true)
            ->assertTableActionHidden('promote', $product)
            ->assertTableActionHidden('unpromote', $product);
    }

    public function test_promote_and_unpromote_are_both_hidden_without_edit_permission(): void
    {
        $role = Role::create('View Only', [Permission::PRODUCT_VIEW]);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('view.only@example.com', app(PasswordHasher::class)->hash('password123'), 'View Only', $role);
        app(StaffRepository::class)->save($staff);

        $domainProduct = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        $domainProduct->promote($domainProduct->createdAt()->modify('+1 day'));
        app(ProductRepository::class)->save($domainProduct);

        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');

        $product = ProductModel::find($domainProduct->id());

        Livewire::test(ListProducts::class)
            ->assertTableActionHidden('promote', $product)
            ->assertTableActionHidden('unpromote', $product);
    }

    public function test_duplicating_a_promoted_product_yields_an_un_promoted_copy(): void
    {
        $this->actingAsStaffRole('Administrator');

        $source = Product::createSimple('Air Max', 'SKU-SOURCE', 'air-max');
        app(ProductRepository::class)->save($source);
        app(ProductTimelinePromoter::class)->promote($source->id(), $source->createdAt()->modify('+5 days'));

        $sourceModel = ProductModel::find($source->id());
        $this->assertTrue($sourceModel->fresh()->timeline_at->gt($sourceModel->fresh()->created_at), 'sanity check: the source really is promoted');

        Livewire::test(ListProducts::class)
            ->callTableAction('duplicate', ProductModel::find($source->id()));

        $duplicateModel = ProductModel::where('slug', '!=', 'air-max')->where('name', 'like', 'Air Max%')->firstOrFail();
        $duplicate = app(ProductRepository::class)->findById((string) $duplicateModel->id);

        $this->assertFalse($duplicate->isPromoted(), 'a duplicate must start un-promoted even when the source was promoted');
        $this->assertEquals($duplicate->createdAt(), $duplicate->timelineAt());
    }
}
