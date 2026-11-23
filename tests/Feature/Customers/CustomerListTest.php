<?php

namespace Tests\Feature\Customers;

use App\Customers\CustomerListQuery;
use App\Customers\CustomerRow;
use App\Filament\Pages\Customers;
use App\Privacy\PrivacyHash;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md D5, §7): the «Клиенти» list: one row per person, the permission, the search, the tabs, the badge and the paging.
 */
class CustomerListTest extends TestCase
{
    use BuildsShippingZones;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
    }

    /** @return list<CustomerRow> */
    private function rows(string $tab = 'all', ?string $search = null): array
    {
        $query = CustomerListQuery::query($tab);
        $search === null || CustomerListQuery::search($query, $search);

        return $query->orderByDesc('last_order_at')->orderBy('row_key')->get()->all();
    }

    // ---- the permission ------------------------------------------------------------------------------------------------------------

    public function test_a_user_without_customer_view_gets_403_on_the_list_and_on_the_page(): void
    {
        $id = $this->insertOrder(['email' => 'a@example.com']);
        $this->actingAsCustomRole([Permission::ORDER_VIEW, Permission::ORDER_MANAGE, Permission::PRIVACY_MANAGE]);

        $this->get('/admin/customers')->assertForbidden();
        $this->get('/admin/customers/o-'.$id)->assertForbidden();
    }

    public function test_the_administrator_role_has_customer_view_and_manager_has_not(): void
    {
        $this->actingAsStaff('Administrator');
        $this->get('/admin/customers')->assertOk();

        $this->actingAsStaff('Manager');
        $this->get('/admin/customers')->assertForbidden();
    }

    // ---- one row per person ------------------------------------------------------------------------------------------------------

    public function test_one_row_per_email_across_guest_and_account_orders_case_insensitive(): void
    {
        $account = $this->insertAccount('me@example.com');
        $this->insertOrder(['email' => 'Me@Example.com', 'first' => 'Old', 'total' => 1000, 'account_id' => $account]);
        $this->insertOrder(['email' => 'me@example.com', 'first' => 'New', 'total' => 2500]);
        $this->insertOrder(['email' => 'other@example.com']);

        $rows = $this->rows();

        $this->assertCount(2, $rows);
        $me = collect($rows)->first(fn (CustomerRow $r): bool => $r->email_key === 'me@example.com');
        $this->assertSame(2, $me->orders_count);
        $this->assertSame(3500, $me->spent_minor);
        $this->assertSame('New Ivanov', $me->name, 'the name of the LATEST order');
        $this->assertTrue($me->has_account);
        $this->assertFalse(collect($rows)->first(fn (CustomerRow $r): bool => $r->email_key === 'other@example.com')->has_account);
    }

    public function test_an_account_without_orders_appears_and_a_deleted_one_does_not(): void
    {
        $id = $this->insertAccount('lonely@example.com');
        $this->insertAccount('gone@example.com', deleted: true);
        $this->insertOrder(['email' => 'buyer@example.com']);

        $rows = collect($this->rows());

        $lonely = $rows->first(fn (CustomerRow $r): bool => $r->email_key === 'lonely@example.com');
        $this->assertNotNull($lonely);
        $this->assertSame('a-'.$id, $lonely->row_key);
        $this->assertSame(0, $lonely->orders_count);
        $this->assertTrue($lonely->has_account);
        $this->assertNull($rows->first(fn (CustomerRow $r): bool => $r->email_key === 'gone@example.com'));
        $this->assertCount(2, $rows);
    }

    /** ★ an anonymised order (NULL email_key) is NOT a person of the list */
    public function test_rows_with_a_null_email_key_are_absent(): void
    {
        $this->insertOrder(['email' => 'real@example.com']);
        $this->insertOrder(['email' => 'anonymised@invalid', 'email_key' => null, 'status' => 'delivered', 'anonymised_at' => now()]);
        $this->insertOrder(['email' => 'anonymised2@invalid', 'email_key' => null, 'status' => 'cancelled', 'anonymised_at' => now()]);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('real@example.com', $rows[0]->email_key);
    }

    public function test_the_total_spent_leaves_out_cancelled_and_refunded_orders(): void
    {
        foreach ([['placed', 100], ['confirmed', 200], ['shipped', 400], ['delivered', 800], ['cancelled', 1600], ['refunded', 3200]] as [$status, $total]) {
            $this->insertOrder(['email' => 'spender@example.com', 'status' => $status, 'total' => $total]);
        }

        $row = $this->rows()[0];

        $this->assertSame(6, $row->orders_count, 'every order is counted');
        $this->assertSame(1500, $row->spent_minor, '100 + 200 + 400 + 800: not the cancelled and not the refunded');
    }

    // ---- the search --------------------------------------------------------------------------------------------------------------

    public function test_the_search_finds_a_person_once_by_name_email_and_phone(): void
    {
        $this->insertOrder(['email' => 'maria@example.com', 'first' => 'Мария', 'last' => 'Петрова', 'phone' => '+359888123456']);
        $this->insertOrder(['email' => 'maria@example.com', 'first' => 'Мария', 'last' => 'Петрова', 'phone' => '+359888123456']);
        $this->insertOrder(['email' => 'ivan@example.com', 'first' => 'Иван', 'last' => 'Георгиев', 'phone' => '+359877000111']);

        $keys = fn (string $term): array => array_map(fn (CustomerRow $r): string => $r->email_key, $this->rows('all', $term));

        $this->assertSame(['maria@example.com'], $keys('мария'), 'name, one row for two orders');
        $this->assertSame(['maria@example.com'], $keys('Петрова мария'), 'every word, any order');
        $this->assertSame(['ivan@example.com'], $keys('ivan@exam'), 'email, a part of it');
        $this->assertSame(['maria@example.com'], $keys('+359 888 123 456'), 'phone, as typed with spaces');
        $this->assertSame(['maria@example.com'], $keys('0888 123 456'), 'phone, the national form');
        $this->assertSame(['maria@example.com'], $keys('888123'), 'phone, a part of the digits');
        $this->assertSame([], $keys('мария георгиев'), 'every word must be in ONE name');
        $this->assertSame([], $keys('nobody'));
    }

    public function test_the_search_escapes_percent_underscore_and_backslash(): void
    {
        $this->insertOrder(['email' => 'a_b@example.com', 'first' => 'Under_score']);
        $this->insertOrder(['email' => 'axb@example.com', 'first' => 'Underxscore']);
        $this->insertOrder(['email' => 'plain@example.com', 'first' => 'Plain']);

        $keys = fn (string $term): array => array_map(fn (CustomerRow $r): string => $r->email_key, $this->rows('all', $term));

        $this->assertSame(['a_b@example.com'], $keys('a_b'), 'the underscore is a character, not a wildcard');
        $this->assertSame(['a_b@example.com'], $keys('under_score'));
        $this->assertSame([], $keys('%'), 'a percent sign is not «everything»');
        $this->assertSame([], $keys('a%b'));
        $this->assertSame([], $keys('\\'));
        $this->assertSame([], $keys('x\\'));
    }

    public function test_the_tabs_split_accounts_and_guests(): void
    {
        $this->insertAccount('acc@example.com');
        $this->insertAccount('buyer-acc@example.com');
        $this->insertOrder(['email' => 'buyer-acc@example.com']);
        $this->insertOrder(['email' => 'guest@example.com']);

        $emails = fn (string $tab): array => array_map(fn (CustomerRow $r): string => $r->email_key, $this->rows($tab));

        $this->assertEqualsCanonicalizing(['acc@example.com', 'buyer-acc@example.com', 'guest@example.com'], $emails('all'));
        $this->assertEqualsCanonicalizing(['acc@example.com', 'buyer-acc@example.com'], $emails('accounts'));
        $this->assertSame(['guest@example.com'], $emails('guests'));
    }

    // ---- the page of the table ----------------------------------------------------------------------------------------------------

    public function test_the_table_pages_by_25_newest_first_and_links_to_the_customer_page_without_the_email(): void
    {
        $this->actingAsStaff('Administrator');
        $ids = $this->insertOrders(array_map(fn (int $i): array => ['email' => "p{$i}@example.com", 'placed_at' => now()->subHours(100 - $i)->toDateTimeString()], range(1, 30)));

        $table = Livewire::test(Customers::class)->loadTable();

        $page = $table->instance()->getTableRecords();
        $this->assertSame(25, $page->count(), 'one page');
        $this->assertSame(30, $page->total());
        $this->assertSame('o-'.end($ids), $page->first()->row_key, 'the newest order first');

        $html = $table->html();
        $this->assertStringContainsString('/admin/customers/o-'.end($ids), $html);
        $this->assertStringNotContainsString('href="'.url('/admin/customers/p', []).'', $html);
        $this->assertDoesNotMatchRegularExpression('#/admin/customers/[^"\']*@#', $html, 'the URL key is never the email');
    }

    public function test_the_forgotten_badge_is_read_for_the_rows_of_the_page_in_one_query(): void
    {
        $this->actingAsStaff('Administrator');
        $this->insertOrder(['email' => 'gone@example.com']);
        $this->insertOrder(['email' => 'kept@example.com']);
        DB::table('privacy_requests')->insert([
            'kind' => 'erase', 'source' => 'admin', 'status' => 'done', 'email' => 'gone@example.com', 'email_hash' => (string) app(PrivacyHash::class)->email('gone@example.com'), 'reason' => 'asked',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // an export request of the other person must not count as «forgotten»
        DB::table('privacy_requests')->insert([
            'kind' => 'export', 'source' => 'admin', 'status' => 'done', 'email' => 'kept@example.com', 'email_hash' => (string) app(PrivacyHash::class)->email('kept@example.com'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            str_contains($query->sql, 'privacy_requests') && $queries[] = $query->sql;
        });

        $component = Livewire::test(Customers::class)->loadTable();
        $queries = [];
        $html = $component->call('$refresh')->html();

        $this->assertSame(1, substr_count($html, __('customers.badges.forgotten')), 'one «Забравен»');
        $this->assertCount(1, $queries, 'one request, one query for the whole page: '.json_encode($queries));
    }

    public function test_without_the_hash_key_in_production_the_list_still_renders_with_no_badge(): void
    {
        $this->actingAsStaff('Administrator');
        $this->insertOrder(['email' => 'x@example.com']);
        config(['privacy.hash_key' => null]);
        $this->app['env'] = 'production';

        try {
            $html = Livewire::test(Customers::class)->loadTable()->assertCountTableRecords(1)->html();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertStringNotContainsString(__('customers.badges.forgotten'), $html);
    }
}
