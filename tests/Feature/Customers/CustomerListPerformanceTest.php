<?php

namespace Tests\Feature\Customers;

use App\Customers\CustomerListQuery;
use App\Filament\Pages\Customers;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md §7): the list at 20 000 orders: a constant number of queries per page, the aggregate on `ord_email_key_index`, the EXPLAIN in the output (it goes into the report).
 */
class CustomerListPerformanceTest extends TestCase
{
    use BuildsShippingZones;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    private const ORDERS = 20_000;

    private const PEOPLE = 6_000;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        $this->seedTwentyThousandOrders();
        DB::listen(function (): void {
            $this->queryCount++;
        });
    }

    private function seedTwentyThousandOrders(): void
    {
        $rows = [];

        for ($i = 0; $i < self::ORDERS; $i++) {
            $person = $i % self::PEOPLE;
            $rows[] = [
                'email' => "person{$person}@example.com", 'first' => 'Name'.$person, 'last' => 'Surname'.($person % 97),
                'phone' => '+3598'.str_pad((string) (10_000_000 + $person), 8, '0', STR_PAD_LEFT),
                'status' => ['placed', 'confirmed', 'shipped', 'delivered', 'cancelled', 'refunded'][$i % 6], 'total' => 500 + ($i % 50) * 10,
                'placed_at' => now()->subMinutes(self::ORDERS - $i)->toDateTimeString(),
            ];
        }

        $this->insertOrders($rows);

        // a third of the people have an account; 50 accounts have no order
        $accounts = [];

        for ($p = 0; $p < self::PEOPLE; $p += 3) {
            $accounts[] = ['email' => "person{$p}@example.com", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()];
        }

        for ($p = 0; $p < 50; $p++) {
            $accounts[] = ['email' => "lonely{$p}@example.com", 'password' => 'x', 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($accounts, 500) as $chunk) {
            DB::table('accounts')->insert($chunk);
        }
    }

    private int $queryCount = 0;

    /** the number of queries of one Livewire request (ONE listener for the whole test: a listener cannot be removed, so counting is reset instead) */
    private function queriesOf(callable $render): int
    {
        $this->queryCount = 0;
        $render();

        return $this->queryCount;
    }

    public function test_the_list_at_20000_orders_has_a_constant_number_of_queries_and_uses_the_email_key_index(): void
    {
        $this->assertSame(self::ORDERS, DB::table('orders')->count());
        $this->actingAsStaff('Administrator');

        $component = Livewire::test(Customers::class)->loadTable();
        $perPage = $component->instance()->getTableRecords();
        $this->assertSame(25, $perPage->count());
        $this->assertSame(self::PEOPLE + 50, $perPage->total(), 'one row per person: 6 000 people + 50 accounts with no order');

        $page1 = $this->queriesOf(fn () => $component->call('$refresh'));
        $page2 = $this->queriesOf(fn () => $component->call('gotoPage', 2)->call('$refresh'));
        $search = $this->queriesOf(fn () => $component->set('tableSearch', 'person77@')->call('$refresh'));
        $tab = $this->queriesOf(function () use ($component): void {
            $component->set('tableSearch', '')->set('activeTab', 'accounts')->call('$refresh');
        });

        fwrite(STDERR, "\n[query-count] customers list, 20 000 orders: page 1 = {$page1} queries, page 2 = {$page2}, search = {$search}, tab = {$tab}\n");
        // ONE render = 3 queries: the count, the page, the badges of THIS page. A request that changes the page, the search or the tab renders the table again (Filament), so it is a small multiple of 3:
        // never a number that depends on the rows (25 rows per page, 6 050 people, 20 000 orders)
        $this->assertSame(3, $page1, 'count + page + badges');
        $this->assertSame(0, $page2 % 3);
        $this->assertLessThanOrEqual(6, $page2);
        $this->assertLessThanOrEqual(6, $search);
        $this->assertLessThanOrEqual(9, $tab);

        // EXPLAIN of the page's statement (the deepest one: the sorted first page)
        $query = CustomerListQuery::query()->orderByDesc('last_order_at')->limit(25);
        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
        $lines = array_map(fn (object $r): string => sprintf('id=%s %s table=%s type=%s key=%s rows=%s extra=%s', $r->id, $r->select_type, $r->table, $r->type, $r->key ?? '-', $r->rows ?? '-', $r->Extra ?? '-'), $plan);
        fwrite(STDERR, "[explain] customers list (first page, newest first):\n  ".implode("\n  ", $lines)."\n");

        $aggregate = collect($plan)->first(fn (object $r): bool => $r->table === 'orders' && $r->select_type === 'DERIVED');
        $this->assertSame('ord_email_key_index', $aggregate->key, 'the aggregate walks the email_key index, not the table');

        $started = microtime(true);
        $component->call('$refresh');
        $ms = (int) ((microtime(true) - $started) * 1000);
        fwrite(STDERR, "[timing] customers list render at 20 000 orders: {$ms} ms\n");
        $this->assertLessThan(5000, $ms);
    }
}
