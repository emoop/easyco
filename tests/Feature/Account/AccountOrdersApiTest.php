<?php

namespace Tests\Feature\Account;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\Feature\Account\Concerns\MakesAccountOrders;
use Tests\TestCase;

/** ACC-2 decisions 1 and 2: the customer's own order list and order detail. */
class AccountOrdersApiTest extends TestCase
{
    use DrivesAccountApi;
    use MakesAccountOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        config(['ratelimits.account-read' => 1000]);
    }

    private function me(string $email = 'me@example.com'): AccountModel
    {
        $account = $this->verifiedAccount($email);
        $this->actingAs($account, 'customer');

        return $account;
    }

    // ---- list -----------------------------------------------------------------------------------------------------------------

    public function test_the_list_holds_only_her_orders_newest_first_with_the_documented_fields(): void
    {
        $me = $this->me();
        $other = $this->verifiedAccount('other@example.com');

        $old = $this->makeOrder('me@example.com', (string) $me->getKey(), '2026-09-01 10:00:00', [['quantity' => 1, 'unit' => 1000]]);
        $new = $this->makeOrder('me@example.com', (string) $me->getKey(), '2026-10-05 12:30:00', [['quantity' => 2, 'unit' => 1500], ['quantity' => 3, 'unit' => 100]]);
        $this->makeOrder('other@example.com', (string) $other->getKey(), '2026-10-06 12:00:00');   // another account's
        $this->makeOrder('me@example.com', null, '2026-10-07 12:00:00');                            // a GUEST order with her own email

        $response = $this->getJson('/api/account/orders')->assertOk();

        $this->assertSame([$new, $old], array_column($response->json('data'), 'number'));

        $first = $response->json('data.0');
        $this->assertSame(['number', 'placed_at', 'status', 'status_label', 'total', 'total_minor', 'currency', 'items_count'], array_keys($first));
        $this->assertSame('placed', $first['status']);
        $this->assertSame('Placed', $first['status_label'] === 'Placed' ? 'Placed' : $first['status_label'], 'a label in the store locale');
        $this->assertNotSame('placed', $first['status_label']);
        $this->assertSame(3300, $first['total_minor']);
        $this->assertSame('EUR', $first['currency']);
        $this->assertSame(5, $first['items_count'], '2 + 3 units');
        $this->assertSame(1, $response->json('data.1.items_count'));
        $this->assertMatchesRegularExpression('/^2026-10-05T\d{2}:30:00[+-]\d{2}:\d{2}|Z$/', $first['placed_at']);
        $this->assertStringContainsString('33', $first['total']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2], $response->json('meta'));
    }

    public function test_twenty_a_page_and_the_second_page(): void
    {
        $me = $this->me();

        for ($i = 1; $i <= 25; $i++) {
            $this->makeOrder('me@example.com', (string) $me->getKey(), sprintf('2026-10-%02d 10:00:00', $i));
        }

        $one = $this->getJson('/api/account/orders')->assertOk();
        $two = $this->getJson('/api/account/orders?page=2')->assertOk();

        $this->assertCount(20, $one->json('data'));
        $this->assertCount(5, $two->json('data'));
        $this->assertSame(['current_page' => 2, 'last_page' => 2, 'per_page' => 20, 'total' => 25], $two->json('meta'));
        $this->assertEmpty(array_intersect(array_column($one->json('data'), 'number'), array_column($two->json('data'), 'number')));
        $this->getJson('/api/account/orders?page=0')->assertStatus(422);
    }

    public function test_the_list_needs_a_logged_in_customer(): void
    {
        $this->getJson('/api/account/orders')->assertStatus(401);
        $this->getJson('/api/account/orders/1')->assertStatus(401);
    }

    /** the number of queries does not grow with the number of orders */
    public function test_the_query_count_is_constant_for_one_and_for_twenty_five_orders(): void
    {
        $me = $this->me();
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/account/orders')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->makeOrder('me@example.com', (string) $me->getKey());
        $count();                                  // warm: settings, locale, the session
        $one = $count();

        for ($i = 0; $i < 24; $i++) {
            $this->makeOrder('me@example.com', (string) $me->getKey(), sprintf('2026-10-%02d 10:00:00', $i + 1));
        }

        $many = $count();

        fwrite(STDERR, "\n[query-count] GET /api/account/orders: 1 order {$one}, 25 orders {$many}\n");
        $this->assertSame($one, $many, 'constant in the number of orders');
    }

    // ---- detail ---------------------------------------------------------------------------------------------------------------

    /** keys (recursively) that must never appear in an answer */
    private const NEVER = ['staff_note', 'notes', 'history', 'events', 'audit', 'edit_revision', 'ip', 'ip_key', 'user_agent', 'client_id', 'transaction_id', 'account_id', 'address_id', 'unit_cost', 'cost', 'profit', 'profit_minor', 'block', 'blocked', 'fraud', 'id', 'email', 'phone', 'phone_key', 'email_key', 'sku'];

    private function allKeys(mixed $value): array
    {
        $keys = [];

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $keys[] = (string) $key;
                $keys = array_merge($keys, $this->allKeys($item));
            }
        }

        return $keys;
    }

    public function test_her_order_has_the_documented_fields_and_none_of_the_internal_ones(): void
    {
        $me = $this->me();
        $number = $this->makeOrder('me@example.com', (string) $me->getKey(), '2026-10-05 12:30:00', [['quantity' => 2, 'unit' => 1500], ['quantity' => 1, 'unit' => 250]], "first line\n\nsecond line");
        DB::table('orders')->where('id', $number)->update(['tracking_number' => 'TRK-123', 'shipping_courier' => 'Speedy']);

        $order = $this->getJson('/api/account/orders/'.$number)->assertOk()->json('data');

        $this->assertSame($number, $order['number']);
        $this->assertSame('placed', $order['status']);
        $this->assertNotSame('placed', $order['status_label']);
        $this->assertSame(3250, $order['total_minor']);

        $this->assertCount(2, $order['lines']);
        $this->assertSame(['name', 'attributes', 'quantity', 'unit_price', 'line_total'], array_keys($order['lines'][0]));
        $this->assertSame('Product 0', $order['lines'][0]['name']);
        $this->assertSame('M', $order['lines'][0]['attributes']);
        $this->assertSame(2, $order['lines'][0]['quantity']);
        $this->assertStringContainsString('15', $order['lines'][0]['unit_price']);
        $this->assertStringContainsString('30', $order['lines'][0]['line_total']);

        $this->assertSame(['subtotal', 'discount', 'shipping', 'total'], array_keys($order['totals']));
        $this->assertNull($order['totals']['discount']);
        $this->assertSame('Free delivery', $order['totals']['shipping']['name']);
        $this->assertSame('Free delivery', $order['delivery']['method']);
        $this->assertSame('address', $order['delivery']['kind']);
        $this->assertContains('Vitosha Blvd 1', $order['delivery']['lines']);
        $this->assertSame(['number' => 'TRK-123', 'courier' => 'Speedy'], $order['tracking']);
        $this->assertSame("first line\nsecond line", $order['note']);
        $this->assertArrayHasKey('payment', $order);

        // NEVER: recursively, by key
        $keys = $this->allKeys($order);
        foreach (self::NEVER as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "the key «{$forbidden}» must not be in the answer");
        }
        // ... and the cost / profit figure written into the fixture (777) is nowhere in the text
        $this->assertStringNotContainsString('777', json_encode($order));
    }

    public function test_an_order_without_a_tracking_number_has_no_tracking(): void
    {
        $me = $this->me();
        $number = $this->makeOrder('me@example.com', (string) $me->getKey());

        $this->assertNull($this->getJson('/api/account/orders/'.$number)->assertOk()->json('data.tracking'));
    }

    /** ★ another account's, a guest and an unknown number are the IDENTICAL 404 */
    public function test_someone_elses_a_guest_and_an_unknown_order_are_the_same_404(): void
    {
        $me = $this->me();
        $other = $this->verifiedAccount('other@example.com');
        $mine = $this->makeOrder('me@example.com', (string) $me->getKey());
        $theirs = $this->makeOrder('other@example.com', (string) $other->getKey());
        $guest = $this->makeOrder('me@example.com', null);

        $this->getJson('/api/account/orders/'.$mine)->assertOk();

        $bodies = [];
        foreach ([$theirs, $guest, '99999999'] as $number) {
            $bodies[] = $this->getJson('/api/account/orders/'.$number)->assertStatus(404)->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
        $this->assertSame('Order not found.', json_decode($bodies[0], true)['message']);
        $this->getJson('/api/account/orders/abc')->assertStatus(404);   // the route only takes digits
    }

    public function test_the_detail_is_built_on_the_order_received_reader(): void
    {
        $me = $this->me();
        $number = $this->makeOrder('me@example.com', (string) $me->getKey());
        $received = app(\App\Storefront\Orders\OrderReceivedReader::class)->find($number);

        $order = $this->getJson('/api/account/orders/'.$number)->assertOk()->json('data');

        $this->assertSame($received['total'], $order['totals']['total']);
        $this->assertSame($received['subtotal'], $order['totals']['subtotal']);
        $this->assertSame($received['destination']['lines'], $order['delivery']['lines']);
        $this->assertSame(array_column($received['lines'], 'total'), array_column($order['lines'], 'line_total'));
    }
}
