<?php

namespace Tests\Feature\Account;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use EasyCo\Catalog\Contracts\ProductRepository;
use EasyCo\Catalog\Product;
use EasyCo\Inventory\Contracts\StockLevelRepository;
use EasyCo\Inventory\StockLevel;
use EasyCo\Pricing\Contracts\PriceListItemRepository;
use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListItemTargetType;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Money;
use EasyCo\Pricing\Price;
use EasyCo\Pricing\PriceList;
use EasyCo\Pricing\PriceListItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProvidesCheckoutShipping;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-2b: the address book: delete, ONE default per account (in the database), the 20-address cap, checkout's dedupe and cap. */
class AccountAddressBookTest extends TestCase
{
    use DrivesAccountApi;
    use ProvidesCheckoutShipping;
    use RefreshDatabase;

    private static int $variationCounter = 0;

    private ?PriceList $priceList = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    // ---- fixtures -------------------------------------------------------------------------------------------------------------

    /** a verified account, logged in over the real login route (a real session, like the browser's) */
    private function me(string $email = 'me@example.com'): AccountModel
    {
        $account = $this->verifiedAccount($email);
        $this->login($email)->assertOk();

        return $account;
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'delivery_type' => 'street_address',
            'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov',
            'phone' => '+359888123456',
            'country' => 'BG', 'city' => 'Sofia', 'postal_code' => '1000', 'address_line_1' => 'Vitosha Blvd 1',
        ], $override);
    }

    private function saveAddress(array $override = []): string
    {
        return (string) $this->postJson('/api/addresses', $this->payload($override))->assertStatus(201)->json('id');
    }

    /** a row written straight into the table, with a chosen updated_at (and optionally the default marker) */
    private function rawAddress(AccountModel|int|null $account, string $updatedAt = '2026-10-01 10:00:00', ?int $marker = null, string $line = 'Vitosha Blvd 1'): int
    {
        return (int) DB::table('addresses')->insertGetId([
            'account_id' => $account instanceof AccountModel ? $account->getKey() : $account,
            'delivery_type' => 'street_address', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov',
            'phone' => '+359888123456', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => $line,
            'default_marker' => $marker, 'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }

    private function defaultIdOf(AccountModel $account): ?int
    {
        $id = DB::table('addresses')->where('account_id', $account->getKey())->where('default_marker', 1)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function pricedVariation(): string
    {
        self::$variationCounter++;
        $n = self::$variationCounter;
        $product = Product::createSimple("Book Product {$n}", "BK-{$n}", "book-product-{$n}");
        app(ProductRepository::class)->save($product);
        $variationId = $product->variations()[0]->id();

        $this->priceList ??= tap(PriceList::createSystemList('Regular Prices', PriceListMode::FIXED_ITEMS, priority: 0), fn (PriceList $list) => app(PriceListRepository::class)->save($list));
        app(PriceListItemRepository::class)->save(new PriceListItem(null, $this->priceList->id(), PriceListItemTargetType::VARIATION, $variationId, Price::exclusiveOfTax(Money::fromDecimal('10.00', 'EUR'), 0)));
        app(StockLevelRepository::class)->save(StockLevel::forVariation($variationId, 100));

        return $variationId;
    }

    /** places an order as the logged-in customer: with a saved address, or with the typed fields; returns the order id */
    private function placeOrder(?string $addressId = null, array $typed = []): string
    {
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');

        $base = ['cart_id' => $cartId, 'email' => 'me@example.com', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888123456', 'payment_method' => 'cash_on_delivery'];
        $where = $addressId !== null
            ? ['address_id' => $addressId]
            : array_merge(['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1'], $typed);

        return (string) $this->postJson('/api/checkout', [...$base, ...$where, ...$this->shippingPayload()])->assertStatus(201)->json('order.id');
    }

    /**
     * the queries one logged-in checkout with a typed address runs (the cart is built before the listener starts). The confirmation job is faked, as in
     * CheckoutHardeningTest's pin: it measures a production request.
     *
     * @return array{total: int, address: list<string>}
     */
    private function measuredCheckout(array $typed = []): array
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Mail\SendMailJob::class]);
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');
        $payload = [
            'cart_id' => $cartId, 'email' => 'me@example.com', 'recipient_first_name' => 'Ivan', 'recipient_last_name' => 'Ivanov', 'phone' => '+359888123456', 'payment_method' => 'cash_on_delivery',
            ...array_merge(['delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1'], $typed), ...$this->shippingPayload(),
        ];
        $this->app->forgetScopedInstances();

        $seen = ['total' => 0, 'address' => []];
        DB::listen(function ($query) use (&$seen): void {
            $seen['total']++;
            if (stripos($query->sql, "addresses") !== false) {
                $seen['address'][] = $query->sql;
            }
        });

        $this->postJson('/api/checkout', $payload)->assertStatus(201);

        return $seen;
    }

    /** every column of the order row except the link to the saved address and the touch time */
    private function orderSnapshot(string $orderId): string
    {
        $row = (array) DB::table('orders')->where('id', $orderId)->first();
        unset($row['address_id'], $row['updated_at']);

        return json_encode($row, JSON_THROW_ON_ERROR);
    }

    // ---- delete ---------------------------------------------------------------------------------------------------------------

    /** the row is gone, the order placed earlier with it keeps its snapshot byte for byte, and address_id is NULL */
    public function test_deleting_an_own_address_is_204_and_the_order_placed_with_it_keeps_its_snapshot(): void
    {
        $this->me();
        $addressId = $this->saveAddress(['address_line_1' => 'Rakovski 12', 'region' => 'Sofia-grad']);
        $orderId = $this->placeOrder($addressId);

        $this->assertSame($addressId, (string) DB::table('orders')->where('id', $orderId)->value('address_id'));
        $before = $this->orderSnapshot($orderId);
        $this->assertStringContainsString('Rakovski 12', $before);

        $this->deleteJson("/api/addresses/{$addressId}")->assertNoContent();

        $this->assertDatabaseMissing('addresses', ['id' => $addressId]);
        $this->assertSame($before, $this->orderSnapshot($orderId), 'the order snapshot is byte for byte the same');
        $this->assertNull(DB::table('orders')->where('id', $orderId)->value('address_id'));
    }

    public function test_another_accounts_address_and_an_unknown_id_are_the_same_404_and_nothing_is_deleted(): void
    {
        $other = $this->verifiedAccount('other@example.com');
        $theirs = $this->rawAddress($other);
        $this->me();

        $foreign = $this->deleteJson("/api/addresses/{$theirs}")->assertNotFound();
        $unknown = $this->deleteJson('/api/addresses/999999')->assertNotFound();

        $this->assertSame(str_replace((string) $theirs, 'X', $foreign->getContent()), str_replace('999999', 'X', $unknown->getContent()), 'the same body, no enumeration');
        $this->assertDatabaseHas('addresses', ['id' => $theirs]);

        $this->postJson("/api/addresses/{$theirs}/default")->assertNotFound();
        $this->postJson('/api/addresses/999999/default')->assertNotFound();
        $this->assertNull(DB::table('addresses')->where('id', $theirs)->value('default_marker'), 'another account\'s address is not made a default');
    }

    public function test_unauthenticated_and_a_stale_session_answer_like_the_other_account_routes(): void
    {
        $account = $this->verifiedAccount('me@example.com');
        $id = $this->rawAddress($account);

        $guestOrders = $this->getJson('/api/account/orders')->assertStatus(401);
        $this->deleteJson("/api/addresses/{$id}")->assertStatus(401)->assertExactJson($guestOrders->json());
        $this->postJson("/api/addresses/{$id}/default")->assertStatus(401)->assertExactJson($guestOrders->json());

        // a stale session: the password was changed elsewhere (version 1), this session still carries 0
        $this->login('me@example.com')->assertOk();
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertOk();
        $this->flushSession();
        $this->withSession([AccountSessionVersion::SESSION_KEY => 0]);
        $this->actingAs($account->fresh(), 'customer');

        $staleOrders = $this->getJson('/api/account/orders')->assertStatus(401);
        $this->deleteJson("/api/addresses/{$id}")->assertStatus(401)->assertExactJson($staleOrders->json());
        $this->postJson("/api/addresses/{$id}/default")->assertStatus(401)->assertExactJson($staleOrders->json());
        $this->assertDatabaseHas('addresses', ['id' => $id]);
    }

    // ---- default --------------------------------------------------------------------------------------------------------------

    public function test_the_first_saved_address_is_the_default_and_setting_another_moves_it_and_setting_the_same_again_is_a_no_op(): void
    {
        $me = $this->me();
        $first = $this->saveAddress();
        $second = $this->saveAddress(['address_line_1' => 'Rakovski 12']);

        $this->assertSame((int) $first, $this->defaultIdOf($me), 'the first one is the default, the second is not');

        $this->postJson("/api/addresses/{$second}/default")->assertOk()->assertJsonPath('id', $second)->assertJsonPath('is_default', true);
        $this->assertSame((int) $second, $this->defaultIdOf($me));
        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->where('default_marker', 1)->count());

        $stamp = DB::table('addresses')->where('id', $second)->value('updated_at');
        $this->travel(5)->minutes();
        $this->postJson("/api/addresses/{$second}/default")->assertOk();
        $this->assertSame((int) $second, $this->defaultIdOf($me));
        $this->assertSame($stamp, DB::table('addresses')->where('id', $second)->value('updated_at'), 'setting the same default again changes nothing');
    }

    /** ★ a second default for one account cannot exist, however the row gets there */
    public function test_two_defaults_for_one_account_are_impossible_in_the_database(): void
    {
        $me = $this->verifiedAccount('me@example.com');
        $other = $this->verifiedAccount('other@example.com');
        $this->rawAddress($me, marker: 1);
        $second = $this->rawAddress($me);

        try {
            $this->rawAddress($me, marker: 1);
            $this->fail('a raw INSERT of a second default must throw');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }

        try {
            DB::table('addresses')->where('id', $second)->update(['default_marker' => 1]);
            $this->fail('a raw UPDATE making a second default must throw');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0]);
        }

        // what stays legal: another account's default, any number of non-defaults, guest rows
        $this->rawAddress($other, marker: 1);
        $this->rawAddress($me);
        $this->rawAddress(null);
        $this->rawAddress(null);
        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->where('default_marker', 1)->count());
    }

    public function test_deleting_the_default_promotes_the_most_recently_updated_and_deleting_the_last_leaves_none(): void
    {
        $me = $this->me();
        $default = $this->rawAddress($me, '2026-10-01 10:00:00', 1);
        $older = $this->rawAddress($me, '2026-10-02 10:00:00');
        $tieLow = $this->rawAddress($me, '2026-10-05 10:00:00');
        $tieHigh = $this->rawAddress($me, '2026-10-05 10:00:00');

        $this->deleteJson("/api/addresses/{$older}")->assertNoContent();
        $this->assertSame($default, $this->defaultIdOf($me), 'deleting a non-default changes no default');

        $this->deleteJson("/api/addresses/{$default}")->assertNoContent();
        $this->assertSame($tieHigh, $this->defaultIdOf($me), 'the newest updated_at wins, the higher id on a tie');

        $this->deleteJson("/api/addresses/{$tieHigh}")->assertNoContent();
        $this->assertSame($tieLow, $this->defaultIdOf($me));

        $this->deleteJson("/api/addresses/{$tieLow}")->assertNoContent();
        $this->assertNull($this->defaultIdOf($me));
        $this->assertSame(0, DB::table('addresses')->where('account_id', $me->getKey())->count());
    }

    public function test_the_list_returns_is_default_and_puts_the_default_first_then_the_old_order(): void
    {
        $this->me();
        $a = $this->saveAddress(['address_line_1' => 'A 1']);
        $b = $this->saveAddress(['address_line_1' => 'B 2']);
        $c = $this->saveAddress(['address_line_1' => 'C 3']);

        $this->assertSame([$a, $b, $c], array_column($this->getJson('/api/addresses')->assertOk()->json('data'), 'id'));

        $this->postJson("/api/addresses/{$c}/default")->assertOk();
        $data = $this->getJson('/api/addresses')->assertOk()->json('data');

        $this->assertSame([$c, $a, $b], array_column($data, 'id'));
        $this->assertSame([true, false, false], array_column($data, 'is_default'));
    }

    // ---- the cap --------------------------------------------------------------------------------------------------------------

    public function test_the_21st_address_is_a_422_with_an_error_key(): void
    {
        $me = $this->me();
        for ($i = 1; $i <= 20; $i++) {
            $this->rawAddress($me, line: "Street {$i}");
        }

        $this->postJson('/api/addresses', $this->payload())->assertStatus(422)->assertJsonValidationErrors(['address_limit']);
        $this->assertSame(20, DB::table('addresses')->where('account_id', $me->getKey())->count());

        $this->deleteJson('/api/addresses/'.DB::table('addresses')->where('account_id', $me->getKey())->value('id'))->assertNoContent();
        $this->postJson('/api/addresses', $this->payload())->assertStatus(201);
    }

    // ---- checkout -------------------------------------------------------------------------------------------------------------

    public function test_checkout_saves_a_typed_address_once_and_reuses_an_equal_one_after_normalisation(): void
    {
        $me = $this->me();

        $this->placeOrder();
        $id = (int) DB::table('addresses')->where('account_id', $me->getKey())->value('id');
        $this->assertSame($id, $this->defaultIdOf($me), 'a first address saved by checkout is the default too');
        DB::table('addresses')->where('id', $id)->update(['updated_at' => '2026-01-01 00:00:00']);

        $orderId = $this->placeOrder(typed: ['city' => '  SOFIA ', 'address_line_1' => 'vitosha   blvd 1']);

        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->count(), 'reused, not inserted');
        $this->assertNotSame('2026-01-01 00:00:00', DB::table('addresses')->where('id', $id)->value('updated_at'), 'updated_at is bumped');
        $this->assertSame((string) $id, (string) DB::table('orders')->where('id', $orderId)->value('address_id'));

        $this->placeOrder(typed: ['address_line_1' => 'Another street 5']);
        $this->assertSame(2, DB::table('addresses')->where('account_id', $me->getKey())->count(), 'a different address is a new row');
        $this->assertSame($id, $this->defaultIdOf($me), 'the default does not move');
    }

    public function test_checkout_at_the_cap_places_the_order_with_its_snapshot_and_a_null_address_id(): void
    {
        $me = $this->me();
        for ($i = 1; $i <= 20; $i++) {
            $this->rawAddress($me, line: "Street {$i}");
        }

        $orderId = $this->placeOrder(typed: ['address_line_1' => 'The 21st street 21']);

        $this->assertSame(20, DB::table('addresses')->where('account_id', $me->getKey())->count(), 'nothing new is saved');
        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNull($order->address_id);
        $this->assertSame('The 21st street 21', $order->address_line_1);
        $this->assertSame((string) $me->getKey(), (string) $order->account_id);
    }

    public function test_a_guest_checkout_still_saves_a_row_with_no_account_and_no_default(): void
    {
        $cartId = (string) $this->postJson('/api/cart/lines', ['variation_id' => $this->pricedVariation(), 'quantity' => 1])->assertStatus(201)->json('cart_id');
        $this->postJson('/api/checkout', ['cart_id' => $cartId, 'email' => 'g@example.com', 'recipient_first_name' => 'G', 'recipient_last_name' => 'B', 'phone' => '+359888123456', 'payment_method' => 'cash_on_delivery',
            'delivery_type' => 'street_address', 'country' => 'BG', 'city' => 'Sofia', 'address_line_1' => 'Vitosha Blvd 1', ...$this->shippingPayload()])->assertStatus(201);

        $row = DB::table('addresses')->first();
        $this->assertNull($row->account_id);
        $this->assertNull($row->default_marker);
    }

    // ---- the migration --------------------------------------------------------------------------------------------------------

    public function test_the_migration_backfill_picks_the_most_recently_updated_address_of_each_account(): void
    {
        $a = $this->verifiedAccount('a@example.com');
        $b = $this->verifiedAccount('b@example.com');
        $c = $this->verifiedAccount('c@example.com');

        $this->rawAddress($a, '2026-09-01 10:00:00');
        $aNew = $this->rawAddress($a, '2026-10-09 10:00:00');
        $this->rawAddress($a, '2026-10-01 10:00:00');
        $this->rawAddress($b, '2026-10-05 10:00:00');
        $bHigh = $this->rawAddress($b, '2026-10-05 10:00:00');
        $cOnly = $this->rawAddress($c, '2026-08-01 10:00:00');
        $guest = $this->rawAddress(null, '2026-10-09 10:00:00');

        // the state before the migration: no marker anywhere
        DB::table('addresses')->update(['default_marker' => null]);
        $stamps = DB::table('addresses')->pluck('updated_at', 'id')->all();

        (require base_path('packages/EasyCo/Address/database/migrations/2026_11_19_000001_add_default_marker_to_addresses.php'))->up();

        $defaults = DB::table('addresses')->where('default_marker', 1)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$aNew, $bHigh, $cOnly], $defaults);
        $this->assertNull(DB::table('addresses')->where('id', $guest)->value('default_marker'));
        $this->assertSame($stamps, DB::table('addresses')->pluck('updated_at', 'id')->all(), 'the backfill does not touch updated_at');
    }

    // ---- concurrency: the two default-changing operations, back to back, in either order --------------------------------------

    public function test_delete_of_the_default_and_set_default_back_to_back_in_either_order_leave_exactly_one_default_and_never_throw(): void
    {
        $me = $this->me();
        $first = $this->rawAddress($me, '2026-10-01 10:00:00', 1);
        $second = $this->rawAddress($me, '2026-10-02 10:00:00');
        $third = $this->rawAddress($me, '2026-10-03 10:00:00');
        $repository = app(\EasyCo\Address\Contracts\AddressRepository::class);

        // order 1: delete the default, then set another one
        $this->assertTrue($repository->deleteOwned((string) $first, (string) $me->getKey()));
        $this->assertTrue($repository->setDefault((string) $second, (string) $me->getKey()));
        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->where('default_marker', 1)->count());
        $this->assertSame($second, $this->defaultIdOf($me));

        // order 2: set another one, then delete the (new) default
        $this->assertTrue($repository->setDefault((string) $third, (string) $me->getKey()));
        $this->assertTrue($repository->deleteOwned((string) $third, (string) $me->getKey()));
        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->where('default_marker', 1)->count());
        $this->assertSame($second, $this->defaultIdOf($me), 'the only one left is the default');

        // a set-default of an address that was just deleted is the same 404 answer, not an error
        $this->assertFalse($repository->setDefault((string) $first, (string) $me->getKey()));
    }

    // ---- the query-count pins of a LOGGED-IN checkout with a typed address (the guest pin, 63, is CheckoutHardeningTest's) -------

    /**
     * Pins (measured, ACC-2b follow-up). Address-table queries: NEW = select + insert + default statement (3), REUSED = select + touch (2),
     * AT THE CAP = the select only (1). EXPLAIN of the dedupe select (`where account_id = ? order by default_marker is null, id`): type=ref,
     * key=addr_account_default_unique (the account_id prefix of the unique index; addr_account_id_index is the other candidate), rows=1 here,
     * Using filesort over the account's handful of rows (the cap is 20): an index range, never a table scan.
     */
    public function test_the_query_count_of_a_logged_in_typed_checkout_new_reused_and_at_the_cap(): void
    {
        $me = $this->me();

        // NEW: the account has no address yet, so the checkout inserts one and makes it the default
        $new = $this->measuredCheckout();
        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->count());

        // REUSED: the same address again: the dedupe select + one touch, no insert
        $reused = $this->measuredCheckout();
        $this->assertSame(1, DB::table('addresses')->where('account_id', $me->getKey())->count());

        // AT THE CAP: twenty OTHER addresses: the dedupe select, no write at all
        DB::table('addresses')->where('account_id', $me->getKey())->delete();
        for ($i = 1; $i <= 20; $i++) {
            $this->rawAddress($me, line: "Street {$i}");
        }
        $cap = $this->measuredCheckout(['address_line_1' => 'The 21st street 21']);
        $this->assertSame(20, DB::table('addresses')->where('account_id', $me->getKey())->count());

        $this->assertSame([3, 2, 1], [count($new['address']), count($reused['address']), count($cap['address'])], 'queries on `addresses`: new, reused, at the cap');
        $this->assertSame(60, $new['total'], 'logged-in typed checkout, NEW address: 60 total (measured; the guest pin, 63, is a different scenario and stays in CheckoutHardeningTest)');
        $this->assertSame(57, $reused['total'], 'REUSED address: 57 total (select + touch)');
        $this->assertSame(56, $cap['total'], 'AT THE CAP: 56 total (the select only, no write)');
    }
}
