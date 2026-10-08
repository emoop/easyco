<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\ShippingMethodResource;
use App\Filament\Resources\ShippingMethodResource\Pages\CreateShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\EditShippingMethod;
use App\Filament\Resources\ShippingMethodResource\Pages\ListShippingMethods;
use App\Filament\Support\HelpLink;
use App\Services\ShippingMethodSummaryReader;
use EasyCo\Shipping\Contracts\ShippingMethodRepository;
use EasyCo\Shipping\Enums\ShippingClassMode;
use EasyCo\Shipping\Enums\ShippingMethodKind;
use EasyCo\Shipping\Persistence\Eloquent\ShippingMethodModel;
use EasyCo\Shipping\ShippingMethod;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5d (shipping-domain-design.md §12.3.3): the methods screens — who may open them, the list in zone-then-method
 * order with the one-sentence summary, the form by kind, the actions (toggle, move, copy, delete) as service calls, the
 * dialogs' explicit buttons, the help links, and the bounded number of queries.
 */
class ShippingMethodResourceTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function model(string $id): ShippingMethodModel
    {
        return ShippingMethodResource::getEloquentQuery()->where('shipping_methods.id', $id)->firstOrFail();
    }

    private function allModels(): \Illuminate\Support\Collection
    {
        return ShippingMethodResource::getEloquentQuery()->get();
    }

    // =====================================================================================================
    // Who may open it
    // =====================================================================================================

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(ShippingMethodResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_administrator_and_manager_may_open_every_page(): void
    {
        $method = $this->method((string) $this->zone('Z', 0)->id());

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(ShippingMethodResource::getUrl('index'))->assertOk();
            $this->get(ShippingMethodResource::getUrl('create'))->assertOk();
            $this->get(ShippingMethodResource::getUrl('edit', ['record' => $method->id()]))->assertOk();
        }
    }

    public function test_without_shipping_manage_every_page_is_a_403_and_the_item_is_not_in_the_navigation(): void
    {
        $method = $this->method((string) $this->zone('Z', 0)->id());

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW])] as $actAs) {
            $actAs();

            $this->get(ShippingMethodResource::getUrl('index'))->assertForbidden();
            $this->get(ShippingMethodResource::getUrl('create'))->assertForbidden();
            $this->get(ShippingMethodResource::getUrl('edit', ['record' => $method->id()]))->assertForbidden();

            $this->assertFalse(ShippingMethodResource::canAccess());
            $this->assertStringNotContainsString('/admin/shipping-methods', $this->get(\App\Filament\Pages\Help::getUrl())->assertOk()->getContent());
        }
    }

    public function test_the_navigation_item_is_in_the_shipping_group_after_the_zones_and_costs_no_query(): void
    {
        $this->actingAsStaff('Administrator');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->assertCount(1, ShippingMethodResource::getNavigationItems());
        $this->assertSame(30, ShippingMethodResource::getNavigationSort());
        $this->assertGreaterThan(\App\Filament\Resources\ShippingZoneResource::getNavigationSort(), ShippingMethodResource::getNavigationSort());
        $this->assertSame(\App\Filament\NavigationGroup::SHIPPING, ShippingMethodResource::getNavigationGroup());
        $this->assertSame(0, $queries, 'the navigation item adds no query to any page');
    }

    public function test_livewire_refuses_to_mount_a_page_without_the_permission(): void
    {
        $method = $this->method((string) $this->zone('Z', 0)->id());
        $this->actingAsStaff('Product Entry');

        Livewire::test(ListShippingMethods::class)->assertForbidden();
        Livewire::test(CreateShippingMethod::class)->assertForbidden();
        Livewire::test(EditShippingMethod::class, ['record' => $method->id()])->assertForbidden();
    }

    public function test_the_pages_expose_no_public_method_that_could_reach_a_service_around_the_check(): void
    {
        $allowed = ['getTitle', 'getSubheading'];

        foreach ([ListShippingMethods::class, CreateShippingMethod::class, EditShippingMethod::class] as $page) {
            $own = array_filter(
                (new \ReflectionClass($page))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $page && ! $method->isStatic(),
            );

            foreach ($own as $method) {
                $this->assertContains($method->getName(), $allowed, "{$page}::{$method->getName()} is a public Livewire method");
            }
        }

        foreach ([\App\Services\ShippingMethodWriter::class, \App\Services\ShippingMethodReorderer::class, \App\Services\ShippingMethodCopier::class] as $service) {
            $this->assertFalse(is_subclass_of($service, \Livewire\Component::class), "{$service} is not a component");
        }
    }

    public function test_a_carrier_method_cannot_be_edited_here_but_can_be_switched_moved_copied_and_deleted(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $carrier = ShippingMethod::create($zone, 'Econt live', ShippingMethodKind::CARRIER, 0, true, null, [], null, 'econt', true);
        app(ShippingMethodRepository::class)->save($carrier);
        $this->actingAsStaff('Administrator');

        $this->get(ShippingMethodResource::getUrl('edit', ['record' => $carrier->id()]))->assertForbidden();

        Livewire::test(ListShippingMethods::class)
            ->assertTableActionHidden('edit_method', $this->model((string) $carrier->id()))
            ->assertTableActionVisible('copy_method', $this->model((string) $carrier->id()))
            ->assertTableActionVisible('delete_method', $this->model((string) $carrier->id()));
    }

    // =====================================================================================================
    // The list
    // =====================================================================================================

    public function test_the_list_shows_zones_in_match_order_then_each_zones_methods_in_their_order(): void
    {
        $second = (string) $this->zone('Second by id, first by order', 0)->id();
        $first = (string) $this->zone('First by id, second by order', 1)->id();
        $this->methodAt($first, 'Z2 later', 5);
        $this->methodAt($first, 'Z2 earlier', 1);
        $this->methodAt($second, 'Z1 only', 0);
        $this->actingAsStaff('Administrator');

        Livewire::test(ListShippingMethods::class)
            ->assertCanSeeTableRecords($this->allModels(), inOrder: true)
            ->assertSeeInOrder(['Z1 only', 'Z2 earlier', 'Z2 later']);
    }

    private function methodAt(string $zoneId, string $name, int $sortOrder): ShippingMethod
    {
        $method = ShippingMethod::create($zoneId, $name, ShippingMethodKind::FLAT, $sortOrder, true, 500);
        app(ShippingMethodRepository::class)->save($method);

        return $method;
    }

    public function test_the_summary_is_the_one_the_reader_builds_and_an_adjust_method_shows_signed_amounts(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('discount', 'Discount');
        $zone = (string) $this->zone('Z', 0)->id();
        $adjust = $this->perClassMethod($zone, 'Adjusting', ['heavy' => 2500, 'discount' => -300], ShippingClassMode::ADJUST, freeAbove: 10000);
        $replace = $this->perClassMethod($zone, 'Replacing', ['heavy' => 3000]);
        $this->actingAsStaff('Administrator');

        $reader = app(ShippingMethodSummaryReader::class);
        $adjustSentence = $reader->summary(app(ShippingMethodRepository::class)->findById((string) $adjust->id()));
        $replaceSentence = $reader->summary(app(ShippingMethodRepository::class)->findById((string) $replace->id()));

        $this->assertStringContainsString('Heavy +25.00', $adjustSentence);
        $this->assertStringContainsString("Discount \u{2212}3.00", $adjustSentence);
        $this->assertStringNotContainsString('+', $replaceSentence);
        $this->assertStringContainsString('Heavy 30.00', $replaceSentence);

        Livewire::test(ListShippingMethods::class)
            ->assertSee($adjustSentence)
            ->assertSee($replaceSentence)
            ->assertSee(__('shipping.methods.modes.adjust'))
            ->assertSee(__('shipping.methods.modes.replace'));
    }

    public function test_the_list_reads_a_fixed_number_of_queries_for_3_and_12_methods(): void
    {
        $this->actingAsStaff('Administrator');
        $this->shippingClass('heavy', 'Heavy');

        for ($i = 1; $i <= 3; $i++) {
            $zone = (string) $this->zone("Zone {$i}", $i - 1)->id();
            $this->perClassMethod($zone, "Method {$i}", ['heavy' => 100 * $i]);
        }

        $three = $this->measure(fn () => Livewire::test(ListShippingMethods::class)->assertOk());

        for ($i = 4; $i <= 12; $i++) {
            $zone = (string) $this->zone("Zone {$i}", $i - 1)->id();
            $this->perClassMethod($zone, "Method {$i}", ['heavy' => 100 * $i]);
        }

        $twelve = $this->measure(fn () => Livewire::test(ListShippingMethods::class)->assertOk());

        fwrite(STDERR, sprintf(
            "\n[query-count] shipping methods list: 3 methods %d shipping reads (methods %d, zones %d, rates %d, classes %d; %d total), 12 methods %d shipping reads (methods %d, zones %d, rates %d, classes %d; %d total)\n",
            $three['shipping'], $three['methods'], $three['zones'], $three['rates'], $three['classes'], $three['total'],
            $twelve['shipping'], $twelve['methods'], $twelve['zones'], $twelve['rates'], $twelve['classes'], $twelve['total'],
        ));

        $this->assertSame($three, $twelve, 'the same queries for 3 and for 12 methods — never per row');
    }

    /** @return array{total: int, shipping: int, methods: int, zones: int, rates: int, classes: int} */
    private function measure(callable $call): array
    {
        $this->app->forgetScopedInstances();
        $counts = ['total' => 0, 'shipping' => 0, 'methods' => 0, 'zones' => 0, 'rates' => 0, 'classes' => 0];

        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;

            if (str_contains($query->sql, 'shipping_method_class_rates')) {
                $counts['rates']++;
            } elseif (str_contains($query->sql, 'shipping_methods')) {
                $counts['methods']++;
            } elseif (str_contains($query->sql, 'shipping_zones')) {
                $counts['zones']++;
            } elseif (str_contains($query->sql, 'shipping_classes')) {
                $counts['classes']++;
            }
        });

        $call();
        $counts['shipping'] = $counts['methods'] + $counts['zones'] + $counts['rates'] + $counts['classes'];

        return $counts;
    }

    public function test_the_row_actions_have_explicit_labels_in_en_and_bg(): void
    {
        $this->method((string) $this->zone('Z', 0)->id());
        $this->actingAsStaff('Administrator');

        foreach (['en' => ['Actions', 'Edit', 'Copy to zones', 'Delete'], 'bg' => ['Действия', 'Редакция', 'Копирай в зони', 'Изтрий']] as $locale => $labels) {
            App::setLocale($locale);
            $html = Livewire::test(ListShippingMethods::class)->html();

            foreach ($labels as $label) {
                $this->assertStringContainsString($label, $html, "{$locale}: {$label}");
            }
        }
    }

    public function test_the_first_method_of_a_zone_has_no_move_up_and_the_last_no_move_down(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $first = $this->methodAt($zone, 'First', 0);
        $middle = $this->methodAt($zone, 'Middle', 1);
        $last = $this->methodAt($zone, 'Last', 2);
        $this->actingAsStaff('Administrator');

        Livewire::test(ListShippingMethods::class)
            ->assertTableActionHidden('move_up', $this->model((string) $first->id()))
            ->assertTableActionVisible('move_down', $this->model((string) $first->id()))
            ->assertTableActionVisible('move_up', $this->model((string) $middle->id()))
            ->assertTableActionVisible('move_down', $this->model((string) $middle->id()))
            ->assertTableActionVisible('move_up', $this->model((string) $last->id()))
            ->assertTableActionHidden('move_down', $this->model((string) $last->id()));
    }

    public function test_the_filters_narrow_by_zone_and_by_active_state(): void
    {
        $one = (string) $this->zone('One', 0)->id();
        $two = (string) $this->zone('Two', 1)->id();
        $this->methodAt($one, 'In one', 0);
        $off = ShippingMethod::create($two, 'Off in two', ShippingMethodKind::FLAT, 0, false, 500);
        app(ShippingMethodRepository::class)->save($off);
        $this->methodAt($two, 'On in two', 1);
        $this->actingAsStaff('Administrator');

        Livewire::test(ListShippingMethods::class)
            ->filterTable('zone_id', $two)
            ->assertSee('Off in two')->assertSee('On in two')->assertDontSee('In one');

        Livewire::test(ListShippingMethods::class)
            ->filterTable('is_active', false)
            ->assertSee('Off in two')->assertDontSee('On in two')->assertDontSee('In one');
    }

    // =====================================================================================================
    // Toggle, move, copy, delete — each a service call
    // =====================================================================================================

    public function test_the_active_toggle_goes_through_the_service_and_writes_one_audit_entry(): void
    {
        $this->activityLogOn();
        $method = $this->method((string) $this->zone('Z', 0)->id());
        $this->actingAsStaff('Administrator');
        $this->spyOnMethodHooks();

        Livewire::test(ListShippingMethods::class)
            ->call('updateTableColumnState', 'is_active', (string) $method->id(), false);

        $this->assertFalse(app(ShippingMethodRepository::class)->findById((string) $method->id())->isActive());
        $rows = array_values(array_filter($this->methodAuditRows(), fn ($row) => $row->field === 'active'));
        $this->assertCount(1, $rows);
        $this->assertSame('shipping.method.deactivated', $this->hookCalls[0][0]);
    }

    public function test_move_up_and_move_down_change_the_order_through_the_service(): void
    {
        $this->activityLogOn();
        $zone = (string) $this->zone('Z', 0)->id();
        $a = $this->methodAt($zone, 'A', 0);
        $b = $this->methodAt($zone, 'B', 1);
        $this->methodAt($zone, 'C', 2);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(ListShippingMethods::class);

        $page->callTableAction('move_up', $this->model((string) $b->id()))->assertNotified(__('shipping.methods.notice.moved'));
        $this->assertSame(['B', 'A', 'C'], $this->methodNames($zone));

        $page->callTableAction('move_down', $this->model((string) $a->id()));
        $this->assertSame(['B', 'C', 'A'], $this->methodNames($zone));
        $this->assertSame([0, 1, 2], $this->methodSortOrders($zone));
        $this->assertCount(2, array_filter($this->methodAuditRows(), fn ($row) => $row->field === 'order'));
    }

    public function test_the_delete_dialog_has_explicit_buttons_in_en_and_bg_and_never_the_defaults(): void
    {
        $method = $this->method((string) $this->zone('Z', 0)->id(), 'Gone soon');
        $this->actingAsStaff('Administrator');

        foreach (['en' => ['Delete the method', 'Close'], 'bg' => ['Изтрий метода', 'Затвори']] as $locale => [$submit, $close]) {
            App::setLocale($locale);

            $modal = preg_replace('/\s+/', ' ', Livewire::test(ListShippingMethods::class)
                ->mountTableAction('delete_method', $this->model((string) $method->id()))
                ->getMountedActionModalHtml());

            $this->assertStringContainsString($submit, $modal, $locale);
            $this->assertStringContainsString($close, $modal, $locale);
            $this->assertStringContainsString('Gone soon', $modal);
            $this->assertDoesNotMatchRegularExpression('/>\s*(Изпрати|Откажи|Submit|Cancel|Confirm|Потвърди)\s*</u', $modal, "{$locale}: no default button");
        }
    }

    public function test_deleting_a_method_removes_it_through_the_service(): void
    {
        $method = $this->method((string) $this->zone('Z', 0)->id());
        $this->actingAsStaff('Administrator');
        $this->spyOnMethodHooks();

        Livewire::test(ListShippingMethods::class)
            ->callTableAction('delete_method', $this->model((string) $method->id()))
            ->assertNotified(__('shipping.methods.notice.deleted'));

        $this->assertSame(0, DB::table('shipping_methods')->count());
        $this->assertSame(['shipping.method.deleted'], array_map(fn (array $call): string => $call[0], $this->hookCalls));
        $this->assertCount(1, $this->methodAuditRows());
    }

    public function test_the_copy_dialog_lists_the_other_zones_only_has_explicit_buttons_and_copies_through_the_service(): void
    {
        $source = (string) $this->zone('Source', 0)->id();
        $target = (string) $this->zone('Target', 1)->id();
        $this->zone('Third', 2);
        $method = $this->method($source, 'Econt');
        $this->actingAsStaff('Administrator');

        foreach (['en' => ['Copy the method', 'Close'], 'bg' => ['Копирай метода', 'Затвори']] as $locale => [$submit, $close]) {
            App::setLocale($locale);
            $modal = preg_replace('/\s+/', ' ', Livewire::test(ListShippingMethods::class)
                ->mountTableAction('copy_method', $this->model((string) $method->id()))
                ->getMountedActionModalHtml());

            $this->assertStringContainsString($submit, $modal, $locale);
            $this->assertStringContainsString($close, $modal, $locale);
            $this->assertStringContainsString('Target', $modal);
            $this->assertStringContainsString('Third', $modal);
            $this->assertDoesNotMatchRegularExpression('/>\s*(Изпрати|Откажи|Submit|Cancel)\s*</u', $modal);
            $this->assertStringNotContainsString('Source</option>', $modal, 'the source zone is not offered');
        }

        App::setLocale('en');
        Livewire::test(ListShippingMethods::class)
            ->callTableAction('copy_method', $this->model((string) $method->id()), data: ['zones' => [$target]])
            ->assertNotified();

        $this->assertSame(['Econt'], $this->methodNames($target));
    }

    public function test_a_copy_to_a_zone_with_the_same_method_says_so(): void
    {
        $source = (string) $this->zone('Source', 0)->id();
        $target = (string) $this->zone('Target', 1)->id();
        $this->method($target, 'Econt');
        $method = $this->method($source, 'Econt');
        $this->actingAsStaff('Administrator');

        Livewire::test(ListShippingMethods::class)
            ->callTableAction('copy_method', $this->model((string) $method->id()), data: ['zones' => [$target]])
            ->assertNotified(trans_choice('shipping.methods.copy.done', 1, ['count' => 1]));

        $this->assertSame(['Econt', 'Econt'], $this->methodNames($target));
    }

    // =====================================================================================================
    // The forms
    // =====================================================================================================

    public function test_the_fields_show_and_hide_with_the_kind(): void
    {
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');
        $page = Livewire::test(CreateShippingMethod::class);

        $visible = [
            'flat' => ['price', 'free_above'],
            'free' => [],
            'per_class' => ['price', 'free_above', 'class_mode'],
        ];

        foreach ($visible as $kind => $shown) {
            $page->fillForm(['kind' => $kind]);

            foreach (['price', 'free_above', 'class_mode', 'carrier_code'] as $field) {
                in_array($field, $shown, true)
                    ? $page->assertFormFieldIsVisible($field)
                    : $page->assertFormFieldIsHidden($field);
            }
        }
    }

    public function test_the_kind_select_offers_no_carrier_while_none_is_registered(): void
    {
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(CreateShippingMethod::class)->html();

        $this->assertStringContainsString(__('shipping.methods.kinds.per_class'), $html);
        $this->assertStringNotContainsString('value="carrier"', $html);

        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['name' => 'Sneaky', 'kind' => 'carrier', 'zone_id' => (string) DB::table('shipping_zones')->value('id')])
            ->call('create')
            ->assertHasFormErrors(['kind']);
        $this->assertSame(0, DB::table('shipping_methods')->count());
    }

    public function test_creating_a_flat_and_an_adjusting_method_through_the_form(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('discount', 'Discount');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['zone_id' => $zone, 'name' => 'Econt', 'kind' => 'flat', 'price' => '4,50', 'free_above' => '100'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.methods.notice.created'));

        Livewire::test(CreateShippingMethod::class)
            ->fillForm([
                'zone_id' => $zone, 'name' => 'Adjusting', 'kind' => 'per_class', 'price' => '5', 'class_mode' => 'adjust',
                'rates' => ['heavy' => '25', 'discount' => '-3'], 'requires_pickup_point' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        [$flat, $adjust] = app(ShippingMethodRepository::class)->forZone($zone);

        $this->assertSame(450, $flat->amountMinor());
        $this->assertSame(10000, $flat->freeAboveMinor());
        $this->assertSame(0, $flat->sortOrder());
        $this->assertSame(ShippingClassMode::ADJUST, $adjust->classMode());
        $this->assertSame(['discount' => -300, 'heavy' => 2500], $adjust->classRates());
        $this->assertSame(1, $adjust->sortOrder(), 'appended');
        $this->assertTrue($adjust->requiresPickupPoint());
    }

    public function test_service_and_form_refusals_land_on_the_right_field_and_write_nothing(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');
        $base = ['zone_id' => $zone, 'name' => 'X', 'kind' => 'flat', 'price' => '5'];

        foreach ([
            'name_overlong' => [['name' => str_repeat('я', 256)], 'name'],
            'name_control' => [['name' => "Bad\nname"], 'name'],
            'price_missing' => [['price' => ''], 'price'],
            'price_30_digits' => [['price' => str_repeat('9', 30)], 'price'],
            'price_10_integer_digits' => [['price' => '1000000000.00'], 'price'],
            'price_negative' => [['price' => '-5'], 'price'],
            'price_not_numeric' => [['price' => 'abc'], 'price'],
            'free_above_not_numeric' => [['free_above' => 'lots'], 'free_above'],
            'class_amount_not_numeric' => [['kind' => 'per_class', 'rates' => ['heavy' => 'x']], 'rates.heavy'],
            'class_amount_negative_in_replace' => [['kind' => 'per_class', 'class_mode' => 'replace', 'rates' => ['heavy' => '-5']], 'class_mode'],
        ] as $label => [$override, $field]) {
            try {
                Livewire::test(CreateShippingMethod::class)
                    ->fillForm(array_merge($base, $override))
                    ->call('create')
                    ->assertHasFormErrors([$field]);
            } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
                $this->fail("{$label}: no error on {$field} — ".$failure->getMessage());
            }
        }

        $this->assertSame(0, DB::table('shipping_methods')->count());
    }

    public function test_a_class_that_is_not_a_field_of_the_form_cannot_be_smuggled_in(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->actingAsStaff('Administrator');

        // The form only carries a field per EXISTING class: a state key for an unknown class is dropped before the service
        // sees it (the service's own unknown-class refusal is covered in ShippingMethodWriterTest).
        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['zone_id' => $zone, 'name' => 'X', 'kind' => 'per_class', 'price' => '5', 'rates' => ['heavy' => '1', 'ghost' => '9']])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['heavy' => 100], app(ShippingMethodRepository::class)->forZone($zone)[0]->classRates());
    }

    public function test_editing_a_method_fills_the_form_round_trips_it_and_keeps_its_place_and_zone(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $this->methodAt($zone, 'First', 0);
        $method = $this->perClassMethod($zone, 'Second', ['heavy' => 3000], base: 500, freeAbove: 9000);
        $this->methodAt($zone, 'Third', 2);
        $ordersBefore = $this->methodSortOrders($zone);
        $this->actingAsStaff('Administrator');

        Livewire::test(EditShippingMethod::class, ['record' => $method->id()])
            ->assertFormSet(['name' => 'Second', 'kind' => 'per_class', 'price' => '5.00', 'free_above' => '90.00', 'class_mode' => 'replace', 'rates' => ['heavy' => '30.00']])
            ->fillForm(['name' => 'Second renamed', 'class_mode' => 'adjust', 'rates' => ['heavy' => '-2.5'], 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.methods.notice.updated'));

        $stored = app(ShippingMethodRepository::class)->findById((string) $method->id());

        $this->assertSame('Second renamed', $stored->name());
        $this->assertSame(ShippingClassMode::ADJUST, $stored->classMode());
        $this->assertSame(['heavy' => -250], $stored->classRates());
        $this->assertFalse($stored->isActive());
        $this->assertSame($zone, $stored->zoneId());
        $this->assertSame(['First', 'Second renamed', 'Third'], $this->methodNames($zone));
        $this->assertSame($ordersBefore, $this->methodSortOrders($zone), 'an edit never rewrites the order');
    }

    public function test_the_zone_is_read_only_on_edit_with_a_fact_line(): void
    {
        $zone = (string) $this->zone('Z', 0)->id();
        $other = (string) $this->zone('Other', 1)->id();
        $method = $this->method($zone);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(EditShippingMethod::class, ['record' => $method->id()]);

        $page->assertSee(__('shipping.methods.facts.zone_fixed'));
        $page->fillForm(['zone_id' => $other])->call('save');

        $this->assertSame($zone, app(ShippingMethodRepository::class)->findById((string) $method->id())->zoneId(), 'a method never changes zone');
    }

    public function test_the_class_mode_fact_lines_and_the_free_above_sentence_show_in_both_languages(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        foreach (['en', 'bg'] as $locale) {
            App::setLocale($locale);

            $page = Livewire::test(CreateShippingMethod::class)->fillForm(['kind' => 'per_class', 'class_mode' => 'replace', 'free_above' => '100']);
            $page->assertSee(__('shipping.methods.facts.mode_replace'));
            $page->assertSee(__('shipping.methods.facts.free_above', ['amount' => app(\App\Services\PriceDisplayFormatter::class)->format('100.00', \EasyCo\Pricing\DefaultCurrency::get())]));
            $page->assertSee(__('shipping.methods.facts.kind_clears'));

            $page->fillForm(['class_mode' => 'adjust'])->assertSee(__('shipping.methods.facts.mode_adjust'));
        }
    }

    public function test_with_no_classes_the_form_says_so(): void
    {
        $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingMethod::class)
            ->fillForm(['kind' => 'per_class'])
            ->assertSee(__('shipping.methods.facts.no_classes'));
    }

    public function test_switching_replace_to_adjust_with_class_amounts_asks_to_confirm_with_explicit_buttons(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->perClassMethod($zone, 'Priced', ['heavy' => 3000]);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(EditShippingMethod::class, ['record' => $method->id()]);
        $asks = fn (): bool => (function () {
            return $this->switchesToAdjustments();
        })->call($page->instance());

        $this->assertFalse($asks(), 'still replace: nothing to confirm');

        $page->fillForm(['class_mode' => 'adjust']);
        $this->assertTrue($asks(), 'replace -> adjust with a non-zero amount asks');

        $page->fillForm(['rates' => ['heavy' => '0']]);
        $this->assertFalse($asks(), 'all amounts zero or empty: nothing to confirm');

        foreach (['en' => ['Switch to adjustments', 'Back to the form'], 'bg' => ['Превключи към корекции', 'Назад към формата']] as $locale => [$submit, $cancel]) {
            App::setLocale($locale);
            $action = (function () {
                return $this->getSaveFormAction();
            })->call(Livewire::test(EditShippingMethod::class, ['record' => $method->id()])->instance());

            $this->assertSame($submit, $action->getModalSubmitActionLabel());
            $this->assertSame($cancel, $action->getModalCancelActionLabel());
        }
    }

    public function test_an_adjust_method_going_back_or_an_unchanged_mode_never_asks(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->perClassMethod($zone, 'Adjusting', ['heavy' => 300], ShippingClassMode::ADJUST);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(EditShippingMethod::class, ['record' => $method->id()]);
        $asks = fn (): bool => (function () {
            return $this->switchesToAdjustments();
        })->call($page->instance());

        $this->assertFalse($asks());
        $page->fillForm(['class_mode' => 'replace']);
        $this->assertFalse($asks());
    }

    public function test_the_edit_page_reads_a_fixed_number_of_shipping_rows(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $zone = (string) $this->zone('Z', 0)->id();
        $method = $this->perClassMethod($zone, 'Priced', ['heavy' => 3000]);
        $this->actingAsStaff('Administrator');

        $counts = $this->measure(fn () => Livewire::test(EditShippingMethod::class, ['record' => $method->id()])->assertOk());

        fwrite(STDERR, sprintf(
            "\n[query-count] shipping method edit page: %d shipping reads (methods %d, zones %d, rates %d, classes %d; %d total)\n",
            $counts['shipping'], $counts['methods'], $counts['zones'], $counts['rates'], $counts['classes'], $counts['total'],
        ));

        $this->assertLessThanOrEqual(2, $counts['rates'], 'the class amounts are read in one query, never per class');
        $this->assertLessThanOrEqual(3, $counts['methods']);
    }

    // =====================================================================================================
    // Escaping, help links, the overview
    // =====================================================================================================

    public function test_method_and_zone_names_are_escaped_on_the_list(): void
    {
        $zone = (string) $this->zone('<i>Zone</i>', 0)->id();
        $this->method($zone, '<script>alert(1)</script> & <b>bold</b>');
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(ListShippingMethods::class)->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('<i>Zone</i>', $html);
    }

    public function test_the_form_and_the_list_link_to_help_anchors_that_exist_in_both_shipping_help_files(): void
    {
        $zone = $this->zone('Z', 0);
        $method = $this->method((string) $zone->id());
        $this->actingAsStaff('Administrator');

        $form = Livewire::test(CreateShippingMethod::class)->html();
        $edit = Livewire::test(EditShippingMethod::class, ['record' => $method->id()])->html();
        $list = Livewire::test(ListShippingMethods::class)->html();

        foreach (['method_editor', 'method_kinds', 'class_mode', 'method_copy'] as $anchor) {
            $this->assertStringContainsString('/admin/help/shipping#'.HelpLink::anchor($anchor), $form, $anchor);
            $this->assertStringContainsString('/admin/help/shipping#'.HelpLink::anchor($anchor), $edit, $anchor);

            foreach (['en', 'bg'] as $locale) {
                $markdown = (string) file_get_contents(resource_path("help/{$locale}/shipping.md"));
                $this->assertStringContainsString('{#'.HelpLink::anchor($anchor).'}', $markdown, "{$locale}: {$anchor}");
            }
        }

        $this->assertStringContainsString('/admin/help/shipping#action-method-editor', $list);

        foreach ([$form, $edit, $list] as $html) {
            preg_match_all('/<a\s[^>]*href="[^"]*help\/shipping#([a-z0-9-]+)"[^>]*>/', $html, $links, PREG_SET_ORDER);
            $this->assertNotEmpty($links);

            foreach ($links as $link) {
                $this->assertStringContainsString('target="_blank"', $link[0]);
                $this->assertStringContainsString('rel="noopener noreferrer"', $link[0]);
            }
        }

        // The copy dialog links to its own anchor too.
        $modal = Livewire::test(ListShippingMethods::class)->mountTableAction('copy_method', $this->model((string) $method->id()))->getMountedActionModalHtml();
        $this->assertStringContainsString('/admin/help/shipping#action-method-copy', $modal);
    }

    public function test_the_overview_links_to_the_methods_list_and_its_try_it_names_the_class_mode(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('discount', 'Discount');
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->perClassMethod($zone, 'Adjusting', ['heavy' => 2500, 'discount' => -300], ShippingClassMode::ADJUST);
        $this->perClassMethod($zone, 'Replacing', ['heavy' => 3000]);
        $this->method($zone, 'Plain flat');
        $this->actingAsStaff('Administrator');

        $overview = $this->get(ShippingOverview::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString('href="'.ShippingMethodResource::getUrl('index').'"', $overview);
        $this->assertStringContainsString('Edit methods', $overview);

        $result = Livewire::test(ShippingOverview::class)
            ->set('country', 'BG')->set('goods', '10.00')
            ->set('lines', [['class' => 'heavy', 'quantity' => 1], ['class' => 'discount', 'quantity' => 1]])
            ->call('run')
            ->html();

        $this->assertStringContainsString(e(__('shipping.class_mode.adjust')), $result);
        $this->assertStringContainsString(e(__('shipping.class_mode.replace')), $result);
        $this->assertStringContainsString(e('Price: '), $result);

        App::setLocale('bg');
        $this->assertSame('Редакция на методите', __('shipping.overview.edit_methods'));
    }

    public function test_the_adjust_price_the_try_it_shows_is_the_real_calculators(): void
    {
        $this->shippingClass('heavy', 'Heavy');
        $this->shippingClass('discount', 'Discount');
        $zone = (string) $this->zone('Bulgaria', 0)->id();
        $this->perClassMethod($zone, 'Adjusting', ['heavy' => 2500, 'discount' => -300], ShippingClassMode::ADJUST);
        $this->actingAsStaff('Administrator');

        $result = app(\App\Services\ShippingTester::class)->run('BG', null, null, false, 1000, [
            new \EasyCo\Shipping\Rating\RateLine('heavy', 1), new \EasyCo\Shipping\Rating\RateLine('discount', 1), new \EasyCo\Shipping\Rating\RateLine(null, 1),
        ]);

        $this->assertSame(2700, $result->methods[0]->amountMinor, 'base 5.00 + 25.00 − 3.00 = 27.00, the owner\'s example');
        $this->assertSame('adjust', $result->methods[0]->classMode);
    }
}
