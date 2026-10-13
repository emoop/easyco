<?php

namespace Tests\Feature;

use EasyCo\Shipping\Enums\ShippingDeliveryType as Type;
use EasyCo\Shipping\Enums\ShippingDestinationScope as Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsAllKindsQuote;
use Tests\TestCase;

/**
 * Shipping stage 5g-2b (design 9.3.4, target "an all request costs the single-kind quote plus at most 2 queries"): the queries of a
 * COLD quote, measured the way ShippingQuoteEndpointTest does (one cold measurement per test, the container's scoped instances
 * forgotten first). The numbers are PINNED: a change to the pipeline has to change them on purpose.
 *
 * The same fixture throughout: one zone, four methods (Speedy any, an address-only method, an office and a locker method), a
 * cart of goods 30.00 spread over 1 or 5 lines.
 */
class ShippingAllKindsQueryCountTest extends TestCase
{
    use BuildsAllKindsQuote;
    use RefreshDatabase;

    /** The single-kind (street address) quote of this fixture, cold: every query of the request, with 1 line and with 5. */
    private const SINGLE_1 = 19;

    private const SINGLE_5 = 63;

    /** The `all` quote of this fixture, cold, with 1 line and with 5: never more than the single-kind quote + 2 (here: +1, the hint's settings read). */
    private const ALL_1 = 20;

    private const ALL_5 = 64;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost/');
        $this->akMethod('Speedy any', Scope::ANY, null, 600, 6000, [Type::OFFICE, Type::LOCKER], 'Speedy', 0);
        $this->akMethod('Shop address', Scope::ADDRESS, null, 700, null, null, null, 1);
        $this->akMethod('Econt office', Scope::PICKUP, Type::OFFICE, 400, 9000, null, 'Econt', 2);
        $this->akMethod('Econt locker', Scope::PICKUP, Type::LOCKER, 350, 9000, null, 'Econt', 3);
    }

    private function lines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->akCart((string) number_format(30 / $count, 2, '.', ''), 1);
        }
    }

    /** @return array<string, mixed> */
    private function measure(array $body): array
    {
        $this->app->forgetScopedInstances();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->postJson('/api/shipping/quote', $body)->assertOk();
        DB::flushQueryLog();

        return ['queries' => $queries];
    }

    /** @return array<string, mixed> */
    private function single(): array
    {
        return ['delivery_type' => 'street_address', 'country' => 'BG', 'settlement' => 'Sofia'];
    }

    public function test_the_single_kind_quote_with_one_line(): void
    {
        $this->lines(1);
        $n = $this->measure($this->single())['queries'];

        fwrite(STDERR, "
[query-count] POST /api/shipping/quote single street address, 1 line: {$n} queries
");

        $this->assertSame(self::SINGLE_1, $n);
    }

    public function test_the_single_kind_quote_with_five_lines(): void
    {
        $this->lines(5);
        $n = $this->measure($this->single())['queries'];

        fwrite(STDERR, "
[query-count] POST /api/shipping/quote single street address, 5 lines: {$n} queries
");

        $this->assertSame(self::SINGLE_5, $n);
    }

    public function test_the_all_quote_with_one_line(): void
    {
        $this->lines(1);
        $n = $this->measure(['delivery_type' => 'all', 'country' => 'BG', 'settlement' => 'Sofia'])['queries'];

        fwrite(STDERR, "
[query-count] POST /api/shipping/quote all kinds, 1 line: {$n} queries (single: ".self::SINGLE_1.")
");

        $this->assertSame(self::ALL_1, $n);
        $this->assertLessThanOrEqual(self::SINGLE_1 + 2, $n, 'an all quote costs at most two queries more than a single-kind quote');
    }

    /**
     * The cost of a quote grows with the cart's lines because CartPricing prices each line (that is how the single-kind quote has always
     * been: reported, not changed here). What 5g-2b promises is that an all quote adds at most two queries to the single-kind quote of the
     * SAME cart, whatever the lines: ONE cart pricing, not one per kind.
     */
    public function test_the_all_quote_with_five_lines_costs_at_most_two_more_than_the_single_quote_of_the_same_cart(): void
    {
        $this->lines(5);
        $n = $this->measure(['delivery_type' => 'all', 'country' => 'BG', 'settlement' => 'Sofia'])['queries'];

        fwrite(STDERR, "
[query-count] POST /api/shipping/quote all kinds, 5 lines: {$n} queries (single: ".self::SINGLE_5.")
");

        $this->assertSame(self::ALL_5, $n);
        $this->assertLessThanOrEqual(self::SINGLE_5 + 2, $n);
    }

    public function test_the_all_quote_preview_costs_no_more_than_the_final_one(): void
    {
        $this->lines(1);
        $n = $this->measure(['delivery_type' => 'all', 'country' => 'BG'])['queries'];

        fwrite(STDERR, "
[query-count] POST /api/shipping/quote all kinds preview, 1 line: {$n} queries
");

        $this->assertLessThanOrEqual(self::ALL_1, $n);
    }
}
