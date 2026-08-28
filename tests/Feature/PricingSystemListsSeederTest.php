<?php

namespace Tests\Feature;

use EasyCo\Pricing\Contracts\PriceListRepository;
use EasyCo\Pricing\Enums\PriceListMode;
use EasyCo\Pricing\Enums\PriceListStatus;
use EasyCo\Pricing\Seeders\PricingSystemListsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers PricingSystemListsSeeder (pricing-persistence-domain-design.md
 * §4.5/§8 item 3): both reserved system PriceLists get created with the
 * documented priority/mode/status, re-running the seeder is a no-op
 * (idempotency is the whole point — this is expected to run on every
 * deploy, not once ever), and DatabaseSeeder actually wires it in
 * rather than merely having code that looks like it does.
 */
class PricingSystemListsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_the_regular_prices_system_list(): void
    {
        $this->seed(PricingSystemListsSeeder::class);

        $priceList = app(PriceListRepository::class)->findSystemListByName('Regular Prices');

        self::assertNotNull($priceList);
        self::assertSame(0, $priceList->priority());
        self::assertSame(PriceListMode::FIXED_ITEMS, $priceList->mode());
        self::assertTrue($priceList->isSystem());
        self::assertSame(PriceListStatus::ACTIVE, $priceList->status());
    }

    public function test_seeding_creates_the_manual_sale_system_list(): void
    {
        $this->seed(PricingSystemListsSeeder::class);

        $priceList = app(PriceListRepository::class)->findSystemListByName('Manual Sale');

        self::assertNotNull($priceList);
        self::assertSame(1000, $priceList->priority());
        self::assertSame(PriceListMode::FIXED_ITEMS, $priceList->mode());
        self::assertTrue($priceList->isSystem());
        self::assertSame(PriceListStatus::ACTIVE, $priceList->status());
    }

    public function test_running_the_seeder_twice_does_not_create_duplicates(): void
    {
        $this->seed(PricingSystemListsSeeder::class);
        $this->seed(PricingSystemListsSeeder::class);

        $count = DB::table('pricing_price_lists')->where('is_system', true)->count();

        self::assertSame(2, $count);
    }

    public function test_database_seeder_actually_calls_the_pricing_system_lists_seeder(): void
    {
        $this->seed();

        $count = DB::table('pricing_price_lists')->where('is_system', true)->count();

        self::assertSame(2, $count);
    }
}
