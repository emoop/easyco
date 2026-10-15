<?php

namespace Tests\Feature;

use App\Enums\OrderBlockType;
use App\Filament\Resources\OrderResource;
use App\Models\OrderBlockModel;
use App\Services\OrderBlockService;
use App\Services\OrderMatchReader;
use EasyCo\Order\Persistence\Eloquent\OrderModel;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;

/**
 * Cash-on-delivery protection, stage C2-B, the «Съвпадения» card (cod-protection-design.md section 5): earlier and later orders sharing the phone,
 * the email or the delivery place (strong) or only the name (weak, greyed, "possible"); at most the 10 most recent per key type; a summary with the
 * number cancelled or refunded; the red line only for an identifier that is SHARED and actively blocked; ORDER_VIEW; the IP is not a key; and a
 * bounded, pinned number of queries.
 */
class OrderMatchesCardTest extends TestCase
{
    use BuildsShippingZones;
    use PlacesRegionOrders;
    use RefreshDatabase;

    private static int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // PH-1: a phone typed by hand is read with the STORE's country (a configured shop always has one).
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set(\App\Settings\StoreCountry::KEY, 'BG');

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = false;
        $this->setUpRegionOrders();
        // Dozens of orders in one test would meet the checkout rate limit (429): the limit is not what is under test here.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        app()->setLocale('en');
        self::$n = 0;
    }

    /**
     * An order of a person who shares NOTHING with the others unless $same says so: a phone, an email, a name and a street address of their own.
     *
     * @param  array<string, mixed>  $same  phone / email / first / last / street override what the person would otherwise have
     */
    private function order(array $same = [], string $status = 'placed', ?string $placedAt = null): string
    {
        $n = ++self::$n;
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5']); // the SAME address for every order: it must not be a matching key

        $id = (string) $this->checkout(
            $this->streetFields('BG', ['city' => 'Sofia', 'address_line_1' => $same['street'] ?? "Unique Street {$n}"]),
            [
                'phone' => $same['phone'] ?? '0888'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                'email' => $same['email'] ?? "person{$n}@example.com",
                'recipient_first_name' => $same['first'] ?? "First{$n}",
                'recipient_last_name' => $same['last'] ?? "Last{$n}",
            ],
        )->assertStatus(201)->json('order.id');

        $changes = array_filter(['status' => $status === 'placed' ? null : $status, 'placed_at' => $placedAt]);

        if ($changes !== []) {
            DB::table('orders')->where('id', $id)->update($changes);
        }

        return $id;
    }

    private function card(string $order): string
    {
        $this->app->forgetScopedInstances();
        $html = $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<h2[^>]*>\s*'.preg_quote(__('blocks.matches.section'), '/').'\s*<\/h2>/', $html, $heading, PREG_OFFSET_CAPTURE), 'the card is on the page');

        return substr($html, $heading[0][1], 9000);
    }

    // ---- who and what ------------------------------------------------------------------------------------------------------------------

    public function test_an_order_that_shares_nothing_says_so(): void
    {
        $this->order();
        $mine = $this->order();
        $this->actingAsStaff('Administrator');

        $this->assertStringContainsString(__('blocks.matches.none'), $this->card($mine));
    }

    public function test_the_card_is_open_to_anyone_with_order_view(): void
    {
        $other = $this->order(['phone' => '0888 111 222']);
        $mine = $this->order(['phone' => '+359 888 111 222']);
        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        $card = $this->card($mine);

        $this->assertStringContainsString('Order #'.$other, $card);
    }

    public function test_a_shared_phone_in_another_notation_matches_and_names_the_key(): void
    {
        $other = $this->order(['phone' => '0888 111 222', 'first' => 'Ivan', 'last' => 'Petrov']);
        $mine = $this->order(['phone' => '00359888111222']);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $this->assertStringContainsString('href="'.OrderResource::getUrl('view', ['record' => $other]).'"', $card, 'the order number is a link');
        $this->assertStringContainsString('Order #'.$other, $card);
        $this->assertStringContainsString('Ivan Petrov', $card);
        $this->assertStringContainsString('Placed', $card, 'the status');
        $this->assertMatchesRegularExpression('/Order #'.$other.'.{0,600}?phone/s', $card);
        $this->assertStringNotContainsString('possible match', $card);
        $this->assertStringContainsString('Matches 1 other order (0 cancelled or refunded)', $card);
    }

    public function test_the_email_and_the_delivery_place_are_strong_keys_too_and_several_can_match_at_once(): void
    {
        $byEmail = $this->order(['email' => 'SHARED@example.com']);
        $byPlace = $this->order(['street' => 'Vitosha Blvd. 1']);
        $both = $this->order(['email' => 'shared@example.com', 'street' => 'vitosha  blvd 1']);
        $this->actingAsStaff('Administrator');

        $card = $this->card($both);

        $this->assertMatchesRegularExpression('/Order #'.$byEmail.'.{0,700}?email/s', $card);
        $this->assertMatchesRegularExpression('/Order #'.$byPlace.'.{0,700}?address \/ office/s', $card);
        $this->assertStringContainsString('Matches 2 other orders', $card);
        $this->assertStringNotContainsString('Order #'.$both.'<', $card, 'this order is not its own match');
    }

    public function test_a_later_order_matches_an_earlier_one_and_the_other_way_round(): void
    {
        $first = $this->order(['phone' => '0888 777 666']);
        $later = $this->order(['phone' => '0888 777 666']);
        $this->actingAsStaff('Administrator');

        $this->assertStringContainsString('Order #'.$later, $this->card($first));
        $this->assertStringContainsString('Order #'.$first, $this->card($later));
    }

    // ---- weak: the name -----------------------------------------------------------------------------------------------------------------

    public function test_a_name_only_match_is_greyed_labelled_possible_and_not_in_the_summary(): void
    {
        $same = $this->order(['first' => 'Maria', 'last' => 'Ivanova']);
        $mine = $this->order(['first' => 'MARIA', 'last' => 'ivanova']);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $this->assertStringContainsString('Order #'.$same, $card);
        $this->assertStringContainsString('name — possible match', $card);
        $this->assertStringContainsString('opacity: 0.55', $card, 'greyed');
        $this->assertStringContainsString('1 more with the same name only', $card);
        $this->assertStringNotContainsString('Matches 1 other order', $card, 'not counted as a match');
        $this->assertStringNotContainsString('Matches 0 other', $card);
    }

    public function test_a_strong_match_is_not_greyed_even_when_the_name_matches_too(): void
    {
        $other = $this->order(['phone' => '0888 333 444', 'first' => 'Same', 'last' => 'Person']);
        $mine = $this->order(['phone' => '0888 333 444', 'first' => 'Same', 'last' => 'Person']);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);
        $row = substr($card, strpos($card, 'Order #'.$other) - 160, 700);

        $this->assertStringNotContainsString('opacity: 0.55', $row);
        $this->assertStringContainsString('Matches 1 other order', $card);
    }

    // ---- counts and the limit -----------------------------------------------------------------------------------------------------------------

    public function test_the_summary_counts_the_cancelled_and_refunded_among_the_strong_matches(): void
    {
        $this->order(['phone' => '0888 555 000']);
        $this->order(['phone' => '0888 555 000'], 'cancelled');
        $this->order(['phone' => '0888 555 000'], 'cancelled');
        $this->order(['phone' => '0888 555 000'], 'refunded');
        $this->order(['phone' => '0888 555 000'], 'delivered');
        $mine = $this->order(['phone' => '0888 555 000']);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $this->assertStringContainsString('Matches 5 other orders (3 cancelled or refunded)', $card);
        $this->assertSame(2, substr_count($card, 'Cancelled') , 'the cancelled ones stand out by their status');
        $this->assertSame(1, substr_count($card, 'Refunded'));
    }

    public function test_at_most_the_ten_most_recent_per_key_type_are_listed(): void
    {
        $byPhone = [];
        foreach (range(1, 12) as $i) {
            $byPhone[$i] = $this->order(['phone' => '0888 999 000'], 'placed', now()->subDays(40 - $i)->format('Y-m-d H:i:s'));
        }
        $byEmail = [];
        foreach (range(1, 11) as $i) {
            $byEmail[$i] = $this->order(['email' => 'many@example.com'], 'placed', now()->subDays(40 - $i)->format('Y-m-d H:i:s'));
        }
        $mine = $this->order(['phone' => '0888 999 000', 'email' => 'many@example.com']);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $listed = fn (array $ids): int => count(array_filter($ids, fn (string $id): bool => str_contains($card, 'Order #'.$id.'<')));
        $this->assertSame(10, $listed($byPhone), 'the 10 most recent of the 12 that share the phone');
        $this->assertStringNotContainsString('Order #'.$byPhone[1].'<', $card, 'the oldest is left out');
        $this->assertStringNotContainsString('Order #'.$byPhone[2].'<', $card);
        $this->assertStringContainsString('Order #'.$byPhone[12].'<', $card, 'the newest is in');
        $this->assertSame(10, $listed($byEmail), 'its own limit of 10 for the email');
        $this->assertStringContainsString('Showing the 10 most recent', $card);
    }

    // ---- the red line --------------------------------------------------------------------------------------------------------------------------

    public function test_a_shared_phone_that_is_actively_blocked_raises_the_red_line(): void
    {
        $this->order(['phone' => '0888 444 111']);
        $mine = $this->order(['phone' => '0888 444 111']);
        app(OrderBlockService::class)->block(OrderBlockType::PHONE, '0888 444 111', 30);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $this->assertStringContainsString('The phone is blocked until '.now()->addDays(30)->format('M j, Y'), $card);
        $this->assertStringContainsString('color: rgb(185, 28, 28)', $card);

        $block = OrderBlockModel::query()->sole();
        $block->update(['expires_at' => null]);
        $this->assertStringContainsString('The phone is blocked permanently', $this->card($mine));
    }

    public function test_a_shared_email_that_is_blocked_raises_its_own_line(): void
    {
        $this->order(['email' => 'repeat@example.com']);
        $mine = $this->order(['email' => 'repeat@example.com']);
        app(OrderBlockService::class)->block(OrderBlockType::EMAIL, 'repeat@example.com', 10);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $this->assertStringContainsString('The email is blocked until', $card);
        $this->assertStringNotContainsString('The phone is blocked', $card);
    }

    public function test_a_name_only_match_never_raises_the_red_line_even_when_the_phone_is_blocked(): void
    {
        // The phone of THIS order is blocked, but nobody else shares it: the only match is the name (weak).
        $this->order(['first' => 'Same', 'last' => 'Name']);
        $mine = $this->order(['first' => 'Same', 'last' => 'Name', 'phone' => '0888 202 202']);
        app(OrderBlockService::class)->block(OrderBlockType::PHONE, '0888 202 202', 30);
        app(OrderBlockService::class)->block(OrderBlockType::EMAIL, "person{$this->lastN()}@example.com", 30);
        $this->actingAsStaff('Administrator');

        $card = $this->card($mine);

        $this->assertStringContainsString('name — possible match', $card);
        $this->assertStringNotContainsString('is blocked', $card, 'a possibility is never a red line');
        $this->assertStringNotContainsString('color: rgb(185, 28, 28)', $card);
    }

    public function test_an_ended_block_raises_nothing(): void
    {
        $this->order(['phone' => '0888 303 303']);
        $mine = $this->order(['phone' => '0888 303 303']);
        $expired = OrderBlockModel::query()->create(['type' => 'phone', 'value_key' => '888303303', 'value_display' => '0888 303 303', 'expires_at' => now()->subDay()]);
        $this->actingAsStaff('Administrator');
        $this->assertStringNotContainsString('is blocked', $this->card($mine));

        $expired->update(['expires_at' => null, 'revoked_at' => now()]);
        $this->assertStringNotContainsString('is blocked', $this->card($mine));
    }

    // ---- the IP is not a key ----------------------------------------------------------------------------------------------------------------------

    public function test_orders_from_the_same_ip_do_not_match(): void
    {
        $this->order(); // the same REMOTE_ADDR for every order of this class
        $mine = $this->order();
        $this->actingAsStaff('Administrator');

        $this->assertSame(1, DB::table('order_attributions')->where('ip', '203.0.113.5')->count() - 1, 'two orders really share the address');
        $this->assertStringContainsString(__('blocks.matches.none'), $this->card($mine));
        $this->assertStringNotContainsString('203.0.113.5', $this->card($mine));
    }

    // ---- an order with no keys / a placed-before-the-stage order -------------------------------------------------------------------------------

    public function test_an_order_without_keys_matches_nothing(): void
    {
        $mine = $this->order();
        DB::table('orders')->where('id', $mine)->update(['phone_key' => null, 'email_key' => null, 'name_key' => null, 'place_key' => null]);
        $this->actingAsStaff('Administrator');

        $this->assertStringContainsString(__('blocks.matches.none'), $this->card($mine));
    }

    // ---- the cost --------------------------------------------------------------------------------------------------------------------------------

    private function matchQueries(string $order): int
    {
        $this->app->forgetScopedInstances();
        $n = 0;
        DB::listen(function ($query) use (&$n): void {
            $n += preg_match('/from `orders` where `(phone|email|place|name)_key` = /', $query->sql) === 1 ? 1 : 0;
        });
        app(OrderMatchReader::class)->forOrder(OrderModel::findOrFail($order));

        return $n;
    }

    public function test_the_card_costs_at_most_one_query_per_key_type_whatever_the_number_of_matches(): void
    {
        $few = $this->order(['phone' => '0888 606 606', 'email' => 'q@example.com', 'street' => 'Q Street 1', 'first' => 'Q', 'last' => 'Q']);
        $this->order(['phone' => '0888 606 606']);
        $costFew = $this->matchQueries($few);

        foreach (range(1, 15) as $i) {
            $this->order(['phone' => '0888 606 606', 'email' => 'q@example.com', 'street' => 'Q Street 1', 'first' => 'Q', 'last' => 'Q']);
        }
        $many = $this->order(['phone' => '0888 606 606', 'email' => 'q@example.com', 'street' => 'Q Street 1', 'first' => 'Q', 'last' => 'Q']);
        $costMany = $this->matchQueries($many);

        fwrite(STDERR, "\n[query-count] matches card: {$costFew} (2 orders) / {$costMany} (17 orders) key queries\n");
        $this->assertSame(4, $costMany, 'the phone, the email, the place and the name: one each, newest 10');
        $this->assertLessThanOrEqual(4, $costFew);
    }

    public function test_the_order_view_adds_at_most_the_four_key_reads_and_one_block_lookup(): void
    {
        $this->order(['phone' => '0888 707 707']);
        $a = $this->order(['phone' => '0888 707 707']);
        $this->actingAsStaff('Administrator');
        $cost = function (string $id): array {
            $this->app->forgetScopedInstances();
            $keyReads = 0;
            $blockReads = 0;
            DB::listen(function ($query) use (&$keyReads, &$blockReads): void {
                $keyReads += preg_match('/from `orders` where `(phone|email|place|name)_key` = /', $query->sql);
                $blockReads += str_contains($query->sql, 'from `order_blocks`') ? 1 : 0;
            });
            $this->get(OrderResource::getUrl('view', ['record' => $id]))->assertOk();

            return [$keyReads, $blockReads];
        };

        [$keyReads, $blockReads] = $cost($a);

        $this->assertSame(4, $keyReads);
        $this->assertSame(1, $blockReads, 'the buttons, the hints and the red line share ONE lookup');
    }

    private function lastN(): int
    {
        return self::$n;
    }
}
