<?php

namespace Tests\Feature;

use App\Filament\NavigationGroup;
use App\Filament\Pages\NeedsAttention;
use App\Filament\Resources\OrderResource;
use App\Filament\StaffPanelUser;
use App\NeedsAttention\NeedsAttentionSource;
use App\NeedsAttention\PaymentStepUnfinishedSource;
use App\Settings\Contracts\SiteSettingsRepository;
use App\Settings\StoreTimezone;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use EasyCo\Payment\Contracts\PaymentRepository;
use EasyCo\Payment\Enums\PaymentStatus;
use EasyCo\Payment\Payment;
use EasyCo\Pricing\Money;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBankTransferOrders;
use Tests\TestCase;

/**
 * "Needs attention" (shipping-domain-design.md §7.2.20 §6): the read-only page that lists money
 * waiting on someone — refunds still OWED, bank transfers whose effective receipts do not add up —
 * oldest first, DERIVED on every read.
 *
 * Everything here drives the page's own real GETs, because that is the screen's whole interaction:
 * each section carries its own count and its own pagination, and those links are plain GETs (one
 * render per section, no Livewire round trip), so a test that types a URL tests what ships.
 *
 * The order the tests are written in is the order the promises are made in: WHO MAY SEE IT, then the
 * two things a row may say (the fact, then the wait), then one page per source, then the two costs the
 * page promises (one count plus one page per source, and no write at all).
 */
class NeedsAttentionPageTest extends TestCase
{
    use BuildsBankTransferOrders;
    use RefreshDatabase;

    // =====================================================================================================
    // Fixtures and helpers
    // =====================================================================================================

    /** A panel staff member holding a seeded system role (this project's established fixture shape). */
    private function actingAsStaff(string $roleName): StaffPanelUser
    {
        $roles = app(RoleRepository::class);
        $role = $roles->findSystemRoleByName($roleName);

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run($roles);
            $role = $roles->findSystemRoleByName($roleName);
        }

        $staff = Staff::create(Str::slug($roleName).'-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), $roleName.' Tester', $role);
        app(StaffRepository::class)->save($staff);

        return $this->actingAsPanelUser($staff->id());
    }

