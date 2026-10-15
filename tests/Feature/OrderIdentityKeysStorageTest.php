<?php

namespace Tests\Feature;

use App\Services\OrderDeliveryChange;
use App\Services\OrderEditor;
use App\Services\OrderPromotionCodeChange;
use DateTimeImmutable;
use EasyCo\Order\Enums\OrderDeliveryType;
use EasyCo\Order\IdentityKeys;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\PlacesRegionOrders;
use Tests\Concerns\RestoresSchemaWithoutRemigrating;
use Tests\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B (cod-protection-design.md section 2): the four identity keys on `orders` — written in the SAME insert
 * as the order, recomputed by the order editor, back-filled for existing rows by the migration, rebuilt by `orders:rebuild-identity-keys`, and
 * guarded by their indexes, CHECKs and short identifiers.
 */
class OrderIdentityKeysStorageTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;
    use RestoresSchemaWithoutRemigrating;

    protected function setUp(): void
    {
        parent::setUp();

        // PH-1: a phone typed by hand is read with the STORE's country (a configured shop always has one).
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\StoreCountry::KEY, 'BG');

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        $this->captureDatabaseFingerprint();
    }

    protected function tearDown(): void
    {
        $this->databaseMatchesFingerprint();
        parent::tearDown();
        $this->skipRemigrationIfClean();
    }

    /** @param array<string, mixed> $extra */
    private function place(array $extra = [], ?array $address = null): string
    {
        return (string) $this->checkout($address ?? $this->streetFields('BG', ['city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1']), $extra)->assertStatus(201)->json('order.id');
    }

    private function keys(string $order): object
    {
        return DB::table('orders')->where('id', $order)->first(['phone_key', 'email_key', 'name_key', 'place_key']);
    }

    // ---- written at placement, in the same INSERT --------------------------------------------------------------------------------------

    public function test_the_keys_are_written_with_the_order_in_one_insert_and_cost_no_query(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $order = $this->place(['phone' => '0888 123 456', 'email' => 'Maria@Example.COM', 'recipient_first_name' => 'Maria', 'recipient_last_name' => 'Petrova']);

        $keys = $this->keys($order);
        $this->assertSame('+359888123456', $keys->phone_key);
        $this->assertSame('maria@example.com', $keys->email_key);
        $this->assertSame('maria petrova', $keys->name_key);
        $this->assertSame(IdentityKeys::place(false, 'BG', 'Sofia', 'Vitosha Blvd 1', null, null), $keys->place_key);

        $inserts = array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'insert into `orders`')));
        $this->assertCount(1, $inserts);
        foreach (['phone_key', 'email_key', 'name_key', 'place_key'] as $column) {
            $this->assertStringContainsString('`'.$column.'`', $inserts[0], "{$column} is in the order's own INSERT");
        }
        $this->assertSame([], array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'update `orders` set `phone_key`')), 'and never written by a second statement');
    }

    public function test_a_pickup_orders_place_key_is_the_carrier_and_the_points_text(): void
    {
        $order = $this->place(['pickup_point_name' => 'Econt Mladost'], $this->pickupFields(['carrier_code' => 'econt', 'pickup_point_address' => 'Mladost 1, Sofia']));

        $this->assertSame(IdentityKeys::place(true, 'BG', null, null, 'econt', 'Mladost 1, Sofia'), $this->keys($order)->place_key);
    }

    public function test_an_old_row_with_a_short_phone_has_no_phone_key(): void
    {
        // PH-1: a number that short can no longer be ORDERED with (it is refused, PhoneValidationTest); an old row that holds one has no phone key.
        $order = $this->place();
        DB::table('orders')->where('id', $order)->update(['phone' => '1234567']);
        $this->artisan('orders:rebuild-identity-keys')->assertSuccessful();

        $this->assertNull($this->keys($order)->phone_key);
        $this->assertNotNull($this->keys($order)->email_key);
    }

    // ---- the editor recomputes them ----------------------------------------------------------------------------------------------------

    public function test_the_order_editor_recomputes_the_keys_when_the_phone_the_name_or_the_place_change(): void
    {
        $order = $this->place(['phone' => '0888 123 456']);
        $before = $this->keys($order);
        $this->assertSame('+359888123456', $before->phone_key);

        app(OrderEditor::class)->apply(
            orderId: $order,
            expectedRevision: (int) OrderModel::findOrFail($order)->edit_revision,
            lineChanges: [],
            delivery: new OrderDeliveryChange(OrderDeliveryType::STREET_ADDRESS, 'New', 'Recipient', '+359 877 654 321', 'BG', 'Plovdiv', null, 'Main Street 5', null, 'econt'),
            promotionCode: OrderPromotionCodeChange::unchanged(),
            editedBy: null,
            editedByName: null,
            reason: null,
            occurredAt: new DateTimeImmutable(),
        );

        $after = $this->keys($order);
        $this->assertSame('877654321', $after->phone_key, 'the new phone');
        $this->assertSame('new recipient', $after->name_key, 'the new name');
        $this->assertSame(IdentityKeys::place(false, 'BG', 'Plovdiv', 'Main Street 5', null, null), $after->place_key, 'the new place');
        $this->assertSame($before->email_key, $after->email_key, 'the email cannot be edited and its key is unchanged');
    }

    // ---- the rebuild command ----------------------------------------------------------------------------------------------------------

    public function test_the_rebuild_command_repairs_every_key_and_a_second_run_changes_nothing(): void
    {
        $first = $this->place(['phone' => '0888 123 456']);
        $second = $this->place(['phone' => '0877 000 111', 'email' => 'other@example.com']);
        DB::table('orders')->update(['phone_key' => null, 'email_key' => null, 'name_key' => null, 'place_key' => null]);
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->artisan('orders:rebuild-identity-keys')->assertSuccessful()->expectsOutputToContain('Checked 2 order(s); rebuilt the identity keys of 2.');

        $this->assertSame('+359888123456', $this->keys($first)->phone_key);
        $this->assertSame('+359877000111', $this->keys($second)->phone_key);
        $this->assertSame('other@example.com', $this->keys($second)->email_key);
        $this->assertSame(['orders.identity_keys_rebuilt{"orders":2,"changed":2}'], $logged, 'counts only');

        $this->artisan('orders:rebuild-identity-keys')->assertSuccessful()->expectsOutputToContain('rebuilt the identity keys of 0.');
    }

    public function test_the_rebuild_command_prints_and_logs_no_personal_value(): void
    {
        $this->place(['phone' => '0888 123 456', 'email' => 'secret.person@example.com']);
        DB::table('orders')->update(['phone_key' => null, 'email_key' => null]);
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class, function ($event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->artisan('orders:rebuild-identity-keys')->doesntExpectOutputToContain('secret')->doesntExpectOutputToContain('888123456')->assertSuccessful();

        $this->assertNotSame([], $logged);
        $this->assertStringNotContainsString('secret', implode('', $logged));
        $this->assertStringNotContainsString('888123456', implode('', $logged));
    }

    // ---- the migration: back-fill, indexes, CHECKs, names -----------------------------------------------------------------------------

    public function test_the_migration_back_fills_existing_orders_and_is_idempotent(): void
    {
        $order = $this->place(['phone' => '+359 888 123 456']);
        $migration = require base_path('packages/EasyCo/Order/database/migrations/2026_10_19_000001_add_identity_keys_to_orders_table.php');

        // The state of an order that existed BEFORE the stage: the columns are there, the keys are not.
        DB::table('orders')->where('id', $order)->update(['phone_key' => null, 'email_key' => null, 'name_key' => null, 'place_key' => null]);

        $migration->up();

        $this->assertSame('+359888123456', $this->keys($order)->phone_key, 'back-filled');
        $this->assertNotNull($this->keys($order)->email_key);
        $this->assertNotNull($this->keys($order)->place_key);

        $keys = (array) $this->keys($order);
        $migration->up();
        $this->assertSame($keys, (array) $this->keys($order), 'a second run changes nothing');
    }

    public function test_the_migration_round_trips_without_losing_the_other_columns(): void
    {
        $migration = require base_path('packages/EasyCo/Order/database/migrations/2026_10_19_000001_add_identity_keys_to_orders_table.php');
        $columns = Schema::getColumnListing('orders');
        $this->assertContains('phone_key', $columns);

        $migration->down();

        foreach (['phone_key', 'email_key', 'name_key', 'place_key'] as $column) {
            $this->assertFalse(Schema::hasColumn('orders', $column));
        }
        $this->assertSame(array_values(array_diff($columns, ['phone_key', 'email_key', 'name_key', 'place_key'])), Schema::getColumnListing('orders'));

        $migration->up();

        $this->assertEqualsCanonicalizing($columns, Schema::getColumnListing('orders'));
    }

    public function test_the_columns_the_indexes_and_the_checks_are_what_the_design_says(): void
    {
        $create = (string) DB::selectOne('SHOW CREATE TABLE `orders`')->{'Create Table'};

        foreach (['`phone_key` varchar(20)', '`email_key` varchar(255)', '`name_key` varchar(201)', '`place_key` char(40)'] as $fragment) {
            $this->assertStringContainsString($fragment, $create);
        }
        foreach (['ord_phone_key_index' => 'phone_key', 'ord_email_key_index' => 'email_key', 'ord_name_key_index' => 'name_key', 'ord_place_key_index' => 'place_key'] as $name => $column) {
            $this->assertMatchesRegularExpression('/KEY `'.$name.'` \(`'.$column.'`\)/', $create);
        }
        $this->assertStringContainsString('ord_phone_key_check', $create);
        $this->assertStringContainsString('ord_place_key_check', $create);

        foreach (['ord_phone_key_index', 'ord_email_key_index', 'ord_name_key_index', 'ord_place_key_index', 'ord_phone_key_check', 'ord_place_key_check'] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    public function test_a_phone_key_that_is_not_nine_digits_is_refused_by_the_database(): void
    {
        $order = $this->place();

        foreach (['ord_phone_key_check' => ['phone_key' => '12345'], 'ord_place_key_check' => ['place_key' => 'short']] as $check => $bad) {
            try {
                DB::table('orders')->where('id', $order)->update($bad);
                $this->fail("{$check} accepted a bad key");
            } catch (QueryException $e) {
                $this->assertStringContainsString($check, $e->getMessage());
            }
        }
    }
}
