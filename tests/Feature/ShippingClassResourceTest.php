<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\ShippingClassResource;
use App\Filament\Resources\ShippingClassResource\Pages\CreateShippingClass;
use App\Filament\Resources\ShippingClassResource\Pages\EditShippingClass;
use App\Filament\Resources\ShippingClassResource\Pages\ListShippingClasses;
use EasyCo\Shipping\Contracts\ShippingClassRepository;
use EasyCo\Shipping\Persistence\Eloquent\ShippingClassModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5b (shipping-domain-design.md §12.3.1): the classes screens — who may open them, the list with where each
 * class is used, the create / edit form (the code is set once), the delete dialog's explicit buttons, the help links,
 * the escaped output and the bounded number of queries.
 */
class ShippingClassResourceTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function model(string $code): ShippingClassModel
    {
        return ShippingClassModel::query()->where('code', $code)->firstOrFail();
    }

    private function classId(string $code): string
    {
        return (string) $this->model($code)->id;
    }

    // =====================================================================================================
    // Who may open it
    // =====================================================================================================

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(ShippingClassResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_administrator_and_manager_may_open_every_page(): void
    {
        $this->shippingClass('heavy', 'Heavy');

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(ShippingClassResource::getUrl('index'))->assertOk();
            $this->get(ShippingClassResource::getUrl('create'))->assertOk();
            $this->get(ShippingClassResource::getUrl('edit', ['record' => $this->classId('heavy')]))->assertOk();
        }
    }

    public function test_without_shipping_manage_every_page_is_a_403_and_the_item_is_not_in_the_navigation(): void
    {
        $this->shippingClass('heavy', 'Heavy');

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW])] as $actAs) {
            $actAs();

            $this->get(ShippingClassResource::getUrl('index'))->assertForbidden();
            $this->get(ShippingClassResource::getUrl('create'))->assertForbidden();
            $this->get(ShippingClassResource::getUrl('edit', ['record' => $this->classId('heavy')]))->assertForbidden();

            $this->assertFalse(ShippingClassResource::canAccess());
            $this->assertStringNotContainsString('/admin/shipping-classes', $this->get(\App\Filament\Pages\Help::getUrl())->assertOk()->getContent());
        }
    }

    public function test_the_navigation_item_is_in_the_shipping_group_at_sort_40_and_costs_no_query(): void
    {
        $this->actingAsStaff('Administrator');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertCount(1, ShippingClassResource::getNavigationItems());
        $this->assertSame(40, ShippingClassResource::getNavigationSort());
        $this->assertSame(\App\Filament\NavigationGroup::SHIPPING, ShippingClassResource::getNavigationGroup());
        $this->assertGreaterThan(\App\Filament\Resources\ShippingMethodResource::getNavigationSort(), ShippingClassResource::getNavigationSort());
        $this->assertSame(0, $queries);
    }

    public function test_livewire_refuses_to_mount_a_page_without_the_permission_and_the_pages_expose_no_public_write_method(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->actingAsStaff('Product Entry');

        Livewire::test(ListShippingClasses::class)->assertForbidden();
        Livewire::test(CreateShippingClass::class)->assertForbidden();
        Livewire::test(EditShippingClass::class, ['record' => $this->classId('heavy')])->assertForbidden();

        foreach ([ListShippingClasses::class, CreateShippingClass::class, EditShippingClass::class] as $page) {
            $own = array_filter(
                (new \ReflectionClass($page))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $page && ! $method->isStatic(),
            );

            foreach ($own as $method) {
                $this->assertContains($method->getName(), ['getTitle', 'getSubheading'], "{$page}::{$method->getName()} is a public Livewire method");
            }
        }
    }

    // =====================================================================================================
    // The list
    // =====================================================================================================

    public function test_the_empty_list_explains_in_one_sentence_what_a_class_is(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(ListShippingClasses::class)
            ->assertSee(__('shipping.classes.empty'))
            ->assertSee('A class groups products that cost a different amount to ship');

        App::setLocale('bg');
        Livewire::test(ListShippingClasses::class)
            ->assertSee('Все още няма клас за доставка.')
            ->assertSee('Класът групира продукти, чиято доставка струва различно');
    }

    public function test_the_list_shows_code_name_description_and_where_each_class_is_used(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy parcels');
        $this->shippingClass('light', 'Light');
        $this->shippingClass('idle', 'Idle');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->perClassMethod($zone, 'A', ['heavy' => 900, 'light' => 100]);
        $this->perClassMethod($zone, 'B', ['heavy' => 800]);
        $this->variationWithClass('heavy');

        $html = Livewire::test(ListShippingClasses::class)->assertOk()->html();

        $this->assertStringContainsString('Heavy parcels', $html);
        $this->assertStringContainsString('2 methods · 1 product or variation', $html);
        $this->assertStringContainsString('1 method · 0 products and variations', $html);
        $this->assertStringContainsString('Not used', $html);

        App::setLocale('bg');
        $bg = Livewire::test(ListShippingClasses::class)->html();
        $this->assertStringContainsString('2 метода · 1 продукт или вариация', $bg);
        $this->assertStringContainsString('Не се използва', $bg);
    }

    public function test_the_list_is_ordered_by_code_and_escapes_what_was_typed(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('b-class', 'B');
        $this->shippingClass('a-class', '<script>alert(1)</script>');

        $component = Livewire::test(ListShippingClasses::class);
        $html = $component->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertSame(['a-class', 'b-class'], ShippingClassResource::getEloquentQuery()->pluck('code')->all());
    }

    public function test_the_list_reads_the_same_queries_for_3_and_12_classes(): void
    {
        $this->actingAsStaff('Administrator');
        $zone = (string) $this->zone('Z', 0)->id();

        for ($i = 1; $i <= 3; $i++) {
            $this->shippingClass("class-{$i}", "Class {$i}");
        }
        $this->perClassMethod($zone, 'A', ['class-1' => 100]);
        $this->variationWithClass('class-2');

        $three = $this->measureList();

        for ($i = 4; $i <= 12; $i++) {
            $this->shippingClass("class-{$i}", "Class {$i}");
        }
        $this->perClassMethod($zone, 'B', ['class-4' => 100, 'class-5' => 100]);
        $this->variationWithClass('class-6');

        $twelve = $this->measureList();

        fwrite(STDERR, sprintf(
            "\n[query-count] shipping classes list: 3 classes %d shipping reads (classes %d, rates %d, variations %d; %d total), 12 classes %d shipping reads (classes %d, rates %d, variations %d; %d total)\n",
            $three['shipping'], $three['classes'], $three['rates'], $three['variations'], $three['total'],
            $twelve['shipping'], $twelve['classes'], $twelve['rates'], $twelve['variations'], $twelve['total'],
        ));

        $this->assertSame(2, $twelve['classes'], 'the classes: the page itself and the pagination count');
        $this->assertSame(1, $twelve['rates'], 'the rate usage of ALL listed classes: one grouped read');
        $this->assertSame(1, $twelve['variations'], 'the variation usage of ALL listed classes: one grouped read');
        $this->assertSame($three, $twelve, 'the same queries for 3 and for 12 classes — never per row');
    }

    /** @return array{total: int, shipping: int, classes: int, rates: int, variations: int} */
    private function measureList(): array
    {
        $this->app->forgetScopedInstances();
        $counts = ['total' => 0, 'shipping' => 0, 'classes' => 0, 'rates' => 0, 'variations' => 0];

        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;

            if (str_contains($query->sql, 'shipping_method_class_rates')) {
                $counts['rates']++;
            } elseif (str_contains($query->sql, 'from `shipping_classes`') || str_contains($query->sql, 'from "shipping_classes"')) {
                $counts['classes']++;
            } elseif (str_contains($query->sql, 'catalog_variations') && str_contains($query->sql, 'shipping_class')) {
                $counts['variations']++;
            }
        });

        Livewire::test(ListShippingClasses::class)->assertOk();

        $counts['shipping'] = $counts['classes'] + $counts['rates'] + $counts['variations'];

        return $counts;
    }

    public function test_the_edit_page_reads_one_class_row(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');
        $id = $this->classId('heavy');
        $this->app->forgetScopedInstances();

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (str_contains($query->sql, 'shipping_classes')) {
                $reads++;
            }
        });

        Livewire::test(EditShippingClass::class, ['record' => $id])->assertOk();

        fwrite(STDERR, sprintf("\n[query-count] shipping class edit page: %d shipping_classes read(s)\n", $reads));
        $this->assertSame(1, $reads);
    }

    // =====================================================================================================
    // Create and edit
    // =====================================================================================================

    public function test_a_class_is_created_through_the_writer_with_a_notice_and_the_redirect_to_the_list(): void
    {
        $this->actingAsStaff('Administrator');
        $this->activityLogOn();

        Livewire::test(CreateShippingClass::class)
            ->fillForm(['name' => 'Heavy parcels', 'code' => 'heavy', 'description' => 'over 10 kg'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.classes.notice.created'))
            ->assertRedirect(ShippingClassResource::getUrl('index'));

        $class = app(ShippingClassRepository::class)->findByCode('heavy');
        $this->assertSame('Heavy parcels', $class->name());
        $this->assertSame('over 10 kg', $class->description());
        $this->assertCount(1, $this->classAuditRows());
    }

    public function test_the_create_form_shows_the_service_refusals_on_the_field(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');

        foreach ([
            ['name' => 'X', 'code' => 'heavy', 'description' => null, 'field' => 'code'],
            ['name' => 'X', 'code' => 'Bad Code', 'description' => null, 'field' => 'code'],
            ['name' => 'X', 'code' => str_repeat('a', 100), 'description' => null, 'field' => 'code'],
            ['name' => "Bad\u{202E}name", 'code' => 'ok', 'description' => null, 'field' => 'name'],
            ['name' => str_repeat('n', 256), 'code' => 'ok', 'description' => null, 'field' => 'name'],
            ['name' => 'X', 'code' => 'ok', 'description' => "line\nbreak", 'field' => 'description'],
            ['name' => 'X', 'code' => 'ok', 'description' => str_repeat('d', 501), 'field' => 'description'],
            ['name' => '', 'code' => 'ok', 'description' => null, 'field' => 'name'],
        ] as $case) {
            Livewire::test(CreateShippingClass::class)
                ->fillForm(['name' => $case['name'], 'code' => $case['code'], 'description' => $case['description']])
                ->call('create')
                ->assertHasFormErrors([$case['field']]);
        }

        $this->assertSame(1, DB::table('shipping_classes')->count(), 'nothing was written');
    }

    public function test_a_duplicate_code_is_shown_translated_on_the_code_field(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');

        $component = Livewire::test(CreateShippingClass::class)
            ->fillForm(['name' => 'Another', 'code' => 'heavy'])
            ->call('create')
            ->assertHasFormErrors(['code' => __('shipping.classes.errors.code_taken')]);

        $this->assertStringNotContainsString('SQLSTATE', $component->html());
    }

    public function test_the_edit_form_keeps_the_code_read_only_and_saves_the_name_and_description(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');
        $id = $this->classId('heavy');

        Livewire::test(EditShippingClass::class, ['record' => $id])
            ->assertFormSet(['name' => 'Heavy', 'code' => 'heavy'])
            ->assertFormFieldIsDisabled('code')
            ->fillForm(['name' => 'Very heavy', 'description' => 'now with a note'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.classes.notice.updated'));

        $class = app(ShippingClassRepository::class)->findByCode('heavy');
        $this->assertSame('Very heavy', $class->name());
        $this->assertSame('now with a note', $class->description());
    }

    public function test_a_code_tampered_into_the_edit_form_state_changes_nothing(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');
        $id = $this->classId('heavy');

        Livewire::test(EditShippingClass::class, ['record' => $id])
            ->set('data.code', 'sneaky')
            ->fillForm(['name' => 'Renamed'])
            ->call('save');

        $this->assertSame('heavy', DB::table('shipping_classes')->where('id', $id)->value('code'));
        $this->assertSame('Renamed', DB::table('shipping_classes')->where('id', $id)->value('name'));
    }

    public function test_the_form_shows_the_class_mode_fact_line_and_the_help_links_in_a_new_tab_in_both_languages(): void
    {
        $this->actingAsStaff('Administrator');

        $en = html_entity_decode(Livewire::test(CreateShippingClass::class)->html());
        $this->assertStringContainsString('in "replace" mode the most expensive class in the cart sets the price', $en);
        $this->assertStringContainsString('in "adjust" mode the class amounts are added once each', $en);
        $this->assertStringContainsString('/admin/help/shipping#action-class-mode', $en);
        $this->assertStringContainsString('/admin/help/shipping#action-class-editor', $en);
        $this->assertSame(2, preg_match_all('/target="_blank" rel="noopener noreferrer"/', $en));

        App::setLocale('bg');
        $bg = html_entity_decode(Livewire::test(CreateShippingClass::class)->html());
        $this->assertStringContainsString('в режим „замести“ цената определя най-скъпият клас', $bg);
    }

    public function test_the_list_page_links_to_the_classes_help_in_a_new_tab(): void
    {
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(ListShippingClasses::class)->html();

        $this->assertStringContainsString('/admin/help/shipping#action-classes', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*action-classes"[^>]*target="_blank" rel="noopener noreferrer"#', $html);
    }

    // =====================================================================================================
    // Delete
    // =====================================================================================================

    public function test_the_delete_dialog_has_explicit_buttons_in_en_and_bg(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');

        foreach (['en' => ['Delete the class', 'Close'], 'bg' => ['Изтрий класа', __('orders.modal.close', [], 'bg')]] as $locale => [$submit, $close]) {
            App::setLocale($locale);

            $html = Livewire::test(ListShippingClasses::class)
                ->mountTableAction('delete_class', $this->model('heavy'))
                ->getMountedActionModalHtml();

            $this->assertStringContainsString($submit, $html);
            $this->assertStringContainsString($close, $html);
            $this->assertStringNotContainsString('>Откажи<', $html);
            $this->assertStringNotContainsString('>Изпрати<', $html);
        }
    }

    public function test_an_unused_class_is_deleted_through_the_writer(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');

        Livewire::test(ListShippingClasses::class)
            ->callTableAction('delete_class', $this->model('heavy'))
            ->assertNotified(__('shipping.classes.notice.deleted'));

        $this->assertSame(0, DB::table('shipping_classes')->count());
    }

    public function test_a_class_in_use_is_refused_with_the_counts_in_the_notice_and_nothing_is_removed(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->perClassMethod($zone, 'A', ['heavy' => 900]);
        $this->variationWithClass('heavy');
        $this->variationWithClass('heavy');

        $message = (new \App\Services\Exceptions\ShippingClassInUseException('Heavy', 1, 2))->getMessage();
        $this->assertStringContainsString('used by 1 method and 2 products and variations', $message);

        Livewire::test(ListShippingClasses::class)
            ->callTableAction('delete_class', $this->model('heavy'))
            ->assertNotified(\Filament\Notifications\Notification::make()->title(__('shipping.classes.notice.refused'))->body($message)->danger());

        $this->assertSame(1, DB::table('shipping_classes')->count());
        $this->assertSame(1, DB::table('shipping_method_class_rates')->count());
    }

    // =====================================================================================================
    // The overview
    // =====================================================================================================

    public function test_the_overview_links_to_the_classes_next_to_the_zones_and_methods(): void
    {
        $this->actingAsStaff('Administrator');

        $html = (string) $this->get(ShippingOverview::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('href="'.ShippingClassResource::getUrl('index').'"', $html);
        $this->assertStringContainsString('Edit classes', $html);

        App::setLocale('bg');
        $this->assertStringContainsString('Редакция на класовете', (string) $this->get(ShippingOverview::getUrl())->getContent());
    }

    private function variationWithClass(string $code): void
    {
        static $n = 0;
        $n++;

        $product = \EasyCo\Catalog\Product::createSimple("List Product {$n}", "LP-{$n}", "list-product-{$n}");
        $product->variations()[0]->setShippingClass($code);
        app(\EasyCo\Catalog\Contracts\ProductRepository::class)->save($product);
    }
}
