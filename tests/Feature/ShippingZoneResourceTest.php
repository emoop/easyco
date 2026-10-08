<?php

namespace Tests\Feature;

use App\Filament\Pages\ShippingOverview;
use App\Filament\Resources\ShippingZoneResource;
use App\Filament\Resources\ShippingZoneResource\Pages\CreateShippingZone;
use App\Filament\Resources\ShippingZoneResource\Pages\EditShippingZone;
use App\Filament\Resources\ShippingZoneResource\Pages\ListShippingZones;
use App\Filament\Support\HelpLink;
use App\Services\ShippingZoneCoverageReader;
use App\Services\SettlementNormalizerResolver;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Shipping\Contracts\ShippingZoneRepository;
use EasyCo\Shipping\Matching\PostcodeNormalizer;
use EasyCo\Shipping\Persistence\Eloquent\ShippingZoneModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * Stage 5c (shipping-domain-design.md §12.3.2): the zones screens — who may open them, the ordered list, the
 * create / edit forms with the matcher's own normalisation shown, Move up / Move down and Delete, the help links,
 * and the bounded number of queries. Every write is a service call, so the effects below are the services'.
 */
class ShippingZoneResourceTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private function model(string $id): ShippingZoneModel
    {
        return ShippingZoneModel::findOrFail($id);
    }

    // =====================================================================================================
    // Who may open it
    // =====================================================================================================

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $response = $this->get(ShippingZoneResource::getUrl('index'));

        $response->assertRedirect();
        $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_administrator_and_manager_may_open_every_page(): void
    {
        $zone = $this->zone('Z', 0);

        foreach (['Administrator', 'Manager'] as $role) {
            $this->actingAsStaff($role);

            $this->get(ShippingZoneResource::getUrl('index'))->assertOk();
            $this->get(ShippingZoneResource::getUrl('create'))->assertOk();
            $this->get(ShippingZoneResource::getUrl('edit', ['record' => $zone->id()]))->assertOk();
        }
    }

    public function test_without_shipping_manage_every_page_is_a_403_and_the_item_is_not_in_the_navigation(): void
    {
        $zone = $this->zone('Z', 0);

        foreach ([fn () => $this->actingAsStaff('Product Entry'), fn () => $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::PRODUCT_VIEW])] as $actAs) {
            $actAs();

            $this->get(ShippingZoneResource::getUrl('index'))->assertForbidden();
            $this->get(ShippingZoneResource::getUrl('create'))->assertForbidden();
            $this->get(ShippingZoneResource::getUrl('edit', ['record' => $zone->id()]))->assertForbidden();

            // Absent from the navigation: Filament builds the sidebar only from resources the staff member may access.
            $this->assertFalse(ShippingZoneResource::canAccess());
            $this->assertStringNotContainsString('/admin/shipping-zones', $this->get(\App\Filament\Pages\Help::getUrl())->assertOk()->getContent());
            $this->assertFalse(ShippingZoneResource::canViewAny());
            $this->assertFalse(ShippingZoneResource::canCreate());
            $this->assertFalse(ShippingZoneResource::canEdit($this->model((string) $zone->id())));
            $this->assertFalse(ShippingZoneResource::canDelete($this->model((string) $zone->id())));
        }
    }

    public function test_the_navigation_item_is_in_the_shipping_group_right_after_the_shipping_page_and_costs_no_query(): void
    {
        $this->actingAsStaff('Administrator');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $items = ShippingZoneResource::getNavigationItems();

        $this->assertCount(1, $items);
        $this->assertSame(20, ShippingZoneResource::getNavigationSort());
        $this->assertSame(10, ShippingOverview::getNavigationSort());
        $this->assertSame(\App\Filament\NavigationGroup::SHIPPING, ShippingZoneResource::getNavigationGroup());
        $this->assertSame(0, $queries, 'the navigation item adds no query to any page');
    }

    public function test_livewire_refuses_to_mount_a_page_without_the_permission(): void
    {
        $zone = $this->zone('Z', 0);
        $this->actingAsStaff('Product Entry');

        Livewire::test(ListShippingZones::class)->assertForbidden();
        Livewire::test(CreateShippingZone::class)->assertForbidden();
        Livewire::test(EditShippingZone::class, ['record' => $zone->id()])->assertForbidden();
    }

    public function test_the_pages_expose_no_public_method_that_could_reach_a_service_around_the_check(): void
    {
        // A Livewire component's public methods are callable from the browser. Whatever the pages declare themselves must
        // be a pure read; every write is a Filament action (visible only with shipping_manage) that calls a service.
        $allowed = ['getTitle', 'getSubheading', 'mount'];

        foreach ([ListShippingZones::class, CreateShippingZone::class, EditShippingZone::class] as $page) {
            $own = array_filter(
                (new \ReflectionClass($page))->getMethods(\ReflectionMethod::IS_PUBLIC),
                static fn (\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $page && ! $method->isStatic(),
            );

            foreach ($own as $method) {
                $this->assertContains($method->getName(), $allowed, "{$page}::{$method->getName()} is a public Livewire method");
            }
        }

        foreach ([\App\Services\ShippingZoneWriter::class, \App\Services\ShippingZoneReorderer::class, ShippingZoneCoverageReader::class] as $service) {
            $this->assertFalse(is_subclass_of($service, \Livewire\Component::class), "{$service} is not a component");
        }
    }

    public function test_a_hidden_table_action_cannot_be_called_by_an_unauthorised_staff_member(): void
    {
        // A custom role that may view the Shipping page's permission... but not this one: it never reaches the table.
        $zone = $this->zone('Z', 0);
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        Livewire::test(ListShippingZones::class)->assertForbidden();
        $this->assertSame(1, DB::table('shipping_zones')->count());
        $this->assertNotNull(app(ShippingZoneRepository::class)->findById((string) $zone->id()));
    }

    // =====================================================================================================
    // The list
    // =====================================================================================================

    public function test_the_list_shows_the_zones_in_match_order_with_numbers_names_coverage_and_method_counts(): void
    {
        $this->zone('Third by id, first by order', 0, ['BG']);
        $second = $this->zone('Sofia', 1, ['BG'], ['София', 'Пловдив'], ['1000', '1001']);
        $this->zone('Rest', 2, ['RO', 'GR']);
        $this->method((string) $second->id(), 'One');
        $this->method((string) $second->id(), 'Two');
        $this->actingAsStaff('Administrator');

        $zones = ShippingZoneModel::query()->orderBy('sort_order')->orderBy('id')->get();

        Livewire::test(ListShippingZones::class)
            ->assertCanSeeTableRecords($zones, inOrder: true)
            ->assertSeeInOrder(['Third by id, first by order', 'Sofia', 'Rest'])
            ->assertSee(app(ShippingZoneCoverageReader::class)->sentence(app(ShippingZoneRepository::class)->findById((string) $second->id())))
            ->assertSee('2 methods')
            ->assertSee('0 methods');
    }

    public function test_the_coverage_sentence_is_the_one_the_overview_shows(): void
    {
        $zone = $this->zone('Narrow', 0, ['BG', 'RO'], ['гр. София'], ['1000', '1001', '1002']);
        $this->actingAsStaff('Administrator');

        $expected = app(ShippingZoneCoverageReader::class)->sentence($zone);
        $this->assertStringContainsString('only postcodes: 3', $expected);

        $list = Livewire::test(ListShippingZones::class)->assertSee($expected)->html();
        $overview = $this->get(ShippingOverview::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString(e($expected), $overview);
        $this->assertStringContainsString(e($expected), $list);

        // The reader gives the same sentence from the stored lists a table row holds.
        $this->assertSame($expected, app(ShippingZoneCoverageReader::class)->sentenceFor(['BG', 'RO'], ['гр. София'], ['1000', '1001', '1002']));
    }

    public function test_the_list_shows_the_match_order_note_and_the_help_link_in_both_languages(): void
    {
        $this->actingAsStaff('Administrator');

        $en = Livewire::test(ListShippingZones::class)
            ->assertSee('The first zone from the top that matches the address wins. Narrow zones (with settlements or postcodes) go above broader ones.')
            ->html();
        $this->assertStringContainsString('/admin/help/shipping#action-zone-order', $en);

        App::setLocale('bg');
        Livewire::test(ListShippingZones::class)
            ->assertSee('Първата зона отгоре надолу, която съвпада с адреса, печели. Тесните зони (с населени места или пощенски кодове) се слагат над по-широките.');
    }

    public function test_the_list_reads_a_fixed_number_of_queries_for_3_and_12_zones(): void
    {
        $this->actingAsStaff('Administrator');

        for ($i = 1; $i <= 3; $i++) {
            $zone = $this->zone("Zone {$i}", $i - 1, ['BG'], ["Town {$i}"], ["10{$i}0"]);
            $this->method((string) $zone->id(), "Method {$i}");
        }

        $three = $this->measureList();

        for ($i = 4; $i <= 12; $i++) {
            $zone = $this->zone("Zone {$i}", $i - 1, ['BG'], ["Town {$i}"], ["10{$i}0"]);
            $this->method((string) $zone->id(), "Method {$i}");
        }

        $twelve = $this->measureList();

        fwrite(STDERR, sprintf(
            "\n[query-count] shipping zones list: 3 zones %d shipping reads (zones %d, methods %d, rates %d; %d total), 12 zones %d shipping reads (zones %d, methods %d, rates %d; %d total)\n",
            $three['shipping'], $three['zones'], $three['methods'], $three['rates'], $three['total'],
            $twelve['shipping'], $twelve['zones'], $twelve['methods'], $twelve['rates'], $twelve['total'],
        ));

        $this->assertSame(1, $twelve['zones'], 'the zones: one read, in match order');
        $this->assertSame(1, $twelve['methods'], 'the methods of ALL listed zones: one grouped read');
        $this->assertSame($three, $twelve, 'the same queries for 3 and for 12 zones — never per row');
    }

    /** @return array{total: int, shipping: int, zones: int, methods: int, rates: int} */
    private function measureList(): array
    {
        $this->app->forgetScopedInstances();
        $counts = ['total' => 0, 'shipping' => 0, 'zones' => 0, 'methods' => 0, 'rates' => 0];

        DB::listen(function ($query) use (&$counts): void {
            $counts['total']++;

            if (str_contains($query->sql, 'shipping_zones')) {
                $counts['zones']++;
            } elseif (str_contains($query->sql, 'shipping_method_class_rates')) {
                $counts['rates']++;
            } elseif (str_contains($query->sql, 'shipping_methods')) {
                $counts['methods']++;
            }
        });

        Livewire::test(ListShippingZones::class)->assertOk();

        $counts['shipping'] = $counts['zones'] + $counts['methods'] + $counts['rates'];

        // Stop counting for the next measurement (a listener cannot be removed; a fresh array is closed over per call).
        return $counts;
    }

    public function test_the_row_actions_have_explicit_labels_in_en_and_bg(): void
    {
        $zone = $this->zone('A', 0);
        $this->zone('B', 1);
        $this->actingAsStaff('Administrator');

        foreach (['en' => ['Actions', 'Edit', 'Move up', 'Move down', 'Delete'], 'bg' => ['Действия', 'Редакция', 'Нагоре', 'Надолу', 'Изтрий']] as $locale => $labels) {
            App::setLocale($locale);
            $html = Livewire::test(ListShippingZones::class)->html();

            foreach ($labels as $label) {
                $this->assertStringContainsString($label, $html, "{$locale}: {$label}");
            }
        }

        $this->assertNotNull($zone->id());
    }

    public function test_the_first_row_has_no_move_up_and_the_last_has_no_move_down(): void
    {
        $first = $this->zone('First', 0);
        $middle = $this->zone('Middle', 1);
        $last = $this->zone('Last', 2);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(ListShippingZones::class);

        $page->assertTableActionHidden('move_up', $this->model((string) $first->id()))
            ->assertTableActionVisible('move_down', $this->model((string) $first->id()))
            ->assertTableActionVisible('move_up', $this->model((string) $middle->id()))
            ->assertTableActionVisible('move_down', $this->model((string) $middle->id()))
            ->assertTableActionVisible('move_up', $this->model((string) $last->id()))
            ->assertTableActionHidden('move_down', $this->model((string) $last->id()));
    }

    // =====================================================================================================
    // Move and delete
    // =====================================================================================================

    public function test_move_up_and_move_down_change_the_order_through_the_service(): void
    {
        $this->activityLogOn();
        $a = $this->zone('A', 0);
        $b = $this->zone('B', 1);
        $this->zone('C', 2);
        $this->actingAsStaff('Administrator');

        $page = Livewire::test(ListShippingZones::class);

        $page->callTableAction('move_up', $this->model((string) $b->id()))->assertNotified(__('shipping.zones.notice.moved'));
        $this->assertSame(['B', 'A', 'C'], $this->zoneNames());

        $page->callTableAction('move_down', $this->model((string) $a->id()));
        $this->assertSame(['B', 'C', 'A'], $this->zoneNames());
        $this->assertSame([0, 1, 2], $this->sortOrders());
        $this->assertCount(2, array_filter($this->auditRows(), fn ($row) => $row->field === 'order'), 'one audit entry per move');
    }

    public function test_the_delete_dialog_has_explicit_buttons_in_en_and_bg_and_never_the_defaults(): void
    {
        $zone = $this->zone('Gone soon', 0);
        $this->actingAsStaff('Administrator');

        foreach (['en' => ['Delete the zone', 'Close'], 'bg' => ['Изтрий зоната', 'Затвори']] as $locale => [$submit, $close]) {
            App::setLocale($locale);

            $modal = preg_replace('/\s+/', ' ', Livewire::test(ListShippingZones::class)
                ->mountTableAction('delete_zone', $this->model((string) $zone->id()))
                ->getMountedActionModalHtml());

            $this->assertStringContainsString($submit, $modal, $locale);
            $this->assertStringContainsString($close, $modal, $locale);
            $this->assertStringContainsString('Gone soon', $modal);
            $this->assertDoesNotMatchRegularExpression('/>\s*(Изпрати|Откажи|Submit|Cancel|Confirm|Потвърди)\s*</u', $modal, "{$locale}: no default button");
        }
    }

    public function test_deleting_an_empty_zone_removes_it_with_one_audit_snapshot_and_one_hook(): void
    {
        $zone = $this->zone('Empty', 0);
        $this->actingAsStaff('Administrator');
        $this->spyOnZoneHooks();

        Livewire::test(ListShippingZones::class)
            ->callTableAction('delete_zone', $this->model((string) $zone->id()))
            ->assertNotified(__('shipping.zones.notice.deleted'));

        $this->assertSame(0, DB::table('shipping_zones')->count());
        $this->assertCount(1, $this->auditRows());
        $this->assertSame(['shipping.zone.deleted'], array_map(fn (array $call): string => $call[0], $this->hookCalls));
    }

    public function test_deleting_a_zone_with_methods_shows_the_translated_notice_and_writes_nothing(): void
    {
        $zone = $this->zone('Busy', 0);
        $this->method((string) $zone->id(), 'One');
        $this->method((string) $zone->id(), 'Two');
        $this->actingAsStaff('Administrator');
        $this->spyOnZoneHooks();

        Livewire::test(ListShippingZones::class)
            ->callTableAction('delete_zone', $this->model((string) $zone->id()))
            ->assertNotified(__('shipping.zones.notice.refused'));

        $this->assertSame(1, DB::table('shipping_zones')->count());
        $this->assertSame(2, DB::table('shipping_methods')->count());
        $this->assertSame([], $this->auditRows());
        $this->assertSame([], $this->hookCalls);
    }

    // =====================================================================================================
    // The forms
    // =====================================================================================================

    public function test_creating_a_zone_through_the_form_stores_it_at_the_end(): void
    {
        $this->zone('Existing', 4);
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'Sofia', 'country_codes' => ['BG'], 'settlement_names' => ['гр. София'], 'postcodes' => ['sw1a 1aa']])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.zones.notice.created'));

        $created = app(ShippingZoneRepository::class)->allOrdered()[1];

        $this->assertSame('Sofia', $created->name());
        $this->assertSame(5, $created->sortOrder());
        $this->assertSame(['гр. София'], $created->settlementNames());
        $this->assertSame(['SW1A1AA'], $created->postcodes());
    }

    public function test_the_forms_have_no_order_field(): void
    {
        $this->actingAsStaff('Administrator');

        $this->assertStringNotContainsString('sort_order', Livewire::test(CreateShippingZone::class)->html());
        $this->assertStringNotContainsString('sortOrder', Livewire::test(CreateShippingZone::class)->html());
    }

    public function test_service_refusals_land_on_the_right_form_field_and_write_nothing(): void
    {
        $this->actingAsStaff('Administrator');

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'X', 'country_codes' => ['BG'], 'postcodes' => ['!!']])
            ->call('create')
            ->assertHasFormErrors(['postcodes']);

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => "Bad\u{202E}name", 'country_codes' => ['BG']])
            ->call('create')
            ->assertHasFormErrors(['name']);

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'X', 'country_codes' => ['BG'], 'postcodes' => ['ab 12', 'AB12']])
            ->call('create')
            ->assertHasFormErrors(['postcodes']);

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'X', 'country_codes' => ['BG'], 'settlement_names' => ["line\nbreak"]])
            ->call('create')
            ->assertHasFormErrors(['settlement_names']);

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'X', 'country_codes' => ['BG'], 'settlement_names' => array_map(fn (int $i): string => "Town {$i}", range(1, 501))])
            ->call('create')
            ->assertHasFormErrors(['settlement_names']);

        Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'X', 'country_codes' => []])
            ->call('create')
            ->assertHasFormErrors(['country_codes']);

        $this->assertSame(0, DB::table('shipping_zones')->count());
        $this->assertSame([], $this->auditRows());
    }

    public function test_editing_a_zone_fills_the_form_round_trips_it_and_keeps_its_place(): void
    {
        $this->zone('First', 0);
        $zone = $this->zone('Second', 1, ['BG'], ['София'], ['1000']);
        $this->zone('Third', 2);
        $this->actingAsStaff('Administrator');

        Livewire::test(EditShippingZone::class, ['record' => $zone->id()])
            ->assertFormSet(['name' => 'Second', 'country_codes' => ['BG'], 'settlement_names' => ['София'], 'postcodes' => ['1000']])
            ->fillForm(['name' => 'Second renamed', 'country_codes' => ['BG', 'RO'], 'settlement_names' => ['Пловдив'], 'postcodes' => []])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('shipping.zones.notice.updated'));

        $stored = app(ShippingZoneRepository::class)->findById((string) $zone->id());
        $this->assertSame('Second renamed', $stored->name());
        $this->assertSame(['BG', 'RO'], $stored->countryCodes());
        $this->assertSame(['Пловдив'], $stored->settlementNames());
        $this->assertNull($stored->postcodes());
        $this->assertSame(['First', 'Second renamed', 'Third'], $this->zoneNames(), 'the order did not change');
        $this->assertSame([0, 1, 2], $this->sortOrders());
    }

    public function test_the_edit_page_reads_one_zone(): void
    {
        $zone = $this->zone('One', 0, ['BG'], ['София'], ['1000']);
        $this->zone('Other', 1);
        $this->actingAsStaff('Administrator');

        $zoneReads = 0;
        DB::listen(function ($query) use (&$zoneReads): void {
            if (str_contains($query->sql, 'shipping_zones')) {
                $zoneReads++;
            }
        });

        Livewire::test(EditShippingZone::class, ['record' => $zone->id()])->assertOk();

        fwrite(STDERR, sprintf("\n[query-count] shipping zone edit page: %d shipping_zones read(s)\n", $zoneReads));

        $this->assertSame(1, $zoneReads);
    }

    // =====================================================================================================
    // The matcher's normalisation, shown
    // =====================================================================================================

    public function test_the_preview_shows_what_the_matcher_compares_for_a_bulgarian_settlement_and_a_postcode(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        App::setLocale('bg'); // Livewire tests skip the panel middleware that applies the store locale to the app
        $this->actingAsStaff('Administrator');

        $normaliser = app(SettlementNormalizerResolver::class)->forCurrentLocale();
        $expectedSettlement = $normaliser->normalize('гр. София');
        $expectedPostcode = PostcodeNormalizer::normalize('sw1a 1aa');

        $this->assertSame('софия', $expectedSettlement, 'the Bulgarian rules strip the type prefix');
        $this->assertSame('SW1A1AA', $expectedPostcode);

        $html = Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'Preview', 'country_codes' => ['BG'], 'settlement_names' => ['гр. София'], 'postcodes' => ['sw1a 1aa']])
            ->html();

        $this->assertStringContainsString(e('Населени места: гр. София → софия'), $html);
        $this->assertStringContainsString(e('Пощенски кодове: sw1a 1aa → SW1A1AA'), $html);
    }

    public function test_the_preview_is_bounded_to_20_entries_then_says_how_many_more_and_escapes_what_was_typed(): void
    {
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'Preview', 'country_codes' => ['BG'], 'settlement_names' => array_map(fn (int $i): string => "Town {$i}", range(1, 25))])
            ->html();

        $this->assertStringContainsString('Town 20', $html);
        $this->assertStringNotContainsString('Town 21 →', $html);
        $this->assertStringContainsString('and 5 more', $html);

        $xss = Livewire::test(CreateShippingZone::class)
            ->fillForm(['name' => 'Preview', 'country_codes' => ['BG'], 'settlement_names' => ['<script>alert(1)</script>']])
            ->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $xss);
    }

    public function test_zone_names_are_escaped_on_the_list(): void
    {
        $this->zone('<b>Bold</b> & <script>alert(1)</script>', 0);
        $this->actingAsStaff('Administrator');

        $html = Livewire::test(ListShippingZones::class)->html();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>Bold</b>', $html);
    }

    // =====================================================================================================
    // Help links and the overview's link
    // =====================================================================================================

    public function test_the_form_and_the_list_link_to_help_anchors_that_exist_in_both_shipping_help_files(): void
    {
        $zone = $this->zone('Z', 0);
        $this->actingAsStaff('Administrator');

        $pages = [
            Livewire::test(CreateShippingZone::class)->html(),
            Livewire::test(EditShippingZone::class, ['record' => $zone->id()])->html(),
        ];

        foreach ($pages as $html) {
            foreach (['zone_editor', 'zone_settlement_matching'] as $anchor) {
                $this->assertStringContainsString('/admin/help/shipping#'.HelpLink::anchor($anchor), $html);
            }
        }

        $list = Livewire::test(ListShippingZones::class)->html();
        $this->assertStringContainsString('/admin/help/shipping#'.HelpLink::anchor('zone_order'), $list);

        foreach ([$pages[0], $pages[1], $list] as $html) {
            preg_match_all('/<a\s[^>]*href="[^"]*help\/shipping#([a-z0-9-]+)"[^>]*>/', $html, $links, PREG_SET_ORDER);
            $this->assertNotEmpty($links);

            foreach ($links as $link) {
                $this->assertStringContainsString('target="_blank"', $link[0]);
                $this->assertStringContainsString('rel="noopener noreferrer"', $link[0]);
            }
        }

        foreach (['en', 'bg'] as $locale) {
            $markdown = (string) file_get_contents(resource_path("help/{$locale}/shipping.md"));

            foreach (['zone_editor', 'zone_settlement_matching', 'zone_order'] as $anchor) {
                $this->assertStringContainsString('{#'.HelpLink::anchor($anchor).'}', $markdown, "{$locale}: {$anchor}");
            }
        }
    }

    public function test_the_overview_links_to_the_zones_list(): void
    {
        $this->actingAsStaff('Administrator');

        $html = $this->get(ShippingOverview::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('href="'.ShippingZoneResource::getUrl('index').'"', $html);
        $this->assertStringContainsString('Edit zones', $html);

        App::setLocale('bg');
        $this->assertSame('Редакция на зоните', __('shipping.overview.edit_zones'));
    }
}