    /** @param list<Permission> $permissions */
    private function actingAsCustomRole(array $permissions): StaffPanelUser
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);

        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);

        return $this->actingAsPanelUser($staff->id());
    }

    private function actingAsPanelUser(string $staffId): StaffPanelUser
    {
        $model = StaffPanelUser::find($staffId);
        $this->actingAs($model, 'staff');
        session()->forget('password_hash_staff');

        return $model;
    }

    /** The page under test, as a real GET, with an optional query string (one section's own page). */
    private function visit(string $query = ''): TestResponse
    {
        return $this->get(NeedsAttention::getUrl().$query);
    }

    /**
     * The rendered HTML of ONE section, so an assertion about one source can never accidentally read
     * another source's rows (the page draws one section per tagged source).
     */
    private function section(string $html, string $key): string
    {
        $start = strpos($html, 'id="na-section-'.$key.'"');
        $this->assertNotFalse($start, "the {$key} section is on the page");

        $rest = substr($html, (int) $start);
        $end = strpos($rest, '<section class="na-section"', 1);

        return $end === false ? $rest : substr($rest, 0, $end);
    }

    /** How many rows one section draws (one link to an order per row). */
    private function rowsIn(string $section): int
    {
        return substr_count($section, 'class="na-link"');
    }

    /** The one <tr> block that contains $needle — so "THESE facts are on ONE row" can be asserted. */
    private function rowContaining(string $section, string $needle): string
    {
        $at = strpos($section, $needle);
        $this->assertNotFalse($at, "{$needle} is drawn on the page");

        $start = strrpos(substr($section, 0, (int) $at), '<tr>');
        $end = strpos($section, '</tr>', (int) $at);
        $this->assertNotFalse($start, "the row around {$needle} opens");
        $this->assertNotFalse($end, "the row around {$needle} closes");

        return substr($section, (int) $start, (int) $end - (int) $start);
    }

    /** The first data row of a section (its <tbody>), for the tests that wait on exactly one row. */
    private function firstRow(string $section): string
    {
        $start = strpos($section, '<tbody>');
        $this->assertNotFalse($start, 'the section draws a table body');

        return substr($section, (int) $start);
    }

    /**
     * One owed refund so the page is not globally empty — a page with nothing at all says one thing for
     * the whole page and draws no sections, which is a different assertion (its own test) from "this
     * section is empty".
     */
    private function keepThePageAlive(): void
    {
        $this->owedRefundRow('order-still-waiting', 700, $this->instantOn($this->storeDayAgo(1)));
    }

    /** Today, in the STORE's own timezone — the day every age on this page is counted from. */
    private function storeToday(): string
    {
        return app(StoreTimezone::class)->today();
    }

    /** The store-local calendar day N whole days before today: the input side of every age assertion. */
    private function storeDayAgo(int $days): string
    {
        return (new DateTimeImmutable($this->storeToday(), new DateTimeZone('UTC')))
            ->sub(new DateInterval('P'.$days.'D'))
            ->format('Y-m-d');
    }

    /** The UTC instant ('Y-m-d H:i:s' — the app's storage rule) whose STORE-LOCAL day is $day. */
    private function instantOn(string $day, string $time = '12:00:00'): string
    {
        return (new DateTimeImmutable("{$day} {$time}", app(StoreTimezone::class)->zone()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * One refund row straight into payment_refunds — the raw-insert shape EloquentPaymentRefundRepositoryTest
     * already uses, because this source reads payment_refunds and nothing else (no lines, no aggregate
     * rules, no order), and the breakdown CHECK constraints are satisfied by construction here.
     */
    private function owedRefundRow(string $orderId, int $minor, string $createdAt, string $channel = 'cash', string $status = 'owed'): void
    {
        DB::table('payment_refunds')->insert([
            'payment_id' => 'payment-'.Str::uuid(),
            'order_id' => $orderId,
            'amount_minor' => $minor,
            'amount_currency' => 'EUR',
            'channel' => $channel,
            'goods_minor' => $minor,
            'shipping_minor' => 0,
            'adjustment_minor' => 0,
            'deduction_minor' => 0,
            'deduction_reason' => null,
            'reason' => null,
            'refunded_by' => null,
            'status' => $status,
            'failure_reason' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** A saved, ANSWERED pending payment for an order that has none yet (the method/currency edges). */
    private function pendingPayment(string $orderId, string $method, int $minor, string $currency = 'EUR'): Payment
    {
        $payment = Payment::create($orderId, $method, Money::fromMinorUnits($minor, $currency), PaymentStatus::PENDING);
        $payment->recordAttemptResult(PaymentStatus::PENDING, null, null, new DateTimeImmutable('2026-09-28 09:00:00'));
        app(PaymentRepository::class)->save($payment);

        return $payment;
    }

    // =====================================================================================================
    // Who may see it — Permission::ORDER_VIEW, checked at canAccess() AND at mount()
    // =====================================================================================================

    public function test_the_page_lists_what_is_waiting_for_a_staff_member_who_may_read_orders(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->refundableOrder([['quantity' => 1, 'unit' => 1000]]);
        $this->owedRefundRow($order['orderId'], 2500, $this->instantOn($this->storeDayAgo(2)));

        $response = $this->visit();

        $response->assertOk();
        $html = $response->getContent();

        // The page's own words: its title, its one intro line, and one heading per source.
        $this->assertStringContainsString(__('needs_attention.title'), $html);
        $this->assertStringContainsString(__('needs_attention.intro'), $html);
        $this->assertStringContainsString(__('needs_attention.sources.owed_refund.label'), $html);
        $this->assertStringContainsString(__('needs_attention.sources.receipt_mismatch.label'), $html);

        // ...and the four column names, because a listing with no headers is not a listing.
        foreach (['order', 'fact', 'amount', 'waiting'] as $column) {
            $this->assertStringContainsString(__('needs_attention.columns.'.$column), $html);
        }
    }

    public function test_the_page_refuses_a_staff_member_whose_role_does_not_grant_the_orders_read_permission(): void
    {
        $this->actingAsCustomRole([Permission::PRODUCT_VIEW, Permission::PRODUCT_MANAGE]);

        $this->visit()->assertForbidden();
    }

    public function test_the_page_refuses_the_seeded_product_entry_role_which_has_no_orders_permission_at_all(): void
    {
        $this->actingAsStaff('Product Entry');

        $this->visit()->assertForbidden();
    }

    public function test_a_guest_cannot_read_the_page(): void
    {
        $response = $this->visit();

        $this->assertContains($response->getStatusCode(), [302, 403]);
        $response->getStatusCode() === 302 && $this->assertStringContainsString('login', (string) $response->headers->get('Location'));
    }

    public function test_the_page_sits_in_the_sales_group_right_after_orders(): void
    {
        $this->assertSame(NavigationGroup::SALES, NeedsAttention::getNavigationGroup());
        $this->assertSame(20, NeedsAttention::getNavigationSort());
        $this->assertSame(10, OrderResource::getNavigationSort());
        $this->assertSame(25, NeedsAttention::PER_PAGE);
    }

    public function test_the_navigation_offers_the_page_to_a_staff_member_who_may_read_orders(): void
    {
        $path = parse_url(NeedsAttention::getUrl(), PHP_URL_PATH);
        $this->assertIsString($path);

        $this->actingAsStaff('Administrator');
        $offered = $this->get(url('/admin'))->assertOk()->getContent();

        $this->assertStringContainsString($path, $offered, 'the sidebar links the page');
        $this->assertStringContainsString(NeedsAttention::getNavigationLabel(), $offered);
        // The label alone is not the promise: it must be a link, on this page's own URL.
        $this->assertStringContainsString('href="'.NeedsAttention::getUrl().'"', $offered);
    }

    /**
     * Its own test, not a second request in the one above: Filament builds one navigation per panel
     * INSTANCE, and a panel built during the first request of a test stays built for the rest of that
     * test — so a link earned by the Administrator would still be on the sidebar when the Product
     * Entry merchant loads the panel in the same process. One request per test, one panel per request.
     */
    public function test_the_navigation_withholds_the_page_from_a_staff_member_who_may_not_read_orders(): void
    {
        $path = parse_url(NeedsAttention::getUrl(), PHP_URL_PATH);
        $this->assertIsString($path);

        $this->actingAsStaff('Product Entry');
        $withheld = $this->get(url('/admin'))->assertOk()->getContent();

        $this->assertStringNotContainsString($path, $withheld, 'a merchant who may not read orders is never told about this page');
        $this->assertStringNotContainsString(NeedsAttention::getNavigationLabel(), $withheld);
    }

    public function test_the_three_sources_are_registered_under_the_page_s_own_tag_in_the_stated_order(): void
    {
        $sources = iterator_to_array(app()->tagged(NeedsAttentionSource::TAG));

        $this->assertCount(3, $sources);
        $this->assertSame(['owed_refund', 'receipt_mismatch', 'payment_step_unfinished'], array_map(fn (NeedsAttentionSource $source): string => $source->key(), array_values($sources)));
    }

    // =====================================================================================================
    // Refunds owed — the fact says which channel, the wait says how long, the oldest is first
    // =====================================================================================================

    public function test_owed_refunds_are_listed_oldest_first_with_their_channel_amount_and_wait(): void
    {
        $this->actingAsStaff('Administrator');
        // 5 × 20.00 = 100.00: the order has room for three refunds, so the list is about the refunds
        // and not about a cap.
        $order = $this->refundableOrder([['quantity' => 5, 'unit' => 2000]]);
        $orderId = $order['orderId'];

        $this->owedRefundRow($orderId, 3000, $this->instantOn($this->storeDayAgo(5)), 'bank');
        $this->owedRefundRow($orderId, 1000, $this->instantOn($this->storeDayAgo(3)), 'cash');
        $this->owedRefundRow($orderId, 2000, $this->instantOn($this->storeToday()), 'cash');

        $html = $this->visit()->assertOk()->getContent();
        $section = $this->section($html, 'owed_refund');

        $this->assertSame(3, $this->rowsIn($section));
        $this->assertStringContainsString('<span class="na-count">(3)</span>', $section, 'the count is the section total, not the page size');

        $oldest = strpos($section, '<td class="na-right">30.00 €</td>');
        $middle = strpos($section, '<td class="na-right">10.00 €</td>');
        $newest = strpos($section, '<td class="na-right">20.00 €</td>');
        $this->assertNotFalse($oldest, 'the oldest refund is listed');
        $this->assertNotFalse($middle);
        $this->assertNotFalse($newest);
        $this->assertTrue($oldest < $middle && $middle < $newest, 'oldest first: 30.00 (5 days) above 10.00 (3 days) above 20.00 (today)');

        // One row, in full: the link to its order, its own fact, its own wait, and the day it began.
        $row = $this->rowContaining($section, '<td class="na-right">30.00 €</td>');
        $this->assertStringContainsString('href="'.OrderResource::getUrl('view', ['record' => $orderId]).'"', $row);
        $this->assertStringContainsString('>'.$orderId.'</a>', $row);
        $this->assertStringContainsString(__('needs_attention.sources.owed_refund.fact', ['channel' => __('needs_attention.channels.bank')]), $row);
        $this->assertStringContainsString('title="'.$this->storeDayAgo(5).'"', $row, 'the cell carries the day the merchant saw');
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 5), $row);

        // The two cash rows are two different waits on the same sentence — the whole point of the column.
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 3), $this->rowContaining($section, '<td class="na-right">10.00 €</td>'));
        $this->assertStringContainsString(__('needs_attention.today'), $this->rowContaining($section, '<td class="na-right">20.00 €</td>'));

        // ...and the link is a live link, not a painted one.
        $this->get(OrderResource::getUrl('view', ['record' => $orderId]))->assertOk();
    }

    public function test_an_owed_refund_in_a_channel_this_app_does_not_know_is_still_listed_and_named_honestly(): void
    {
        $this->actingAsStaff('Administrator');
        // A legacy row: the column is a free string, so one value the enum never heard of must not stop
        // the listing (and the row needs no order to exist — this source reads payment_refunds alone).
        $this->owedRefundRow('order-of-an-older-era', 500, $this->instantOn($this->storeToday()), 'voucher');

        $section = $this->section($this->visit()->assertOk()->getContent(), 'owed_refund');

        $this->assertSame(1, $this->rowsIn($section));
        $this->assertStringContainsString(
            __('needs_attention.sources.owed_refund.fact', ['channel' => __('needs_attention.channels.unknown')]),
            $section,
        );
    }

    public function test_a_refund_that_is_not_owed_any_more_is_not_waiting(): void
    {
        $this->actingAsStaff('Administrator');
        $day = $this->instantOn($this->storeDayAgo(4));

        // Every state the enum has other than OWED: paid out and cancelled moved the money (or withdrew
        // the decision), the four others belong to online refunds that are not offline money in hand.
        foreach (['paid_out', 'cancelled', 'failed', 'requested', 'completed'] as $status) {
            $this->owedRefundRow('order-'.$status, 1000, $day, 'cash', $status);
        }

        $this->assertSame(5, DB::table('payment_refunds')->count(), 'the fixture really wrote five non-owed rows');

        $html = $this->visit()->assertOk()->getContent();

        $this->assertStringContainsString(__('needs_attention.empty'), $html);
        $this->assertStringNotContainsString('<section class="na-section"', $html, 'no section, no count, no table');
    }

    public function test_the_wait_of_an_owed_refund_is_counted_in_the_store_s_calendar_not_in_utc(): void
    {
        $this->actingAsStaff('Administrator');
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');

        $earlyMorning = $this->instantOn($this->storeToday(), '01:30:00');
        // 01:30 in the store's own morning is the PREVIOUS day in UTC: the refund began TODAY for the
        // merchant who recorded it, and this page may not age it by a day he never saw.
        $this->assertNotSame($this->storeToday(), substr($earlyMorning, 0, 10), 'the fixture really crosses the UTC day boundary');

        $this->owedRefundRow('order-early', 1100, $earlyMorning);
        $this->owedRefundRow('order-previous', 1200, $this->instantOn($this->storeDayAgo(1)));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'owed_refund');

        $early = $this->rowContaining($section, '<td class="na-right">11.00 €</td>');
        $this->assertStringContainsString('title="'.$this->storeToday().'"', $early, 'its day is the store-local today');
        $this->assertStringContainsString(__('needs_attention.today'), $early);
        $this->assertStringNotContainsString(trans_choice('needs_attention.age_days', 1), $early, 'a UTC day count would have called it one day old');

        $previous = $this->rowContaining($section, '<td class="na-right">12.00 €</td>');
        $this->assertStringContainsString('title="'.$this->storeDayAgo(1).'"', $previous);
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 1), $previous);

        // The page's one plural, in both numbers, in the language it ships in.
        $this->assertSame('1 day', trans_choice('needs_attention.age_days', 1));
        $this->assertSame('3 days', trans_choice('needs_attention.age_days', 3));
    }

    // =====================================================================================================
    // Bank transfers that do not add up — expected, received, and the one subtraction between them
    // =====================================================================================================

    public function test_a_short_transfer_is_listed_with_the_three_figures_and_the_signed_shortfall(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->bankOrder();            // 5 × 20.00 = 100.00 EUR expected, answered and pending
        $started = $this->storeDayAgo(2);
        $this->appendReceipt($order['payment'], 8000, day: $started);

        $html = $this->visit()->assertOk()->getContent();
        $section = $this->section($html, 'receipt_mismatch');

        $this->assertSame(1, $this->rowsIn($section));
        $this->assertStringContainsString('<span class="na-count">(1)</span>', $section);

        $row = $this->firstRow($section);
        $this->assertStringContainsString(
            __('needs_attention.sources.receipt_mismatch.fact_short', ['difference' => '20.00 €', 'received' => '80.00 €', 'expected' => '100.00 €']),
            $row,
        );
        // The amount column carries the SIGN: the sentence says "short by", the figure says -20.00.
        $this->assertStringContainsString('<td class="na-right">-20.00 €</td>', $row);
        $this->assertStringContainsString('href="'.OrderResource::getUrl('view', ['record' => $order['orderId']]).'"', $row);
        $this->assertStringContainsString('title="'.$started.'"', $row, 'the day the first effective receipt arrived');
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 2), $row);
    }

    public function test_an_overpaid_transfer_is_listed_with_the_surplus(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->bankOrder();
        $started = $this->storeDayAgo(1);
        $this->appendReceipt($order['payment'], 12000, day: $started);

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $row = $this->firstRow($section);
        $this->assertStringContainsString(
            __('needs_attention.sources.receipt_mismatch.fact_over', ['difference' => '20.00 €', 'received' => '120.00 €', 'expected' => '100.00 €']),
            $row,
        );
        $this->assertStringContainsString('<td class="na-right">20.00 €</td>', $row);
        $this->assertStringContainsString('title="'.$started.'"', $row);
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 1), $row);
    }

    public function test_bank_transfers_are_listed_oldest_first_by_the_day_their_first_receipt_arrived(): void
    {
        $this->actingAsStaff('Administrator');
        $older = $this->bankOrder();
        $newer = $this->bankOrder();
        $this->appendReceipt($older['payment'], 6000, day: $this->storeDayAgo(6));
        $this->appendReceipt($newer['payment'], 8000, day: $this->storeDayAgo(2));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertSame(2, $this->rowsIn($section));
        $olderRow = $this->rowContaining($section, '<td class="na-right">-40.00 €</td>');
        $newerRow = $this->rowContaining($section, '<td class="na-right">-20.00 €</td>');
        $this->assertTrue(
            strpos($section, '<td class="na-right">-40.00 €</td>') < strpos($section, '<td class="na-right">-20.00 €</td>'),
            'the transfer whose receipt arrived six days ago is above the one from two days ago',
        );
        $this->assertStringContainsString('title="'.$this->storeDayAgo(6).'"', $olderRow);
        $this->assertStringContainsString('title="'.$this->storeDayAgo(2).'"', $newerRow);
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 6), $olderRow);
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 2), $newerRow);
    }

    public function test_a_transfer_whose_receipts_add_up_exactly_is_not_waiting(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 10000, day: $this->storeDayAgo(2));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString('<span class="na-count">(0)</span>', $section);
        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_receipts_that_add_up_only_together_are_not_a_mismatch(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 6000, 'REF-A', null, $this->storeDayAgo(3));
        $this->appendReceipt($order['payment'], 4000, 'REF-B', null, $this->storeDayAgo(1));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_a_transfer_no_receipt_has_arrived_for_is_not_a_mismatch_on_this_screen(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $this->bankOrder();     // answered and pending: nothing has arrived, so there is nothing to add up

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_a_cash_on_delivery_payment_is_never_a_mismatch_here(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->refundableOrder([['quantity' => 1, 'unit' => 10000]], method: 'cash_on_delivery', settle: false);
        $payment = $this->pendingPayment($order['orderId'], 'cash_on_delivery', 10000);
        $this->appendReceipt($payment, 5000, day: $this->storeDayAgo(2));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_a_receipt_recorded_in_another_currency_does_not_join_the_payment(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->refundableOrder([['quantity' => 1, 'unit' => 20000]], method: 'bank_transfer', settle: false);
        $payment = $this->pendingPayment($order['orderId'], 'bank_transfer', 20000, 'USD');
        // The fixture's own receipt is in EUR (appendReceipt uses eur()), so this group has nothing to
        // join to: currency is part of the join, never a filter applied after the fact.
        $this->appendReceipt($payment, 5000, day: $this->storeDayAgo(2));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_settling_the_transfer_takes_it_off_the_list_at_once(): void
    {
        $this->actingAsStaff('Administrator');
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 8000, day: $this->storeDayAgo(2));

        // It is waiting now: the merchant has not accepted the shortfall yet.
        $this->assertSame(1, $this->rowsIn($this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch')));

        // The moment he accepts it, the payment is settled (confirmed_at set) and the screen stops asking.
        $order['payment']->confirm(new DateTimeImmutable('2026-10-06 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        $this->keepThePageAlive();
        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_a_voided_transfer_is_not_a_mismatch(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 8000, day: $this->storeDayAgo(2));

        $order['payment']->void(new DateTimeImmutable('2026-10-06 10:00:00'));
        app(PaymentRepository::class)->save($order['payment']);

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section));
    }

    public function test_a_superseded_receipt_stops_counting_the_moment_it_is_corrected(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->bankOrder();
        $wrong = $this->appendReceipt($order['payment'], 8000, 'REF-1', null, $this->storeDayAgo(4));
        // The correction: the same payment tells the same story again, and names the row it replaces.
        $this->appendReceipt($order['payment'], 10000, 'REF-2', $wrong, $this->storeDayAgo(2));

        $section = $this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch');

        $this->assertStringContainsString(__('needs_attention.section_empty'), $section);
        $this->assertSame(0, $this->rowsIn($section), 'the corrected figures add up, so nothing is waiting');
    }

    public function test_a_correction_that_is_still_wrong_is_listed_with_the_corrected_figures(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->bankOrder();
        $wrong = $this->appendReceipt($order['payment'], 8000, 'REF-1', null, $this->storeDayAgo(4));
        $this->appendReceipt($order['payment'], 5000, 'REF-2', $wrong, $this->storeDayAgo(2));

        $row = $this->firstRow($this->section($this->visit()->assertOk()->getContent(), 'receipt_mismatch'));

        // 50.00 of the 100.00 expected — the WRONG 80.00 is nowhere in the sentence.
        $this->assertStringContainsString(
            __('needs_attention.sources.receipt_mismatch.fact_short', ['difference' => '50.00 €', 'received' => '50.00 €', 'expected' => '100.00 €']),
            $row,
        );
        $this->assertStringNotContainsString('80.00 €', $row, 'the superseded figure is not counted, and it is not shown either');
        $this->assertStringContainsString('<td class="na-right">-50.00 €</td>', $row);
        $this->assertStringContainsString('title="'.$this->storeDayAgo(2).'"', $row, 'the wait is counted from the receipt that counts');
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 2), $row, 'had the wrong row still counted, this would read four days');
    }

    // =====================================================================================================
    // Orders whose payment step did not finish — PENDING with no attempt date, past the in-flight grace
    // =====================================================================================================

    /** A saved payment the adapter never answered for (PENDING, attempted_at NULL), created $minutesAgo minutes before "now". */
    private function unfinishedPayment(string $orderId, int $minor = 2500, int $minutesAgo = 60): Payment
    {
        $payment = Payment::create($orderId, 'cash_on_delivery', Money::fromMinorUnits($minor, 'EUR'), PaymentStatus::PENDING);
        app(PaymentRepository::class)->save($payment);
        DB::table('payments')->where('id', $payment->id())->update(['created_at' => now()->subMinutes($minutesAgo)->format('Y-m-d H:i:s')]);

        return $payment;
    }

    public function test_a_payment_step_that_never_finished_is_listed_with_its_amount_the_fixed_sentence_and_its_wait(): void
    {
        $this->actingAsStaff('Administrator');
        $this->unfinishedPayment('order-stuck-1', 4321, 3 * 24 * 60);

        $html = $this->visit()->assertOk()->getContent();
        $section = $this->section($html, 'payment_step_unfinished');
        $row = $this->rowContaining($section, 'order-stuck-1');

        $this->assertSame(1, $this->rowsIn($section));
        $this->assertStringContainsString(__('needs_attention.sources.payment_step_unfinished.fact'), $row);
        $this->assertStringContainsString('43.21', $row, 'the payment amount');
        $this->assertStringContainsString(trans_choice('needs_attention.age_days', 3), $row);
        $this->assertStringContainsString(__('needs_attention.sources.payment_step_unfinished.label'), $section);
    }

    public function test_an_answered_pending_payment_is_a_normal_offline_order_and_is_not_listed(): void
    {
        $this->bankOrder(method: 'bank_transfer');
        $this->bankOrder(method: 'cash_on_delivery');
        DB::table('payments')->update(['created_at' => now()->subDays(5)->format('Y-m-d H:i:s')]);

        $this->assertSame(2, DB::table('payments')->whereNotNull('attempted_at')->where('status', 'pending')->count(), 'the fixture really holds two answered pending payments');
        $this->assertSame(0, app(PaymentStepUnfinishedSource::class)->count());
    }

    public function test_a_voided_a_confirmed_a_captured_and_a_failed_payment_are_not_listed(): void
    {
        $source = app(PaymentStepUnfinishedSource::class);
        $voided = $this->unfinishedPayment('order-voided');
        $confirmed = $this->unfinishedPayment('order-confirmed');
        $captured = $this->unfinishedPayment('order-captured');
        $failed = $this->unfinishedPayment('order-failed');
        $this->unfinishedPayment('order-listed');

        $this->assertSame(5, $source->count());

        DB::table('payments')->where('id', $voided->id())->update(['voided_at' => now()]);
        DB::table('payments')->where('id', $confirmed->id())->update(['confirmed_at' => now()]);
        DB::table('payments')->where('id', $captured->id())->update(['status' => 'captured']);
        DB::table('payments')->where('id', $failed->id())->update(['status' => 'failed', 'failure_reason' => 'declined']);

        $this->assertSame(1, $source->count());
        $this->assertSame(['order-listed'], array_map(fn ($item) => $item->orderId, $source->page(1, 25)));
    }

    public function test_a_payment_younger_than_the_grace_is_still_in_flight_and_the_boundary_is_ten_minutes(): void
    {
        $source = app(PaymentStepUnfinishedSource::class);
        Carbon::setTestNow('2026-10-09 12:00:00');

        try {
            foreach ([0, 5, 9] as $minutes) {
                DB::table('payments')->delete();
                $this->unfinishedPayment('order-young', minutesAgo: $minutes);
                $this->assertSame(0, $source->count(), "{$minutes} minutes old is still in flight");
            }

            foreach ([10, 11, 120] as $minutes) {
                DB::table('payments')->delete();
                $this->unfinishedPayment('order-old', minutesAgo: $minutes);
                $this->assertSame(1, $source->count(), "{$minutes} minutes old is listed");
            }

            $this->assertSame(10, PaymentStepUnfinishedSource::GRACE_MINUTES);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unfinished_payments_are_listed_oldest_first_then_by_payment_id(): void
    {
        $source = app(PaymentStepUnfinishedSource::class);
        $this->unfinishedPayment('order-c', minutesAgo: 60);
        $this->unfinishedPayment('order-a', minutesAgo: 3 * 24 * 60);
        $tieFirst = $this->unfinishedPayment('order-b1');
        $tieSecond = $this->unfinishedPayment('order-b2');
        DB::table('payments')->whereIn('id', [$tieFirst->id(), $tieSecond->id()])->update(['created_at' => now()->subDays(2)->format('Y-m-d H:i:s')]);

        $items = $source->page(1, 25);

        $this->assertSame(['order-a', 'order-b1', 'order-b2', 'order-c'], array_map(fn ($item) => $item->orderId, $items));
        $this->assertSame([3, 2, 2, 0], array_map(fn ($item) => $item->ageDays, $items));
        $this->assertSame(2500, $items[0]->amount->minorValue());
        $this->assertSame($source->count(), count($items));
    }

    public function test_the_source_costs_one_count_and_one_page_on_payments_whatever_the_number_of_rows(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $this->unfinishedPayment('order-one');

        $payments = static fn (array $queries): array => array_values(array_filter(
            $queries,
            static fn (array $entry): bool => preg_match('/from `payments`/', $entry['query']) === 1 && ! str_contains($entry['query'], 'payment_receipts'),
        ));

        $this->assertCount(2, $payments($this->queriesOf()), 'a COUNT and one page of unfinished payments');

        for ($i = 0; $i < 25; $i++) {
            $this->unfinishedPayment('order-many-'.$i, minutesAgo: 30 + $i);
        }

        $this->assertCount(2, $payments($this->queriesOf()), 'still a COUNT and one page, not one read per row');
    }

    public function test_the_unfinished_section_speaks_the_store_s_language(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->actingAsStaff('Administrator');
        $this->unfinishedPayment('order-bg');

        $section = $this->section($this->visit()->assertOk()->getContent(), 'payment_step_unfinished');

        $this->assertStringContainsString('Поръчката е направена, но стъпката с плащането не е завършила', $section);
        $this->assertStringContainsString('Поръчки с незавършена стъпка за плащане', $section);
    }

    // =====================================================================================================
    // One page per source — each section's own count, own page, own key in the query string
    // =====================================================================================================

    public function test_each_section_carries_its_own_page_and_paging_one_leaves_the_other_alone(): void
    {
        $this->actingAsStaff('Administrator');
        $this->seedTwentySixOwedRefunds();
        $mismatch = $this->bankOrder();
        $this->appendReceipt($mismatch['payment'], 8000, day: $this->storeDayAgo(2));

        $first = $this->visit()->assertOk()->getContent();
        $owed = $this->section($first, 'owed_refund');

        $this->assertSame(25, $this->rowsIn($owed), 'one page of the section, not all 26 rows');
        $this->assertStringContainsString('<span class="na-count">(26)</span>', $owed, 'the count is the whole section whatever the page');
        $this->assertStringContainsString(__('needs_attention.pagination.status', ['page' => 1, 'last' => 2]), $owed);
        $this->assertStringContainsString('rel="next"', $owed);
        $this->assertStringContainsString('owed_refund=2', $owed, "the next link carries this section's own page name");
        $this->assertStringContainsString('<span class="na-off">'.__('needs_attention.pagination.previous').'</span>', $owed, 'page one has nowhere back to go');
        $this->assertStringNotContainsString('<td class="na-right">26.00 €</td>', $owed, 'the 26th row is on the second page');
        // Another section on the same page is untouched by this one's page.
        $this->assertSame(1, $this->rowsIn($this->section($first, 'receipt_mismatch')));

        $second = $this->visit('?owed_refund=2')->assertOk()->getContent();
        $owedSecond = $this->section($second, 'owed_refund');

        $this->assertSame(1, $this->rowsIn($owedSecond), 'the leftover row, and only it');
        $this->assertStringContainsString('<td class="na-right">26.00 €</td>', $owedSecond, 'the newest refund, which page one did not show');
        $this->assertStringContainsString('<span class="na-count">(26)</span>', $owedSecond);
        $this->assertStringContainsString(__('needs_attention.pagination.status', ['page' => 2, 'last' => 2]), $owedSecond);
        $this->assertStringContainsString('rel="prev"', $owedSecond);
        $this->assertStringContainsString('owed_refund=1', $owedSecond);
        $this->assertStringContainsString('<span class="na-off">'.__('needs_attention.pagination.next').'</span>', $owedSecond, 'the last page has nowhere forward to go');
        $this->assertSame(1, $this->rowsIn($this->section($second, 'receipt_mismatch')), 'the other section is still on its own first page');
    }

    public function test_a_page_past_the_end_of_a_section_is_a_section_with_nothing_on_it(): void
    {
        $this->actingAsStaff('Administrator');
        $this->seedTwentySixOwedRefunds();
        $mismatch = $this->bankOrder();
        $this->appendReceipt($mismatch['payment'], 8000, day: $this->storeDayAgo(2));

        $html = $this->visit('?owed_refund=99')->assertOk()->getContent();
        $owed = $this->section($html, 'owed_refund');

        $this->assertStringContainsString('<span class="na-count">(26)</span>', $owed, 'the count is the truth, not the page');
        $this->assertStringContainsString(__('needs_attention.section_empty'), $owed);
        $this->assertSame(0, $this->rowsIn($owed));
        // ...and saying nothing about one source says nothing about the page: the other section is intact.
        $this->assertStringNotContainsString(__('needs_attention.empty'), $html);
        $this->assertSame(1, $this->rowsIn($this->section($html, 'receipt_mismatch')));
    }

    // =====================================================================================================
    // A page number is a URL parameter, not a promise: nonsense is page one, and huge is the cap
    // =====================================================================================================

    public function test_a_page_number_the_query_string_cannot_mean_is_page_one(): void
    {
        $this->actingAsStaff('Administrator');
        $this->seedTwentySixOwedRefunds();
        $mismatch = $this->bankOrder();
        $this->appendReceipt($mismatch['payment'], 8000, day: $this->storeDayAgo(2));

        // Everything Livewire's OWN resolver refuses (SupportPagination::setPageResolvers() is
        // filter_var(..., FILTER_VALIDATE_INT)): '', 'abc', '1.5', '0', '-5', and a number no PHP int holds.
        // A section asked for one of them is on page ONE — twenty-five rows where there are twenty-six, and
        // the one row where there is one — never on page zero, never on a negative page, never on the last
        // page a thirty-digit number would otherwise ask for, and never a broken offset.
        foreach (['', 'abc', '1.5', '0', '-5', '999999999999999999999999999999'] as $value) {
            foreach (['owed_refund', 'receipt_mismatch'] as $key) {
                $section = $this->section($this->visit('?'.$key.'='.rawurlencode($value))->assertOk()->getContent(), $key);

                if ($key === 'owed_refund') {
                    $this->assertStringContainsString(__('needs_attention.pagination.status', ['page' => 1, 'last' => 2]), $section, "?{$key}={$value}");
                    $this->assertSame(25, $this->rowsIn($section), "?{$key}={$value} is page one of two");
                } else {
                    // One row, one page, so no status line to read (the pager only draws when hasPages()) —
                    // the row itself is the proof it is page one and not a page past this section's end.
                    $this->assertSame(1, $this->rowsIn($section), "?{$key}={$value} is page one, not a page past the end");
                    $this->assertStringNotContainsString(__('needs_attention.section_empty'), $section, "?{$key}={$value}");
                }
            }
        }

        // The array form is not a page number either: `?owed_refund[]=2` is page one.
        $section = $this->section($this->visit('?owed_refund[]=2')->assertOk()->getContent(), 'owed_refund');

        $this->assertSame(25, $this->rowsIn($section), 'a query-string array is page one');
        $this->assertStringContainsString(__('needs_attention.pagination.status', ['page' => 1, 'last' => 2]), $section);
    }

    public function test_a_page_number_above_the_cap_is_asked_for_at_the_cap_and_not_as_typed(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $spy = $this->spySource();

        // Inside the cap, the number in the URL is the number the source is asked for — all three sources.
        foreach (['1' => 1, '2' => 2, '99' => 99] as $query => $expected) {
            $this->visit('?spy='.$query)->assertOk();

            $this->assertSame($expected, $spy->lastAsked(), "?spy={$query}");
        }

        // 2,100,000,000 is a VALID integer — Livewire would take it exactly as typed. A second source is
        // asked page 2,100,000,001 on its own, but this page caps it BEFORE it becomes an offset, so no URL
        // can turn one read into a fifty-billion-row skip.
        $this->visit('?spy=2100000000&owed_refund=2100000001')->assertOk();

        $this->assertSame(1_000_000, $spy->lastAsked(), 'the cap, applied before the offset');
        $this->assertSame(1_000_000, NeedsAttention::MAX_PAGE, "and the cap is the page's own constant");
    }

    /**
     * A third source that records the page number it is asked for. Tagged exactly as R4b's own two sources
     * are — the page's documented extension point — so what is under test is the page's own guard, through a
     * real GET, and not a helper called directly.
     *
     * Under the cap a section past its own end is an empty section, as designed; over it the page refuses to
     * pass the number on, which is the only behaviour a store can see for a number that absurd.
     */
    private function spySource(): object
    {
        $spy = new class implements NeedsAttentionSource
        {
            /** @var list<int> every page this source was asked for, in order */
            public array $asked = [];

            public function key(): string
            {
                return 'spy';
            }

            public function label(): string
            {
                return 'Spy';
            }

            public function count(): int
            {
                return 0;
            }

            public function page(int $page, int $perPage): array
            {
                $this->asked[] = $page;

                return [];
            }

            public function lastAsked(): int
            {
                return $this->asked[count($this->asked) - 1];
            }
        };

        $this->app->instance('needs_attention.spy', $spy);
        $this->app->tag(['needs_attention.spy'], NeedsAttentionSource::TAG);

        return $spy;
    }

    private function seedTwentySixOwedRefunds(): void
    {
        // Twenty-six refunds on one order — one more than a page. The times run forward, so the first
        // page holds the oldest twenty-five and the last row of the section is the newest one.
        for ($i = 0; $i < 26; $i++) {
            $this->owedRefundRow(
                'order-26-'.$i,
                100 * ($i + 1),
                $this->instantOn($this->storeDayAgo(10), '12:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)),
            );
        }
    }

    // =====================================================================================================
    // The two costs the page promises: one count plus one page per source, and no write at all
    // =====================================================================================================

    public function test_each_source_costs_one_count_and_one_page_whatever_the_number_of_rows(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $mismatch = $this->bankOrder();
        $this->appendReceipt($mismatch['payment'], 8000, day: $this->storeDayAgo(2));

        $small = $this->queriesOf();
        $this->assertCount(2, $this->statementsOn($small, 'payment_refunds'), 'a COUNT and one page of owed refunds');
        $this->assertCount(2, $this->statementsOn($small, 'payment_receipts'), 'a COUNT and one page of receipts');

        // Twenty-five more rows in one source change nothing about its cost — and nothing about the other's.
        for ($i = 0; $i < 25; $i++) {
            $this->owedRefundRow('order-many-'.$i, 500, $this->instantOn($this->storeDayAgo(3 + $i % 5)));
        }

        $many = $this->queriesOf();
        $this->assertCount(2, $this->statementsOn($many, 'payment_refunds'), 'still a COUNT and one page, not one read per row');
        $this->assertCount(2, $this->statementsOn($many, 'payment_receipts'));
    }

    public function test_reading_the_page_writes_nothing(): void
    {
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 8000, day: $this->storeDayAgo(2));

        $before = $this->moneyRowCounts();
        $queries = $this->queriesOf();
        $after = $this->moneyRowCounts();

        $this->assertSame($before, $after, 'a read of this page changes no row of the money it shows');
        $this->assertSame(
            [],
            $this->writesOn($queries, ['payment_refunds', 'payment_receipts', 'payments', 'orders']),
            'the page derives every row and keeps no row of its own: not one INSERT, UPDATE or DELETE',
        );
    }

    /** Every statement one real render of the page issued, in order (an optional section page). */
    private function queriesOf(string $query = ''): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->visit($query)->assertOk();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        return $queries;
    }

    /** @param  list<array{query: string}>  $queries @return list<array{query: string}> */
    private function statementsOn(array $queries, string $table): array
    {
        return array_values(array_filter($queries, static fn (array $entry): bool => str_contains($entry['query'], $table)));
    }

    /**
     * The write statements among $queries that name one of $tables. Narrowed to this page's own tables on
     * purpose: what is under test is that THIS screen writes nothing, not that no framework code does.
     *
     * @param  list<array{query: string}>  $queries
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function writesOn(array $queries, array $tables): array
    {
        $writes = [];

        foreach ($queries as $entry) {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate)\b/i', $entry['query']) !== 1) {
                continue;
            }

            foreach ($tables as $table) {
                if (str_contains($entry['query'], $table)) {
                    $writes[] = $entry['query'];
                    break;
                }
            }
        }

        return $writes;
    }

    /** @return array<string, int> */
    private function moneyRowCounts(): array
    {
        $counts = [];

        foreach (['payment_refunds', 'payment_receipts', 'payments', 'orders', 'order_events'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // =====================================================================================================
    // Its words — the store's own language, end to end, and the same sentences in both languages
    // =====================================================================================================

    public function test_the_page_speaks_the_store_s_own_language(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();          // one owed refund, one day old
        $order = $this->bankOrder();
        $this->appendReceipt($order['payment'], 8000, day: $this->storeDayAgo(2));

        $html = $this->visit()->assertOk()->getContent();

        // The strings are read from the FILE, not through __(): what is under test is the whole pipeline
        // (site.locale -> ApplyStoreLocale -> App::setLocale -> this page's own keys).
        $bg = require lang_path('bg/needs_attention.php');

        $this->assertStringContainsString($bg['title'], $html);
        $this->assertStringContainsString($bg['intro'], $html);
        $this->assertStringContainsString($bg['sources']['owed_refund']['label'], $html);
        $this->assertStringContainsString($bg['sources']['receipt_mismatch']['label'], $html);
        $this->assertStringContainsString($bg['columns']['waiting'], $html);
        $this->assertStringContainsString(str_replace(':channel', $bg['channels']['cash'], $bg['sources']['owed_refund']['fact']), $html);

        // The two waits, in Bulgarian, in both plural forms the language has.
        [$bgSingular, $bgPlural] = explode('|', $bg['age_days']);
        $this->assertStringContainsString(str_replace(':count', '1', $bgSingular), $html);
        $this->assertStringContainsString(str_replace(':count', '2', $bgPlural), $html);

        $en = require lang_path('en/needs_attention.php');
        $this->assertStringNotContainsString($en['title'], $html, 'and not the English title');
        $this->assertStringNotContainsString($en['sources']['owed_refund']['label'], $html);
        $this->assertStringNotContainsString($en['section_empty'], $html);
    }

    public function test_the_page_names_a_queue_of_money_in_the_plural_and_never_a_warning(): void
    {
        // The words themselves, in both languages: one page, one name, and the store's own.
        foreach (['bg' => 'Изискват внимание', 'en' => 'Needs attention'] as $locale => $expected) {
            $strings = require lang_path($locale.'/needs_attention.php');

            $this->assertSame($expected, $strings['title'], "{$locale}: the page's own title");
            $this->assertSame($expected, $strings['navigation_label'], "{$locale}: the sidebar item carries the same name");

            // NOT ONE STRING on this page names a rule, a lateness or an urgency: the page lists facts and
            // judges nothing (§7.2.7), so the word for a judgement must not appear anywhere in it at all.
            $forbidden = $locale === 'bg'
                ? ['просрочено', 'Просрочено', 'спешно', 'Спешно', 'закъснял']
                : ['overdue', 'Overdue', 'late', 'Late', 'urgent', 'Urgent'];

            foreach ($this->leaves($strings) as $key => $sentence) {
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsString($word, $sentence, "{$locale}: {$key} may not judge");
                }
            }

            // ...and the intro says what is waiting, then the one thing the page may say about a wait.
            $this->assertStringContainsString($locale === 'bg' ? 'възстановявания' : 'refunds', $strings['intro']);
            $this->assertStringContainsString($locale === 'bg' ? 'банкови преводи' : 'bank transfers', $strings['intro']);
        }
    }

    public function test_the_page_s_own_icon_is_a_neutral_one_and_not_a_warning(): void
    {
        // An inbox is a queue of things waiting; a triangle is a verdict on them, and this page passes none.
        $this->assertSame('heroicon-o-inbox', NeedsAttention::getNavigationIcon());
        $this->assertNotContains(NeedsAttention::getNavigationIcon(), ['heroicon-o-exclamation-triangle', 'heroicon-o-exclamation-circle']);
    }

    public function test_the_drawn_page_shows_the_new_name_and_none_of_the_words_it_forbids_itself(): void
    {
        app(SiteSettingsRepository::class)->set('site.locale', 'bg');
        $this->actingAsStaff('Administrator');
        $this->keepThePageAlive();

        $html = $this->visit()->assertOk()->getContent();

        $this->assertStringContainsString('Изискват внимание', $html, 'the new name, on the page and in the sidebar');
        $this->assertStringNotContainsString('Изисква внимание', $html, 'and not the old singular');
        $this->assertStringNotContainsString('просрочено', $html);
        $this->assertStringNotContainsString('спешно', $html);
    }

    public function test_both_languages_carry_the_same_sentences_with_the_same_placeholders(): void
    {
        $en = require lang_path('en/needs_attention.php');
        $bg = require lang_path('bg/needs_attention.php');

        $english = $this->leaves($en);
        $bulgarian = $this->leaves($bg);

        $this->assertSame(array_keys($english), array_keys($bulgarian), 'lang/bg/needs_attention.php must translate every key, and invent none');

        foreach ($english as $path => $sentence) {
            $this->assertSame(
                $this->placeholders($sentence),
                $this->placeholders($bulgarian[$path]),
                "the {$path} sentence must name the same things in both languages, or a figure is silently dropped",
            );
        }

        // The page's one plural, two forms in both languages: "1 day"/"3 days", "1 ден"/"3 дни".
        $this->assertCount(2, explode('|', $en['age_days']));
        $this->assertCount(2, explode('|', $bg['age_days']));
    }

    /** @return array<string, string> every leaf of a lang array, by dotted path ('sources.owed_refund.fact'). */
    private function leaves(array $strings, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($strings as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $leaves += $this->leaves($value, $path);

                continue;
            }

            $leaves[$path] = (string) $value;
        }

        ksort($leaves);

        return $leaves;
    }

    /** The :placeholders one sentence names, sorted and without duplicates. @return list<string> */
    private function placeholders(string $sentence): array
    {
        preg_match_all('/:[a-z_]+/', $sentence, $matches);

        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }
}
