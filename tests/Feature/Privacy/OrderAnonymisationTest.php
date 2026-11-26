<?php

namespace Tests\Feature\Privacy;

use App\Console\Commands\AnonymiseOrders;
use App\Enums\OrderBlockType;
use App\Enums\OrderEventType;
use App\Filament\Support\OrderBlockActions;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Filament\StaffPanelUser;
use App\Privacy\OrderRetention;
use App\Privacy\PrivacySettings;
use App\Services\OrderDeliveryChange;
use App\Services\OrderEditor;
use App\Services\OrderMatchReader;
use App\Services\OrderPromotionCodeChange;
use App\Settings\Contracts\SiteSettingsRepository;
use Carbon\Carbon;
use DateTimeImmutable;
use EasyCo\Extensibility\Hook;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\Exceptions\OrderNotEditableException;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * ACC-4b (acc-4-gdpr-design.md D1, D8): the anonymisation of finished orders past the retention period: the cutoff in the STORE's timezone, every personal
 * field of every copy cleared and everything else byte for byte the same, the identity keys, the mail log, one history row, the skipped ones, the command's
 * options, the editor's refusal, the admin view, the hook, the schedule and the cost. Real orders through POST /api/checkout.
 */
class OrderAnonymisationTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    /** the personal columns each table loses (everything else in a row must stay as it was); `updated_at` is bookkeeping and moves with any write */
    private const PERSONAL = [
        'orders' => ['email', 'recipient_first_name', 'recipient_last_name', 'phone', 'postal_code', 'address_line_1', 'address_line_2', 'region', 'settlement', 'pickup_point_reference', 'pickup_point_name', 'pickup_point_address', 'customer_note', 'recipient_email', 'phone_key', 'email_key', 'name_key', 'place_key', 'anonymised_at', 'updated_at'],
        'order_placement_snapshots' => ['recipient_first_name', 'recipient_last_name', 'phone', 'postal_code', 'address_line_1', 'address_line_2', 'region', 'settlement', 'pickup_point_reference', 'pickup_point_name', 'pickup_point_address', 'customer_note'],
        'order_invoice_details' => ['legal_name', 'tax_id', 'company_registration_number', 'representative', 'city', 'postal_code', 'address_line_1', 'address_line_2', 'invoice_email', 'updated_at'],
        'order_attributions' => ['ip', 'user_agent', 'visitor_id', 'account_id', 'referrer_url', 'landing_page_url', 'first_landing_page_url', 'click_id_value', 'updated_at'],
        'order_events' => ['reason'],
        'payments' => ['failure_reason'],
        'payment_refunds' => ['reason', 'failure_reason', 'paid_out_note', 'paid_out_reference', 'cancelled_reason', 'deduction_reason'],
        'payment_receipts' => ['bank_reference'],
    ];

    /** tables that must be byte for byte the same */
    /** the pinned cost of the command (measured, then pinned): the fixed part of a run and each order's own queries */
    // COUR-2a: 13 (was 12): the snapshot of a courier point (`order_pickup_points`) is blanked with the order's other copies, one UPDATE more per order
    private const PINNED_PER_ORDER = 13;

    private const PINNED_FIXED = 6;

    private const UNTOUCHED = ['operational_sales_clients', 'operational_sales_transactions', 'operational_sales_sale_lines', 'cart_lines'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegionOrders();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        app(SiteSettingsRepository::class)->set('site.timezone', 'Europe/Sofia');
    }

    // ---- fixtures ---------------------------------------------------------------------------------------------------------------

    /** a real order (street, or a pick-up point), finished, placed at the given STORE-LOCAL time, with a row in every table that holds a copy of its personal data */
    private function oldOrder(string $localPlacedAt, string $email = 'old@example.com', string $status = 'delivered', bool $pickup = false): string
    {
        $this->checkout($pickup ? $this->pickupFields() : $this->streetFields('BG'), ['email' => $email, 'recipient_email' => 'mum@example.com', 'customer_note' => 'Leave it with Mrs Petrova'])->assertStatus(201);
        $id = (string) DB::table('orders')->max('id');
        $utc = Carbon::parse($localPlacedAt, 'Europe/Sofia')->utc()->toDateTimeString();

        DB::table('orders')->where('id', $id)->update(['status' => $status, 'placed_at' => $utc]);
        DB::table('payments')->where('order_id', $id)->update(['status' => 'failed', 'attempted_at' => now(), 'failure_reason' => 'card of Ivan Ivanov declined']);
        DB::table('order_attributions')->where('order_id', $id)->update([
            'visitor_id' => '11111111-2222-3333-4444-555555555555', 'account_id' => 99, 'referrer_url' => 'https://example.org/?q=ivan', 'referrer_host' => 'example.org',
            'landing_page_url' => 'https://shop.test/?gclid=abc', 'first_landing_page_url' => 'https://shop.test/first', 'click_id_type' => 'gclid', 'click_id_value' => 'abc123',
            'user_agent' => 'Mozilla/5.0 (X11)', 'ip' => '203.0.113.7', 'last_utm_source' => 'newsletter', 'device_type' => 'desktop',
        ]);
        DB::table('order_invoice_details')->insert([
            'order_id' => $id, 'requested_at' => now(), 'recipient_type' => 'company', 'legal_name' => 'Ivanov Ltd', 'tax_id' => 'BG123456789', 'company_registration_number' => '123456789',
            'representative' => 'Ivan Ivanov', 'country' => 'BG', 'city' => 'Plovdiv', 'postal_code' => '4000', 'address_line_1' => 'Main 5', 'address_line_2' => 'Floor 3',
            'invoice_email' => 'accounts@ivanov.example', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_events')->insert(['order_id' => $id, 'type' => 'note_added', 'reason' => 'Call mum on 0888 123 456', 'staff_name' => 'Admin', 'occurred_at' => now()]);

        $paymentId = (string) DB::table('payments')->where('order_id', $id)->value('id');
        DB::table('payment_refunds')->insert([
            'payment_id' => $paymentId, 'order_id' => $id, 'amount_minor' => 500, 'amount_currency' => 'EUR', 'channel' => 'cash', 'goods_minor' => 500, 'shipping_minor' => 0, 'adjustment_minor' => 0,
            'deduction_minor' => 0, 'reason' => 'Ivan Ivanov returned the dress', 'status' => 'failed', 'failure_reason' => 'Ivan was not home', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payment_receipts')->insert(['payment_id' => $paymentId, 'amount_minor' => 1700, 'amount_currency' => 'EUR', 'received_on' => '2026-04-01', 'bank_reference' => 'Ivan Ivanov order 5', 'recorded_at' => now()]);

        return $id;
    }

    /** the staff free text of a refund: notes on the failed one, and a cancelled refund WITH a deduction (both CHECKs then need a text) */
    private function addRefundFreeText(string $id): void
    {
        DB::table('payment_refunds')->where('order_id', $id)->update(['paid_out_note' => 'paid to Ivan in person', 'paid_out_reference' => 'receipt Ivan 7', 'deduction_reason' => 'Ivan wore it']);
        DB::table('payment_refunds')->insert([
            'payment_id' => (string) DB::table('payments')->where('order_id', $id)->value('id'), 'order_id' => $id, 'amount_minor' => 400, 'amount_currency' => 'EUR', 'channel' => 'cash',
            'goods_minor' => 500, 'shipping_minor' => 0, 'adjustment_minor' => 0, 'deduction_minor' => 100, 'deduction_reason' => 'Ivan wore it', 'status' => 'cancelled',
            'cancelled_at' => now(), 'cancelled_reason' => 'Ivan changed his mind', 'paid_out_note' => 'Ivan called', 'paid_out_reference' => 'ref Ivan', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<string, array<string, mixed>>> table => id => row */
    private function snapshot(): array
    {
        $out = [];

        foreach (array_merge(array_keys(self::PERSONAL), self::UNTOUCHED, ['mail_log']) as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->mapWithKeys(fn (object $row): array => [(string) $row->id => (array) $row])->all();
        }

        return $out;
    }

    /** every row of every table is the same, but for the PERSONAL columns of the rows of $orderIds' tables */
    private function assertOnlyPersonalChanged(array $before, array $after, array $deletedMailLogIds = [], array $addedRows = []): void
    {
        foreach ($before as $table => $rows) {
            foreach ($rows as $id => $row) {
                if ($table === 'mail_log' && in_array((string) $id, $deletedMailLogIds, true)) {
                    $this->assertArrayNotHasKey($id, $after[$table], 'mail_log row of the order must be gone');

                    continue;
                }

                $this->assertArrayHasKey($id, $after[$table], "{$table} #{$id} disappeared");
                $changed = array_keys(array_filter($row, fn ($value, $column) => $value != $after[$table][$id][$column], ARRAY_FILTER_USE_BOTH));
                $allowed = self::PERSONAL[$table] ?? [];

                $this->assertSame([], array_values(array_diff($changed, $allowed)), "{$table} #{$id}: non-personal columns changed");
            }

            $this->assertSame(count($rows) - count(array_intersect(array_keys($rows), $table === 'mail_log' ? $deletedMailLogIds : [])) + ($addedRows[$table] ?? 0), count($after[$table]), "{$table}: the number of rows");
        }
    }

    private function anonymise(string $command = 'privacy:anonymise-orders', array $options = []): string
    {
        Artisan::call($command, $options);

        return trim(Artisan::output());
    }

    private function travelToLocal(string $local): void
    {
        $this->travelTo(Carbon::parse($local, 'Europe/Sofia'));
    }

    private function staff(): void
    {
        $roles = app(RoleRepository::class);
        app(StaffSystemRolesSeeder::class)->run($roles);
        $staff = Staff::create('admin-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Admin Tester', $roles->findSystemRoleByName('Administrator'));
        app(StaffRepository::class)->save($staff);
        $this->actingAs(StaffPanelUser::find($staff->id()), 'staff');
        session()->forget('password_hash_staff');
    }

    // ---- 1: the cutoff in the store's timezone ----------------------------------------------------------------------------------

    public function test_the_cutoff_is_the_start_of_this_year_in_the_store_timezone_minus_the_retention_years(): void
    {
        $retention = app(OrderRetention::class);
        $at = fn (string $local): DateTimeImmutable => Carbon::parse($local, 'Europe/Sofia')->toDateTimeImmutable();
        $sofia = fn (DateTimeImmutable $cutoff): string => $cutoff->setTimezone(new \DateTimeZone('Europe/Sofia'))->format('Y-m-d H:i');

        $this->assertSame('2027-01-01 00:00', $sofia($retention->cutoff($at('2037-03-05 12:00'))), 'the owner example: N = 10, today 2037-03-05');
        $this->assertSame('2026-01-01 00:00', $sofia($retention->cutoff($at('2036-12-31 23:59'))), 'still last year');
        $this->assertSame('2027-01-01 00:00', $sofia($retention->cutoff($at('2037-01-01 00:00'))));

        app(SiteSettingsRepository::class)->set(PrivacySettings::ORDER_RETENTION_YEARS_KEY, '1');
        $this->assertSame('2036-01-01 00:00', $sofia($retention->cutoff($at('2037-03-05 12:00'))), 'N = 1');
        app(SiteSettingsRepository::class)->set(PrivacySettings::ORDER_RETENTION_YEARS_KEY, '30');
        $this->assertSame('2007-01-01 00:00', $sofia($retention->cutoff($at('2037-03-05 12:00'))), 'N = 30');
    }

    public function test_an_order_placed_in_the_last_second_of_a_year_is_anonymised_on_the_first_of_january_ten_years_on_and_the_next_one_is_not(): void
    {
        $a = $this->oldOrder('2026-12-31 23:59:00', 'a@example.com');
        $b = $this->oldOrder('2027-01-01 00:00:00', 'b@example.com');
        $c = $this->oldOrder('2027-06-15 12:00:00', 'c@example.com');

        $this->travelToLocal('2036-12-31 23:59:30');
        $this->assertStringContainsString('Anonymised 0 order(s)', $this->anonymise());
        $this->assertNull(DB::table('orders')->where('id', $a)->value('anonymised_at'), 'one minute before the new year: nothing yet');

        $this->travelToLocal('2037-01-01 00:00:30');
        $this->assertStringContainsString('Anonymised 1 order(s)', $this->anonymise());
        $this->assertNotNull(DB::table('orders')->where('id', $a)->value('anonymised_at'));
        $this->assertNull(DB::table('orders')->where('id', $b)->value('anonymised_at'), 'placed at 2027-01-01 00:00 store time: still inside the period');
        $this->assertNull(DB::table('orders')->where('id', $c)->value('anonymised_at'));

        $this->travelToLocal('2038-01-01 00:00:30');
        $this->anonymise();
        $this->assertNotNull(DB::table('orders')->where('id', $b)->value('anonymised_at'));
        $this->assertNotNull(DB::table('orders')->where('id', $c)->value('anonymised_at'), 'the 2027 orders go together');
    }

    public function test_one_year_and_thirty_years_move_the_cutoff(): void
    {
        $id = $this->oldOrder('2026-06-01 12:00:00');
        $this->travelToLocal('2028-01-01 00:00:30');

        app(SiteSettingsRepository::class)->set(PrivacySettings::ORDER_RETENTION_YEARS_KEY, '30');
        $this->anonymise();
        $this->assertNull(DB::table('orders')->where('id', $id)->value('anonymised_at'), 'N = 30');

        app(SiteSettingsRepository::class)->set(PrivacySettings::ORDER_RETENTION_YEARS_KEY, '1');
        $this->anonymise();
        $this->assertNotNull(DB::table('orders')->where('id', $id)->value('anonymised_at'), 'N = 1: placed in 2026, anonymised from 2028-01-01');
    }

    // ---- 2, 3, 4, 5: what is cleared, what is not, the keys, the mail log, the history ------------------------------------------

    public function test_the_courier_point_snapshot_of_an_order_is_blanked_with_its_other_copies(): void
    {
        $id = $this->oldOrder('2026-03-06 10:00:00', 'p@example.com', 'cancelled', pickup: true);
        DB::table('order_pickup_points')->insert([
            'order_id' => $id, 'point_id' => 5, 'carrier_code' => 'boxnow', 'external_ref' => 'R1', 'kind' => 'locker', 'name' => 'Lidl Пловдив',
            'address_line' => 'ул. Х 1', 'settlement_name' => 'Пловдив', 'postcode' => '4000', 'created_at' => now(),
        ]);
        $this->travelToLocal('2037-03-05 03:10:00');

        $this->assertStringContainsString('Anonymised 1 order(s)', $this->anonymise());

        $row = (array) DB::table('order_pickup_points')->where('order_id', $id)->first();

        foreach (['point_id', 'external_ref', 'name', 'address_line', 'settlement_name', 'postcode'] as $column) {
            $this->assertNull($row[$column], $column);
        }

        $this->assertSame(['boxnow', 'locker'], [$row['carrier_code'], $row['kind']], 'the courier and the kind are not personal');
    }

    public function test_every_personal_field_of_every_copy_is_cleared_and_everything_else_is_byte_for_byte_the_same(): void
    {
        $street = $this->oldOrder('2026-03-05 10:00:00', 'street@example.com');
        $pickup = $this->oldOrder('2026-03-06 10:00:00', 'pickup@example.com', 'cancelled', pickup: true);
        $recent = $this->oldOrder('2036-03-05 10:00:00', 'recent@example.com');
        $this->addRefundFreeText($street);
        $this->travelToLocal('2037-03-05 03:10:00');
        $before = $this->snapshot();
        $deletedMail = DB::table('mail_log')->where('related_type', 'order')->whereIn('related_id', [$street, $pickup])->pluck('id')->map(fn ($v) => (string) $v)->all();
        $this->assertNotSame([], $deletedMail, 'the checkout wrote their mail_log rows');

        $this->assertStringContainsString('Anonymised 2 order(s)', $this->anonymise());

        $this->assertOnlyPersonalChanged($before, $this->snapshot(), $deletedMail, ['order_events' => 2]); // the one «anonymised» history row of each of the two orders

        $o = DB::table('orders')->where('id', $street)->first();
        $this->assertSame('anonymised@invalid', $o->email);
        $this->assertSame(['Анонимизиран', 'Анонимизиран', 'Анонимизиран'], [$o->recipient_first_name, $o->recipient_last_name, $o->phone]);
        foreach (['postal_code', 'address_line_1', 'address_line_2', 'region', 'settlement', 'pickup_point_reference', 'pickup_point_name', 'pickup_point_address', 'customer_note', 'recipient_email'] as $column) {
            $this->assertNull($o->{$column}, "orders.{$column}");
        }
        $this->assertSame('BG', $o->country);
        $this->assertSame('Sofia', $o->city, 'city stays (statistics)');

        $p = DB::table('orders')->where('id', $pickup)->first();
        $this->assertSame('Анонимизиран', $p->pickup_point_address, 'a pick-up point keeps one description: the database requires it');
        $this->assertNull($p->pickup_point_reference);
        $this->assertSame('econt', $p->carrier_code);

        $snapshot = DB::table('order_placement_snapshots')->where('order_id', $street)->first();
        $this->assertSame(['Анонимизиран', null, null], [$snapshot->phone, $snapshot->address_line_1, $snapshot->customer_note]);
        $this->assertSame('Анонимизиран', DB::table('order_placement_snapshots')->where('order_id', $pickup)->value('pickup_point_address'));

        $invoice = DB::table('order_invoice_details')->where('order_id', $street)->first();
        $this->assertSame(['Анонимизиран', null, null, null, 'Анонимизиран', 'anonymised@invalid', 'BG'], [$invoice->legal_name, $invoice->tax_id, $invoice->company_registration_number, $invoice->representative, $invoice->address_line_1, $invoice->invoice_email, $invoice->country]);

        $attribution = DB::table('order_attributions')->where('order_id', $street)->first();
        foreach (['ip', 'user_agent', 'visitor_id', 'account_id', 'referrer_url', 'landing_page_url', 'first_landing_page_url', 'click_id_value'] as $column) {
            $this->assertNull($attribution->{$column}, "order_attributions.{$column}");
        }
        $this->assertSame(['newsletter', 'desktop', 'example.org', 'gclid'], [$attribution->last_utm_source, $attribution->device_type, $attribution->referrer_host, $attribution->click_id_type], 'UTM, device and counts stay');

        $this->assertSame(0, DB::table('order_events')->where('order_id', $street)->whereNotNull('reason')->count());
        $this->assertSame('Admin', DB::table('order_events')->where('order_id', $street)->where('type', 'note_added')->value('staff_name'), 'staff names stay');
        $this->assertNull(DB::table('payments')->where('order_id', $street)->value('failure_reason'));
        $this->assertSame([null, null], [DB::table('payment_refunds')->where('order_id', $street)->value('reason'), DB::table('payment_refunds')->where('order_id', $street)->value('failure_reason')]);
        $this->assertSame('Анонимизиран', DB::table('payment_receipts')->value('bank_reference'));

        // the staff free text of the refunds: NULL, the placeholder only where a CHECK needs a text (cancelled, deduction)
        $failed = DB::table('payment_refunds')->where('order_id', $street)->where('status', 'failed')->first();
        $this->assertSame([null, null, null], [$failed->paid_out_note, $failed->paid_out_reference, $failed->deduction_reason], 'a refund without a deduction or a cancellation');
        $cancelled = DB::table('payment_refunds')->where('order_id', $street)->where('status', 'cancelled')->first();
        $this->assertSame([null, null, 'Анонимизиран', 'Анонимизиран'], [$cancelled->paid_out_note, $cancelled->paid_out_reference, $cancelled->cancelled_reason, $cancelled->deduction_reason], 'the CHECKs keep a text');

        // the recent order is untouched: its own copies still say who it is
        $r = DB::table('orders')->where('id', $recent)->first();
        $this->assertSame('recent@example.com', $r->email);
        $this->assertNull($r->anonymised_at);
        $this->assertSame('203.0.113.7', DB::table('order_attributions')->where('order_id', $recent)->value('ip'));
    }

    public function test_the_identity_keys_are_null_the_matcher_finds_nothing_for_it_and_still_matches_a_recent_order(): void
    {
        $old = $this->oldOrder('2026-03-05 10:00:00', 'same@example.com');
        $recent = $this->oldOrder('2036-03-05 10:00:00', 'same@example.com');

        $matches = fn (string $id): array => app()->make(OrderMatchReader::class)->forOrder(OrderModel::query()->findOrFail($id));
        $this->assertContains($recent, array_column($matches($old)['orders'], 'id'), 'before: the two orders share the email');

        $this->travelToLocal('2037-03-05 03:10:00');
        $this->anonymise();

        $keys = DB::table('orders')->where('id', $old)->first(['phone_key', 'email_key', 'name_key', 'place_key']);
        $this->assertSame([null, null, null, null], array_values((array) $keys));
        $this->assertNotNull(DB::table('orders')->where('id', $recent)->value('email_key'), 'a recent order keeps its keys');

        $this->app->forgetInstance(OrderMatchReader::class);
        $this->assertSame([], $matches($old)['orders'], 'the anonymised order matches nothing');
        $this->assertNotContains($old, array_column($matches($recent)['orders'], 'id'), 'and nothing matches it');
    }

    public function test_a_later_save_of_an_anonymised_order_does_not_bring_the_keys_back(): void
    {
        $id = $this->oldOrder('2026-03-05 10:00:00', 'again@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $this->anonymise();

        // a status move after the anonymisation (delivered -> refunded) saves the whole order again; the repository must not rebuild the keys from the placeholders
        $repository = app(\EasyCo\Order\Contracts\OrderRepository::class);
        $order = $repository->findById($id);
        $this->assertTrue($order->isAnonymised());
        $order->refund();
        $repository->save($order);

        $row = DB::table('orders')->where('id', $id)->first(['status', 'phone_key', 'email_key', 'name_key', 'place_key', 'anonymised_at', 'email']);
        $this->assertSame('refunded', $row->status);
        $this->assertSame([null, null, null, null], [$row->phone_key, $row->email_key, $row->name_key, $row->place_key]);
        $this->assertNotNull($row->anonymised_at);
        $this->assertSame('anonymised@invalid', $row->email);
    }

    public function test_the_database_refuses_an_anonymised_order_that_is_not_finished(): void
    {
        $id = $this->oldOrder('2026-03-05 10:00:00', 'x@example.com', 'placed');

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('orders')->where('id', $id)->update(['anonymised_at' => now()]);
    }

    public function test_the_mail_log_rows_of_the_order_go_and_nobody_elses_do(): void
    {
        $old = $this->oldOrder('2026-03-05 10:00:00', 'old@example.com');
        $recent = $this->oldOrder('2036-03-05 10:00:00', 'recent@example.com');
        DB::table('mail_log')->insert([
            'template_key' => 'account.verify', 'idempotency_key' => 'k-unrelated', 'category' => 'transactional', 'to_email' => 'old@example.com', 'locale' => 'en',
            'status' => 'queued', 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(1, DB::table('mail_log')->where('related_type', 'order')->where('related_id', $old)->count());

        $this->travelToLocal('2037-03-05 03:10:00');
        $this->anonymise();

        $this->assertSame(0, DB::table('mail_log')->where('related_type', 'order')->where('related_id', $old)->count());
        $this->assertSame(1, DB::table('mail_log')->where('related_type', 'order')->where('related_id', $recent)->count());
        $this->assertSame(1, DB::table('mail_log')->where('idempotency_key', 'k-unrelated')->count(), 'a mail that is not about the order is the mail log pruning job, not this');
    }

    public function test_anonymised_at_is_set_one_history_row_is_written_and_a_second_run_changes_nothing(): void
    {
        $id = $this->oldOrder('2026-03-05 10:00:00');
        $this->travelToLocal('2037-03-05 03:10:00');
        $fired = [];
        Hook::action('order.anonymised', function (string $orderId) use (&$fired): void {
            $fired[] = $orderId;
        });

        $this->anonymise();
        $first = $this->snapshot();

        $at = DB::table('orders')->where('id', $id)->value('anonymised_at');
        $this->assertNotNull($at);
        $this->assertSame(1, DB::table('order_events')->where('order_id', $id)->where('type', OrderEventType::ANONYMISED->value)->count());
        $this->assertNull(DB::table('order_events')->where('order_id', $id)->where('type', 'anonymised')->value('reason'));
        $this->assertSame([$id], $fired);

        $this->travelToLocal('2037-03-06 03:10:00');
        $this->assertStringContainsString('Anonymised 0 order(s)', $this->anonymise());

        $this->assertSame($first, $this->snapshot(), 'a second run changes nothing');
        $this->assertSame($at, DB::table('orders')->where('id', $id)->value('anonymised_at'));
        $this->assertSame(1, DB::table('order_events')->where('order_id', $id)->where('type', 'anonymised')->count(), 'and writes no second row');
        $this->assertSame([$id], $fired, 'and fires nothing again');
    }

    // ---- 6: who is skipped ------------------------------------------------------------------------------------------------------

    public function test_unfinished_open_money_and_recent_orders_are_skipped_counted_and_never_touched(): void
    {
        $placed = $this->oldOrder('2026-03-05 10:00:00', 'p@example.com', 'placed');
        $confirmed = $this->oldOrder('2026-03-05 10:00:00', 'c@example.com', 'confirmed');
        $shipped = $this->oldOrder('2026-03-05 10:00:00', 's@example.com', 'shipped');
        $owed = $this->oldOrder('2026-03-05 10:00:00', 'o@example.com', 'delivered');
        DB::table('payment_refunds')->where('order_id', $owed)->update(['status' => 'owed']);
        $unfinishedPayment = $this->oldOrder('2026-03-05 10:00:00', 'u@example.com', 'cancelled');
        DB::table('payments')->where('order_id', $unfinishedPayment)->update(['status' => 'pending', 'attempted_at' => null, 'created_at' => now()->subHour()]);
        $recent = $this->oldOrder('2036-03-05 10:00:00', 'r@example.com');
        $ok = $this->oldOrder('2026-03-05 10:00:00', 'ok@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $before = $this->snapshot();

        $out = $this->anonymise();

        $this->assertStringContainsString('Anonymised 1 order(s)', $out);
        $this->assertStringContainsString('skipped: 3 not finished, 2 with an open money matter', $out);
        $this->assertStringContainsString('failed: 0', $out, 'the others are never even tried (the domain refusal is the second line, not the first)');
        $this->assertNotNull(DB::table('orders')->where('id', $ok)->value('anonymised_at'));

        foreach ([$placed, $confirmed, $shipped, $owed, $unfinishedPayment, $recent] as $untouched) {
            $this->assertNull(DB::table('orders')->where('id', $untouched)->value('anonymised_at'), "order {$untouched} must stay as it is");
            $this->assertSame((array) DB::table('orders')->where('id', $untouched)->first(), $before['orders'][$untouched], "order {$untouched}");
        }
    }

    // ---- 7: the command's options -----------------------------------------------------------------------------------------------

    public function test_dry_run_writes_nothing_and_prints_the_count_and_the_cutoff(): void
    {
        $this->oldOrder('2026-03-05 10:00:00', 'a@example.com');
        $this->oldOrder('2026-03-06 10:00:00', 'b@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $before = $this->snapshot();

        $out = $this->anonymise('privacy:anonymise-orders', ['--dry-run' => true]);

        $this->assertStringContainsString('Dry run: 2 order(s) placed before 2027-01-01 00:00 EET', $out);
        $this->assertStringContainsString('retention 10 year(s)', $out);
        $this->assertStringContainsString('nothing was changed', $out);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_limit_is_respected_and_the_rest_waits_for_the_next_run(): void
    {
        $ids = [$this->oldOrder('2026-03-05 10:00:00', 'a@example.com'), $this->oldOrder('2026-03-06 10:00:00', 'b@example.com'), $this->oldOrder('2026-03-07 10:00:00', 'c@example.com')];
        $this->travelToLocal('2037-03-05 03:10:00');

        $out = $this->anonymise('privacy:anonymise-orders', ['--limit' => '2']);
        $this->assertStringContainsString('Anonymised 2 order(s)', $out);
        $this->assertStringContainsString('waiting for the next run: 1', $out);
        $this->assertSame(2, DB::table('orders')->whereNotNull('anonymised_at')->count());
        $this->assertNull(DB::table('orders')->where('id', $ids[2])->value('anonymised_at'), 'the oldest first, by id');

        $this->anonymise();
        $this->assertSame(3, DB::table('orders')->whereNotNull('anonymised_at')->count());

        foreach (['0', '5001', 'abc', '-1', '1.5'] as $bad) {
            $this->assertSame(AnonymiseOrders::INVALID, Artisan::call('privacy:anonymise-orders', ['--limit' => $bad]), "--limit={$bad}");
        }
    }

    public function test_an_order_that_fails_mid_way_does_not_roll_back_the_others(): void
    {
        $a = $this->oldOrder('2026-03-05 10:00:00', 'a@example.com');
        $b = $this->oldOrder('2026-03-06 10:00:00', 'b@example.com');
        $c = $this->oldOrder('2026-03-07 10:00:00', 'c@example.com');
        // an order row the domain refuses to load (an empty email): its anonymisation fails, the others' must stand
        DB::table('orders')->where('id', $b)->update(['email' => '']);
        $this->travelToLocal('2037-03-05 03:10:00');

        $out = $this->anonymise();

        $this->assertStringContainsString('Anonymised 2 order(s)', $out);
        $this->assertStringContainsString('failed: 1', $out);
        $this->assertNotNull(DB::table('orders')->where('id', $a)->value('anonymised_at'));
        $this->assertNull(DB::table('orders')->where('id', $b)->value('anonymised_at'));
        $this->assertNotNull(DB::table('orders')->where('id', $c)->value('anonymised_at'));
        $this->assertSame(0, DB::table('order_events')->where('order_id', $b)->where('type', 'anonymised')->count(), 'nothing of the failed order was written');
        $this->assertNotNull(DB::table('order_attributions')->where('order_id', $b)->value('ip'), 'its copies are untouched too');
    }

    // ---- 8: the editor and the admin view ---------------------------------------------------------------------------------------

    public function test_the_order_editor_refuses_an_anonymised_order_with_a_stable_reason(): void
    {
        $id = $this->oldOrder('2026-03-05 10:00:00');
        $this->travelToLocal('2037-03-05 03:10:00');
        $this->anonymise();

        try {
            app(OrderEditor::class)->apply($id, 0, [], new OrderDeliveryChange(OrderDeliveryType::STREET_ADDRESS, 'A', 'B', '+359888000000', 'BG', 'Varna', null, 'Sea 1', null, null, null, null, null), OrderPromotionCodeChange::unchanged(), null, null, null, new DateTimeImmutable('2037-03-06 10:00:00'));
            $this->fail('an anonymised order must not be edited');
        } catch (OrderNotEditableException $e) {
            $this->assertTrue($e->isBecauseAnonymised());
            $this->assertSame('order_anonymised', $e->reason());
        }

        $this->assertSame('Анонимизиран', DB::table('orders')->where('id', $id)->value('recipient_first_name'));
        $this->assertSame(0, (int) DB::table('orders')->where('id', $id)->value('edit_revision'));
    }

    public function test_the_admin_view_of_an_anonymised_order_renders_with_a_badge_and_a_normal_order_has_none(): void
    {
        $street = $this->oldOrder('2026-03-05 10:00:00', 'street@example.com');
        $pickup = $this->oldOrder('2026-03-06 10:00:00', 'pickup@example.com', 'cancelled', pickup: true);
        $recent = $this->oldOrder('2036-03-05 10:00:00', 'recent@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $this->anonymise();
        $this->staff();
        app()->setLocale('en');

        foreach ([$street, $pickup] as $id) {
            $html = Livewire::test(ViewOrder::class, ['record' => $id])->assertSuccessful()->html();
            $this->assertStringContainsString('Anonymised on 05.03.2037', $html);
            $this->assertStringContainsString('Order anonymised', $html, 'the history row');
            $this->assertStringNotContainsString('street@example.com', $html);
        }

        $this->assertStringNotContainsString('Anonymised on', Livewire::test(ViewOrder::class, ['record' => $recent])->assertSuccessful()->html());
    }

    public function test_the_block_buttons_are_absent_on_an_anonymised_order_and_present_on_a_normal_one_and_the_server_refuses_a_direct_call(): void
    {
        $old = $this->oldOrder('2026-03-05 10:00:00', 'old@example.com');
        $recent = $this->oldOrder('2036-03-05 10:00:00', 'recent@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $this->staff();

        $this->anonymise();

        $entry = fn (string $action): TestAction => TestAction::make($action)->schemaComponent(['phone' => 'phone', 'email' => 'email', 'ip' => 'block_ip'][substr($action, strpos($action, '_') + 1)], schema: 'infolist');

        $normal = Livewire::test(ViewOrder::class, ['record' => $recent])->assertSuccessful();
        $normal->assertActionVisible($entry('block_phone'))->assertActionVisible($entry('block_email'));

        // belt and braces: even if an anonymised order somehow held identity keys again (and one of them were blocked), the buttons stay away
        app(\App\Services\OrderBlockService::class)->block(OrderBlockType::EMAIL, 'recent@example.com', 180, null, $recent);
        DB::table('orders')->where('id', $old)->update(DB::table('orders')->where('id', $recent)->first(['phone_key', 'email_key']) ? (array) DB::table('orders')->where('id', $recent)->first(['phone_key', 'email_key']) : []);
        $blocks = DB::table('order_blocks')->count();

        $page = Livewire::test(ViewOrder::class, ['record' => $old])->assertSuccessful();
        foreach (['block_phone', 'block_email', 'unblock_phone', 'unblock_email'] as $action) {
            $page->assertActionHidden($entry($action));
        }

        // Filament refuses to mount an action that is not visible: the click never reaches the service
        $refused = false;
        try {
            $page->callAction($entry('block_email'), data: ['duration' => '180']);
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $refused = true;
        }
        $this->assertTrue($refused);
        $this->assertSame($blocks, DB::table('order_blocks')->count(), 'a refused click blocks nothing');

        // and the action itself says no even when it is run: the server-side guard
        $record = OrderModel::query()->findOrFail($old);
        $this->assertFalse(OrderBlockActions::block(OrderBlockType::EMAIL)->record($record)->isVisible());
        $this->assertFalse(OrderBlockActions::unblock(OrderBlockType::PHONE)->record($record)->isVisible());
        $this->assertTrue(OrderBlockActions::block(OrderBlockType::EMAIL)->record(OrderModel::query()->findOrFail($recent))->isVisible(), 'a normal order has the button');
    }

    // ---- 9, 10: the hook, the schedule ------------------------------------------------------------------------------------------

    public function test_the_hook_fires_after_the_commit_and_a_throwing_listener_does_not_undo_the_anonymisation(): void
    {
        $a = $this->oldOrder('2026-03-05 10:00:00', 'a@example.com');
        $b = $this->oldOrder('2026-03-06 10:00:00', 'b@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $seen = [];

        Hook::action('order.anonymised', function (string $orderId) use (&$seen): void {
            $seen[$orderId] = DB::table('orders')->where('id', $orderId)->value('anonymised_at') !== null;
        }, 5);
        Hook::action('order.anonymised', static function (): void {
            throw new \RuntimeException('an extension failed');
        }, 10);

        $out = $this->anonymise();

        $this->assertStringContainsString('Anonymised 2 order(s)', $out);
        $this->assertSame([$a => true, $b => true], $seen, 'once per order, after the commit');
        $this->assertSame(2, DB::table('orders')->whereNotNull('anonymised_at')->count());
    }

    public function test_the_command_is_in_the_schedule_at_ten_past_three_without_overlapping(): void
    {
        Artisan::call('schedule:list');
        $this->assertMatchesRegularExpression('/10\s+3\s+\*\s+\*\s+\*\s+php artisan privacy:anonymise-orders/', Artisan::output());

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'privacy:anonymise-orders'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping, 'a big backlog is worked off over several runs and two must never overlap');
    }

    // ---- 11: the cost -----------------------------------------------------------------------------------------------------------

    /** the number of queries one run of the command makes */
    private function queriesOfARun(): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->anonymise();

        return $count;
    }

    public function test_the_cost_of_a_run_is_a_fixed_part_plus_a_flat_cost_per_order_with_no_n_plus_one(): void
    {
        // a warm-up run (settings, translations and the like are read once per process), then 1, 2 and 3 orders in a run
        $this->oldOrder('2026-03-01 10:00:00', 'warm@example.com');
        $this->travelToLocal('2037-03-05 03:10:00');
        $this->queriesOfARun();
        $this->travelBack();

        $cost = [];

        foreach ([1, 2, 3] as $orders) {
            for ($i = 0; $i < $orders; $i++) {
                $this->oldOrder('2026-03-0'.(2 + $i).' 10:00:00', "o{$orders}-{$i}@example.com");
            }

            $this->travelToLocal('2037-03-05 03:10:00');
            $cost[$orders] = $this->queriesOfARun();
            $this->travelBack();
        }

        $marginal = $cost[2] - $cost[1];
        fwrite(STDERR, "\n[query-count] privacy:anonymise-orders: 1 order {$cost[1]} queries, 2 orders {$cost[2]}, 3 orders {$cost[3]} (per order {$marginal}, fixed ".($cost[1] - $marginal).")\n");

        $this->assertSame($marginal, $cost[3] - $cost[2], 'a flat cost per order: the third costs what the second did');
        $this->assertSame(self::PINNED_PER_ORDER, $marginal, 'queries per anonymised order (pinned)');
        $this->assertSame(self::PINNED_FIXED, $cost[1] - $marginal, 'queries of a run apart from the orders (pinned)');
    }
}
