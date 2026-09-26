<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\StaffPanelUser;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Enums\ProductStatus;
use EasyCo\Catalog\Persistence\Eloquent\ProductModel;
use EasyCo\Catalog\Product;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The products list's four STATUS VIEWS (D1) — Active · Draft · Archived · All —
 * and the removal of the old "archived only" filter (D2).
 *
 * THE CONTROLS ARE DRIVEN AS THE MERCHANT USES THEM: the buttons are the real
 * table toolbar actions (`TestAction::make('status_view_…')->table()`), the view's
 * URL persistence is the real Livewire `#[Url]` round trip
 * (`Livewire::withQueryParams(['status' => …])`, the same helper this codebase's
 * own tab tests use), and "the current button is highlighted" is asserted through
 * Filament's own `assertTableActionHasColor()` rather than by reading markup.
 *
 * THE `statusView` PROPERTY IS SET DIRECTLY IN MOST TESTS, deliberately: it is the
 * page's own state (the buttons only write it), and going through a button click
 * for every fixture would test the click twice and the view once.
 */
class ListProductsStatusViewsTest extends TestCase
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

        $model = StaffPanelUser::find($staff->id());
        $this->actingAs($model, 'staff');

        return $model;
    }

    /** A persisted SIMPLE product in the given status. */
    private function product(ProductStatus $status, string $slug, string $sku): ProductModel
    {
        $product = Product::createSimple('Product '.$slug, $sku, $slug);

        match ($status) {
            ProductStatus::ACTIVE => $product->publish(),
            ProductStatus::ARCHIVED => $product->archive(),
            ProductStatus::DRAFT => $product->markAsDraft(),
        };

        app(ProductRepository::class)->save($product);

        return ProductModel::where('slug', $slug)->firstOrFail();
    }

    /** One product of each status — the fixture every view assertion needs. */
    private function oneOfEachStatus(): array
    {
        return [
            'active' => $this->product(ProductStatus::ACTIVE, 'active-product', 'SKU-ACTIVE'),
            'draft' => $this->product(ProductStatus::DRAFT, 'draft-product', 'SKU-DRAFT'),
            'archived' => $this->product(ProductStatus::ARCHIVED, 'archived-product', 'SKU-ARCHIVED'),
        ];
    }

    /** The ids of the rows the page is actually showing, sorted — order-independent. */
    private function visibleProductIds(ListProducts $page): array
    {
        $ids = [];

        foreach ($page->getTableRecords() as $model) {
            $ids[] = (string) $model->id;
        }

        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, ProductModel> $products
     * @return list<string>
     */
    private function idsOf(array $products): array
    {
        $ids = array_map(static fn (ProductModel $model): string => (string) $model->id, $products);
        sort($ids);

        return $ids;
    }

    public function test_the_list_defaults_to_the_active_view_and_hides_draft_and_archived_products(): void
    {
        $this->staffWithRole('Administrator');

        $products = $this->oneOfEachStatus();

        $component = Livewire::test(ListProducts::class);

        $this->assertSame(ProductResource::STATUS_VIEW_DEFAULT, $component->get('statusView'));
        $this->assertSame([(string) $products['active']->id], $this->visibleProductIds($component->instance()));
    }

    public function test_each_status_view_shows_exactly_its_own_status_and_all_shows_everything(): void
    {
        $this->staffWithRole('Administrator');

        $products = $this->oneOfEachStatus();

        foreach (['active', 'draft', 'archived'] as $view) {
            $component = Livewire::test(ListProducts::class)->set('statusView', $view);

            $this->assertSame(
                [(string) $products[$view]->id],
                $this->visibleProductIds($component->instance()),
                "the {$view} view must show exactly the {$view} products",
            );
        }

        // "All" is the one view that adds no status constraint — archived rows
        // INCLUDED (that is the whole difference from the old filter).
        $all = Livewire::test(ListProducts::class)->set('statusView', 'all');

        $this->assertSame($this->idsOf(array_values($products)), $this->visibleProductIds($all->instance()));
    }

    public function test_the_status_view_is_restored_from_the_url_query_string_on_a_full_page_load(): void
    {
        $this->staffWithRole('Administrator');

        $products = $this->oneOfEachStatus();

        // A REAL full-page load carrying ?status=archived — the reload and
        // back-navigation case D1 asks for, driven through Livewire's own
        // withQueryParams(), which is what the #[Url] attribute's mount path reads.
        $component = Livewire::withQueryParams(['status' => 'archived'])->test(ListProducts::class);

        $this->assertSame('archived', $component->get('statusView'));
        $this->assertSame([(string) $products['archived']->id], $this->visibleProductIds($component->instance()));

        $draft = Livewire::withQueryParams(['status' => 'draft'])->test(ListProducts::class);

        $this->assertSame([(string) $products['draft']->id], $this->visibleProductIds($draft->instance()));
    }

    public function test_an_unknown_status_value_in_the_url_falls_back_to_the_default_view(): void
    {
        $this->staffWithRole('Administrator');

        $products = $this->oneOfEachStatus();

        $component = Livewire::withQueryParams(['status' => 'not-a-real-view'])->test(ListProducts::class);

        // Whitelisted, never passed through to a query.
        $this->assertSame(ProductResource::STATUS_VIEW_DEFAULT, $component->get('statusView'));
        $this->assertSame([(string) $products['active']->id], $this->visibleProductIds($component->instance()));
    }

    public function test_the_four_buttons_render_in_order_as_one_button_group_at_the_left_of_the_toolbar(): void
    {
        $this->staffWithRole('Administrator');

        $this->oneOfEachStatus();

        $toolbarActions = Livewire::test(ListProducts::class)->instance()->getTable()->getToolbarActions();

        // The status group is the FIRST toolbar action. Filament's own header
        // toolbar puts the actions container first and pushes the search box +
        // filter button to the right with `ms-auto` (both quoted in
        // ProductResource::table()'s own comment), so "first here" IS "left of
        // the search box and the filter button".
        $this->assertInstanceOf(ActionGroup::class, $toolbarActions[0]);
        $this->assertTrue($toolbarActions[0]->isButtonGroup());

        $this->assertSame(
            ['status_view_active', 'status_view_draft', 'status_view_archived', 'status_view_all'],
            array_map(static fn ($action): string => $action->getName(), $toolbarActions[0]->getActions()),
        );

        Livewire::test(ListProducts::class)
            ->assertTableActionVisible('status_view_active')
            ->assertTableActionVisible('status_view_draft')
            ->assertTableActionVisible('status_view_archived')
            ->assertTableActionVisible('status_view_all');
    }

    public function test_the_current_button_is_highlighted_and_the_others_are_not(): void
    {
        $this->staffWithRole('Administrator');

        $this->oneOfEachStatus();

        $component = Livewire::test(ListProducts::class)->set('statusView', 'archived');

        $component
            ->assertTableActionHasColor('status_view_archived', 'primary')
            ->assertTableActionHasColor('status_view_active', 'gray')
            ->assertTableActionHasColor('status_view_draft', 'gray')
            ->assertTableActionHasColor('status_view_all', 'gray');

        // ...and the highlight follows the view, not the button.
        $component->set('statusView', 'active')
            ->assertTableActionHasColor('status_view_active', 'primary')
            ->assertTableActionHasColor('status_view_archived', 'gray');
    }

    public function test_clicking_a_status_button_switches_the_view_and_clears_the_row_selection(): void
    {
        $this->staffWithRole('Administrator');

        $products = $this->oneOfEachStatus();

        $component = Livewire::test(ListProducts::class)
            ->selectTableRecords([$products['active']->id]);

        $this->assertNotEmpty($component->get('selectedTableRecords'), 'the fixture must start with a real selection');

        $component->callAction(TestAction::make('status_view_archived')->table());

        $this->assertSame('archived', $component->get('statusView'));
        $this->assertSame([], $component->get('selectedTableRecords'), 'switching views must clear the selection');
        $this->assertSame([(string) $products['archived']->id], $this->visibleProductIds($component->instance()));
    }

    public function test_the_archived_only_filter_is_gone_and_the_other_filters_still_work_with_a_status_view(): void
    {
        $this->staffWithRole('Administrator');

        $products = $this->oneOfEachStatus();

        $filterNames = array_keys(Livewire::test(ListProducts::class)->instance()->getTable()->getFilters());

        $this->assertNotContains('archived_only', $filterNames, 'D2: the status buttons are the only status filter');

        // The other filters are untouched by this change.
        foreach (['catalog_visibility', 'is_purchasable', 'brand_id', 'season_id', 'product_group_id', 'categories', 'tags', 'attribute_usage'] as $stillThere) {
            $this->assertContains($stillThere, $filterNames);
        }

        // A filter still narrows a status view down further — and can empty it.
        Livewire::test(ListProducts::class)
            ->set('statusView', 'archived')
            ->filterTable('catalog_visibility', false)
            ->assertCanSeeTableRecords([$products['archived']]);

        $emptied = Livewire::test(ListProducts::class)
            ->set('statusView', 'archived')
            ->filterTable('catalog_visibility', true);

        $this->assertSame([], $this->visibleProductIds($emptied->instance()));
    }

    public function test_the_query_count_of_the_all_view_is_identical_for_five_and_twenty_five_products(): void
    {
        $this->assertQueryCountIsStableForView('all');
    }

    public function test_the_query_count_of_the_active_view_is_identical_for_five_and_twenty_five_products(): void
    {
        $this->assertQueryCountIsStableForView('active');
    }

    public function test_the_query_count_of_the_draft_view_is_identical_for_five_and_twenty_five_products(): void
    {
        $this->assertQueryCountIsStableForView('draft');
    }

    public function test_the_query_count_of_the_archived_view_is_identical_for_five_and_twenty_five_products(): void
    {
        $this->assertQueryCountIsStableForView('archived');
    }

    /**
     * ONE VIEW PER TEST METHOD, not a loop inside one test: each method gets its
     * own refreshed database, so the "5 rows" measurement really starts from five
     * products. A loop would let an earlier view's fixtures leak into the next
     * view's five-row run (every product counts in 'all'), and a measurement whose
     * table shows nine rows proves nothing about five versus twenty-five.
     */
    private function assertQueryCountIsStableForView(string $view): void
    {
        $this->staffWithRole('Administrator');

        $five = $this->measureListQueries($view, 5);
        $twentyFive = $this->measureListQueries($view, 25);

        // The row counts are part of the measurement's own honesty: if the 25-run
        // were silently paginated down, its query count would be equal for the
        // wrong reason.
        $this->assertSame(5, $five['rows'], "the {$view} measurement must really show 5 rows");
        $this->assertSame(25, $twentyFive['rows'], "the {$view} measurement must really show 25 rows");
        $this->assertSame(
            $five['queries'],
            $twentyFive['queries'],
            "the {$view} view's query count must not grow with the number of products",
        );

        fwrite(STDERR, sprintf(
            "\n[query-count] products list, %s view: 5 rows = %d queries, 25 rows = %d queries\n",
            $view,
            $five['queries'],
            $twentyFive['queries'],
        ));
    }

    /**
     * A full page load of the list in one view with a KNOWN number of visible rows:
     * the view is topped up with its own products until it really shows that many,
     * then every query of the whole page load is counted (DB::listen(), the same
     * measurement ProductArchiveMediaCleanupTest-adjacent query-count tests use).
     *
     * `tableRecordsPerPage` is set to 25 so the 25-row measurement cannot be
     * silently paginated down to a shorter page — otherwise "5 vs 25" would
     * compare the same page twice and assert nothing at all.
     *
     * @return array{queries: int, rows: int}
     */
    private function measureListQueries(string $view, int $rows): array
    {
        $statuses = match ($view) {
            'active' => [ProductStatus::ACTIVE],
            'draft' => [ProductStatus::DRAFT],
            'archived' => [ProductStatus::ARCHIVED],
            default => [ProductStatus::ACTIVE, ProductStatus::DRAFT, ProductStatus::ARCHIVED],
        };

        $visibleSoFar = count($this->visibleProductIds(
            Livewire::test(ListProducts::class)->set('statusView', $view)->set('tableRecordsPerPage', 25)->instance(),
        ));

        for ($index = $visibleSoFar; $index < $rows; $index++) {
            $this->product($statuses[$index % count($statuses)], "measured-{$view}-{$index}", "SKU-MEASURED-{$view}-{$index}");
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $visible = count($this->visibleProductIds(
            Livewire::test(ListProducts::class)->set('statusView', $view)->set('tableRecordsPerPage', 25)->instance(),
        ));

        return ['queries' => $queries, 'rows' => $visible];
    }
}
