<?php

namespace Tests\Feature;

use App\Filament\Resources\CategoryResource;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Resources\CategoryResource\Pages\ListCategories;
use App\Filament\Resources\CategoryResource\Pages\RelatedProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Category;
use EasyCo\Catalog\Contracts\CategoryRepository;
use EasyCo\Catalog\Contracts\ProductCategoryRepository;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Persistence\Eloquent\CategoryModel;
use EasyCo\Catalog\Product;
use EasyCo\Catalog\ProductCategory;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CategoryResourceTest extends TestCase
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

    private function actingAsPanelAdministrator(): StaffPanelUser
    {
        $model = $this->staffWithRole('Administrator');
        $this->actingAs($model, 'staff');

        return $model;
    }

    public function test_creating_a_category_persists_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Shoes', 'slug' => 'shoes'])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = app(CategoryRepository::class)->all()[0] ?? null;

        $this->assertNotNull($category);
        $this->assertSame('Shoes', $category->name());
        $this->assertSame('shoes', $category->slug());
        $this->assertNull($category->parentId());
    }

    public function test_creating_a_category_with_a_real_parent_persists_it(): void
    {
        $this->actingAsPanelAdministrator();

        $parent = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($parent);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Running Shoes', 'slug' => 'running-shoes', 'parent_id' => $parent->id()])
            ->call('create')
            ->assertHasNoFormErrors();

        $child = CategoryModel::where('slug', 'running-shoes')->first();
        $reloaded = app(CategoryRepository::class)->findById((string) $child->id);

        $this->assertSame($parent->id(), $reloaded->parentId());
    }

    public function test_editing_a_category_updates_it_through_the_domain_layer(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        Livewire::test(EditCategory::class, ['record' => $category->id()])
            ->fillForm(['name' => 'Footwear', 'slug' => 'footwear'])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(CategoryRepository::class)->findById($category->id());

        $this->assertSame('Footwear', $reloaded->name());
        $this->assertSame('footwear', $reloaded->slug());
    }

    public function test_editing_a_category_can_change_its_parent(): void
    {
        $this->actingAsPanelAdministrator();

        $originalParent = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($originalParent);

        $newParent = new Category(id: null, parentId: null, name: 'Bags', slug: 'bags');
        app(CategoryRepository::class)->save($newParent);

        $child = new Category(id: null, parentId: $originalParent->id(), name: 'Running Shoes', slug: 'running-shoes');
        app(CategoryRepository::class)->save($child);

        Livewire::test(EditCategory::class, ['record' => $child->id()])
            ->fillForm(['name' => 'Running Shoes', 'slug' => 'running-shoes', 'parent_id' => $newParent->id()])
            ->call('save')
            ->assertHasNoFormErrors();

        $reloaded = app(CategoryRepository::class)->findById($child->id());

        $this->assertSame($newParent->id(), $reloaded->parentId());
    }

    /**
     * The one UI-layer cycle-avoidance piece this task calls for: a
     * category's own id is excluded from its own parent_id options list
     * on the Edit form.
     */
    public function test_a_categorys_own_id_is_excluded_from_its_parent_options_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        $component = Livewire::test(EditCategory::class, ['record' => $category->id()]);

        $options = $component->instance()->form->getComponent('parent_id')->getOptions();

        $this->assertArrayNotHasKey($category->id(), $options);
    }

    public function test_a_duplicate_slug_is_rejected_on_create(): void
    {
        $this->actingAsPanelAdministrator();

        app(CategoryRepository::class)->save(new Category(id: null, parentId: null, name: 'Shoes', slug: 'colliding-slug'));

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Not Shoes', 'slug' => 'colliding-slug'])
            ->call('create')
            ->assertHasFormErrors(['slug']);
    }

    public function test_a_duplicate_slug_is_rejected_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        app(CategoryRepository::class)->save(new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes'));
        $bags = new Category(id: null, parentId: null, name: 'Bags', slug: 'bags');
        app(CategoryRepository::class)->save($bags);

        Livewire::test(EditCategory::class, ['record' => $bags->id()])
            ->fillForm(['slug' => 'shoes'])
            ->call('save')
            ->assertHasFormErrors(['slug']);
    }

    public function test_editing_a_category_with_its_own_unchanged_slug_does_not_false_positive(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        Livewire::test(EditCategory::class, ['record' => $category->id()])
            ->fillForm(['name' => 'Shoes', 'slug' => 'shoes'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_the_real_permission_matrix_across_all_three_shipped_roles(): void
    {
        $this->actingAsPanelAdministrator();
        $this->get(CategoryResource::getUrl('index'))->assertOk();
        $this->get(CategoryResource::getUrl('create'))->assertOk();

        $manager = $this->staffWithRole('Manager');
        $this->actingAs($manager, 'staff');
        session()->forget('password_hash_staff');
        $this->get(CategoryResource::getUrl('index'))->assertOk();
        $this->get(CategoryResource::getUrl('create'))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');
        $this->get(CategoryResource::getUrl('index'))->assertOk();
        $this->get(CategoryResource::getUrl('create'))->assertForbidden();
    }

    public function test_a_categorys_row_navigates_to_view_and_edit_button_visibility_matches_permission(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);
        $categoryModel = CategoryModel::find($category->id());

        $component = Livewire::test(ListCategories::class);

        $component->assertTableActionVisible('edit', $categoryModel);

        $recordUrl = $component->instance()->getTable()->getRecordUrl($categoryModel);
        $this->assertSame(CategoryResource::getUrl('view', ['record' => $categoryModel]), $recordUrl);

        $this->get($recordUrl)->assertOk();
        $this->get(CategoryResource::getUrl('edit', ['record' => $categoryModel]))->assertOk();

        $productEntry = $this->staffWithRole('Product Entry');
        $this->actingAs($productEntry, 'staff');
        session()->forget('password_hash_staff');

        $componentAsProductEntry = Livewire::test(ListCategories::class);
        $componentAsProductEntry->assertTableActionHidden('edit', $categoryModel);

        $this->get(CategoryResource::getUrl('view', ['record' => $categoryModel]))->assertOk();
        $this->get(CategoryResource::getUrl('edit', ['record' => $categoryModel]))->assertForbidden();
    }

    public function test_the_count_column_shows_the_real_number_of_products_using_this_category(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            app(ProductRepository::class)->save($product);
            app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id()));
        }

        $component = Livewire::test(ListCategories::class);

        $component->assertTableColumnStateSet('products_count', 3, record: CategoryModel::find($category->id()));
    }

    public function test_delete_is_blocked_when_the_category_is_still_in_use(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        $product = Product::createSimple('Air Max', 'SKU-1', 'air-max');
        app(ProductRepository::class)->save($product);
        app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id()));

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', CategoryModel::find($category->id()))
            ->assertNotified();

        $this->assertNotNull(app(CategoryRepository::class)->findById($category->id()));
    }

    public function test_delete_succeeds_when_the_category_is_genuinely_unused(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        Livewire::test(ListCategories::class)
            ->callTableAction('delete', CategoryModel::find($category->id()));

        $this->assertNull(app(CategoryRepository::class)->findById($category->id()));
    }

    public function test_bulk_unlink_actually_detaches_the_selected_products(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        $productIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $product = Product::createSimple("Product {$i}", "SKU-{$i}", "product-{$i}");
            app(ProductRepository::class)->save($product);
            app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $product->id(), categoryId: $category->id()));
            $productIds[] = $product->id();
        }

        Livewire::test(RelatedProducts::class, ['record' => $category->id()])
            ->callTableBulkAction('detach', $productIds);

        $this->assertSame(0, app(CategoryRepository::class)->countProductsUsing($category->id()));
    }

    public function test_bulk_unlink_skips_an_already_detached_product_without_erroring(): void
    {
        $this->actingAsPanelAdministrator();

        $category = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        app(CategoryRepository::class)->save($category);

        $stillAttached = Product::createSimple('Still Attached', 'SKU-1', 'still-attached');
        app(ProductRepository::class)->save($stillAttached);
        app(ProductCategoryRepository::class)->save(new ProductCategory(id: null, productId: $stillAttached->id(), categoryId: $category->id()));

        $neverAttached = Product::createSimple('Never Attached', 'SKU-2', 'never-attached');
        app(ProductRepository::class)->save($neverAttached);

        Livewire::test(RelatedProducts::class, ['record' => $category->id()])
            ->callTableBulkAction('detach', [$stillAttached->id(), $neverAttached->id()]);

        $this->assertSame(0, app(CategoryRepository::class)->countProductsUsing($category->id()));
    }

    /**
     * A descendant, not just $record itself, must be excluded from its
     * own parent_id options — picking a descendant as the new parent
     * would create a cycle, which Category::changeParent() has no
     * protection against (CategoryResource::hierarchicalOptions()'s own
     * docblock).
     */
    public function test_a_categorys_own_descendant_is_also_excluded_from_its_parent_options_on_edit(): void
    {
        $this->actingAsPanelAdministrator();

        $repository = app(CategoryRepository::class);

        $shoes = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($shoes);

        $sneakers = new Category(id: null, parentId: $shoes->id(), name: 'Sneakers', slug: 'sneakers');
        $repository->save($sneakers);

        $highTops = new Category(id: null, parentId: $sneakers->id(), name: 'High Tops', slug: 'high-tops');
        $repository->save($highTops);

        $component = Livewire::test(EditCategory::class, ['record' => $shoes->id()]);
        $options = $component->instance()->form->getComponent('parent_id')->getOptions();

        $this->assertArrayNotHasKey($shoes->id(), $options);
        $this->assertArrayNotHasKey($sneakers->id(), $options);
        $this->assertArrayNotHasKey($highTops->id(), $options);
    }

    /**
     * The tree view itself: default list order is depth-first
     * (parent immediately followed by its own children), and each
     * name is indented by its real depth — this task's own real
     * requirement, not just a data-shape assertion.
     */
    public function test_the_list_shows_categories_in_tree_order_with_depth_indentation(): void
    {
        $this->actingAsPanelAdministrator();

        $repository = app(CategoryRepository::class);

        $clothing = new Category(id: null, parentId: null, name: 'Clothing', slug: 'clothing');
        $repository->save($clothing);

        $shoes = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($shoes);

        $sneakers = new Category(id: null, parentId: $shoes->id(), name: 'Sneakers', slug: 'sneakers');
        $repository->save($sneakers);

        $highTops = new Category(id: null, parentId: $sneakers->id(), name: 'High Tops', slug: 'high-tops');
        $repository->save($highTops);

        $component = Livewire::test(ListCategories::class);

        $orderedNames = array_map(
            fn (CategoryModel $record) => $record->name,
            $component->instance()->getTable()->getRecords()->all()
        );
        $this->assertSame(['Clothing', 'Shoes', 'Sneakers', 'High Tops'], $orderedNames);

        $component->assertTableColumnFormattedStateSet('name', 'Clothing', CategoryModel::find($clothing->id()));
        $component->assertTableColumnFormattedStateSet('name', 'Shoes', CategoryModel::find($shoes->id()));
        $component->assertTableColumnFormattedStateSet('name', '— Sneakers', CategoryModel::find($sneakers->id()));
        $component->assertTableColumnFormattedStateSet('name', '— — High Tops', CategoryModel::find($highTops->id()));
    }

    /**
     * ProductResource's own categories field reuses this same
     * hierarchicalOptions() method — no self-reference/exclusion
     * concern there (a Product isn't a Category), but the tree
     * indentation must still show up.
     */
    public function test_product_resources_categories_field_shows_the_same_tree_indentation(): void
    {
        $repository = app(CategoryRepository::class);

        $shoes = new Category(id: null, parentId: null, name: 'Shoes', slug: 'shoes');
        $repository->save($shoes);

        $sneakers = new Category(id: null, parentId: $shoes->id(), name: 'Sneakers', slug: 'sneakers');
        $repository->save($sneakers);

        $options = CategoryResource::hierarchicalOptions();

        $this->assertSame([
            (string) $shoes->id() => 'Shoes',
            (string) $sneakers->id() => '— Sneakers',
        ], $options);
    }
}
