<?php

namespace Tests\Feature\Customers;

use App\Customers\CustomerPageReader;
use App\Filament\Pages\CustomerPage;
use App\Privacy\PrivacyHash;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\Extensibility\Hook;
use EasyCo\Extensibility\HookRegistry;
use EasyCo\Staff\Enums\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsShippingZones;
use Tests\Concerns\InsertsCustomerOrders;
use Tests\TestCase;

/**
 * ACC-4e-1 (acc-4-gdpr-design.md D5, §7, §10): the customer page: the key, the sections, the state strip, the requests card, the lists filter, the staff note and the query count.
 */
class CustomerPageTest extends TestCase
{
    use BuildsShippingZones;
    use InsertsCustomerOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        app(HookRegistry::class)->clear(CustomerPageReader::FILTER);
    }

    protected function tearDown(): void
    {
        app(HookRegistry::class)->clear(CustomerPageReader::FILTER);
        parent::tearDown();
    }

    private function hash(string $email): string
    {
        return (string) app(PrivacyHash::class)->email($email);
    }

    /** @param array<string, mixed> $o */
    private function request(string $email, array $o = []): int
    {
        return (int) DB::table('privacy_requests')->insertGetId($o + [
            'kind' => 'export', 'source' => 'admin', 'status' => 'awaiting_confirmation', 'email' => $email, 'email_hash' => $this->hash($email), 'staff_name' => 'Admin Tester',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- the key -----------------------------------------------------------------------------------------------------------------

    public function test_an_unknown_malformed_or_anonymised_key_is_a_404_and_a_valid_one_is_not(): void
    {
        $this->actingAsStaff('Administrator');
        $good = $this->insertOrder(['email' => 'good@example.com']);
        $anonymised = $this->insertOrder(['email' => 'gone@invalid', 'email_key' => null, 'status' => 'delivered', 'anonymised_at' => now()]);
        $accountOnly = $this->insertAccount('acc-only@example.com');
        $deleted = $this->insertAccount('deleted@example.com', deleted: true);

        $this->get('/admin/customers/o-'.$good)->assertOk();
        $this->get('/admin/customers/a-'.$accountOnly)->assertOk();

        foreach (['o-999999', 'a-999999', 'o-'.$anonymised, 'a-'.$deleted, 'x-1', 'o-0', 'o-abc', 'good@example.com', 'o-'.$good.'x', 'O-'.$good] as $key) {
            $this->get('/admin/customers/'.rawurlencode($key))->assertNotFound();
        }
    }

    public function test_the_url_of_the_page_never_carries_the_email(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'secret.person@example.com']);

        $url = CustomerPage::getUrl(['key' => 'o-'.$id]);

        $this->assertStringEndsWith('/admin/customers/o-'.$id, $url);
        $this->assertStringNotContainsString('secret', $url);
        $this->assertStringNotContainsString('@', $url);
    }

    // ---- the sections -----------------------------------------------------------------------------------------------------------

    public function test_every_section_renders_for_a_guest(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'guest@example.com', 'first' => 'Guesty', 'last' => 'McGuest', 'phone' => '+359888123456', 'consent' => true, 'consent_text' => 'Send me news']);

        $page = $this->get('/admin/customers/o-'.$id)->assertOk();

        foreach (['sections.data', 'sections.addresses', 'sections.orders', 'sections.consents', 'sections.lists', 'sections.note', 'sections.cod', 'sections.requests'] as $key) {
            $page->assertSee(__('customers.'.$key), false);
        }

        $page->assertSee('Guesty McGuest')->assertSee('guest@example.com')->assertSee('+359888123456')
            ->assertSee(__('customers.badges.guest'))
            ->assertSee(__('customers.addresses.none'))
            ->assertSee('Send me news')
            ->assertSee(__('customers.lists.empty'))
            ->assertSee('#'.$id);
    }

    public function test_every_section_renders_for_an_account_with_addresses_and_orders(): void
    {
        $this->actingAsStaff('Administrator');
        $accountId = $this->insertAccount('member@example.com');
        DB::table('addresses')->insert([
            'account_id' => $accountId, 'delivery_type' => 'street_address', 'recipient_first_name' => 'Mem', 'recipient_last_name' => 'Ber', 'phone' => '+359877000111',
            'country' => 'BG', 'city' => 'Plovdiv', 'postal_code' => '4000', 'address_line_1' => 'Saved street 9', 'default_marker' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = $this->insertOrder(['email' => 'member@example.com', 'first' => 'Mem', 'last' => 'Ber', 'account_id' => $accountId, 'status' => 'delivered', 'total' => 4500]);

        $this->get('/admin/customers/o-'.$id)->assertOk()
            ->assertSee(__('customers.badges.account'))
            ->assertSee('Saved street 9')
            ->assertSee('Plovdiv')
            ->assertSee(__('orders.status_options.delivered'))
            ->assertSee('45');

        $this->get('/admin/customers/a-'.$accountId)->assertOk()->assertSee('member@example.com');
    }

    public function test_the_orders_card_shows_the_latest_20_and_the_full_count_with_links_to_the_order_view(): void
    {
        $this->actingAsStaff('Administrator');
        $ids = $this->insertOrders(array_map(fn (int $i): array => ['email' => 'many@example.com'], range(1, 23)));

        $data = app(CustomerPageReader::class)->read(\App\Customers\CustomerIdentity::resolve('o-'.$ids[0]));

        $this->assertSame(23, $data['orders_count']);
        $this->assertCount(20, $data['orders']);
        $this->assertSame(end($ids), $data['orders'][0]['id'], 'the newest first');

        $this->get('/admin/customers/o-'.$ids[0])->assertOk()->assertSee('/admin/orders/'.end($ids), false);
    }

    // ---- the state strip and the requests card ----------------------------------------------------------------------------------

    public function test_the_strip_shows_a_confirmed_request_and_a_waiting_one(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);

        $page = $this->get('/admin/customers/o-'.$id)->assertOk();
        $page->assertDontSee(__('customers.strip.confirmed', ['kind' => '']), false);

        $this->request('me@example.com', ['kind' => 'erase', 'status' => 'confirmed', 'confirmed_at' => now()->subHour()]);
        $this->get('/admin/customers/o-'.$id)->assertOk()
            ->assertSee(__('customers.strip.confirmed', ['kind' => __('customers.requests.kind.erase')]), false)
            ->assertSee('Admin Tester');

        DB::table('privacy_requests')->delete();
        $this->request('me@example.com', ['kind' => 'export']);
        $this->get('/admin/customers/o-'.$id)->assertOk()
            ->assertSee(trim(explode('(', __('customers.strip.awaiting', ['kind' => 'x', 'date' => 'y']))[0]), false)
            ->assertSee(mb_strtolower(__('customers.requests.kind.export')), false);
    }

    public function test_the_requests_card_never_shows_a_clear_email_or_phone_of_a_request(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);
        // the row's own clear copies: not the person's page values; they must never be selected or printed
        $this->request('me@example.com', ['email' => 'CLEARCOPY@secret.test', 'phone' => '+359000CLEARPHONE', 'status' => 'confirmed', 'confirmed_at' => now()]);

        $html = (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent();

        $this->assertStringContainsString(__('customers.requests.status.confirmed'), $html, 'the request is listed');
        $this->assertStringNotContainsString('CLEARCOPY', $html);
        $this->assertStringNotContainsString('CLEARPHONE', $html);

        $selected = [];
        DB::listen(function ($query) use (&$selected): void {
            str_contains($query->sql, 'from `privacy_requests`') && $selected[] = $query->sql;
        });
        app(CustomerPageReader::class)->read(\App\Customers\CustomerIdentity::resolve('o-'.$id));
        $this->assertCount(1, $selected);
        $this->assertStringNotContainsString('`email`', $selected[0]);
        $this->assertStringNotContainsString('`phone`', $selected[0]);
        $this->assertStringNotContainsString('*', $selected[0]);
    }

    public function test_the_download_link_appears_only_for_a_done_and_unexpired_export_and_for_privacy_manage(): void
    {
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $this->request('me@example.com', ['status' => 'done', 'export_path' => 'privacy-exports/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa.zip', 'export_expires_at' => now()->addDay()]);
        $this->request('me@example.com', ['status' => 'done', 'export_path' => 'privacy-exports/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb.zip', 'export_expires_at' => now()->subMinute()]);
        $this->request('me@example.com', ['status' => 'processing']);

        $this->actingAsStaff('Administrator');
        $html = (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent();
        $this->assertStringContainsString('privacy/exports/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $html);
        $this->assertStringNotContainsString('bbbbbbbb-bbbb', $html, 'an expired export has no link');
        $this->assertSame(1, substr_count($html, 'privacy/exports/'));

        $this->actingAsCustomRole([Permission::CUSTOMER_VIEW]);
        $this->assertStringNotContainsString('privacy/exports/', (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent(), 'no privacy_manage: no link');
    }

    public function test_the_card_polls_only_while_a_request_is_processing(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);

        $this->assertStringNotContainsString('wire:poll', (string) $this->get('/admin/customers/o-'.$id)->getContent());

        $this->request('me@example.com', ['status' => 'processing']);
        $this->assertStringContainsString('wire:poll', (string) $this->get('/admin/customers/o-'.$id)->getContent());
    }

    public function test_without_the_hash_key_in_production_the_page_renders_with_one_clear_message_and_no_privacy_buttons(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);
        config(['privacy.hash_key' => null]);
        $this->app['env'] = 'production';

        try {
            $html = (string) $this->get('/admin/customers/o-'.$id)->assertOk()->getContent();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertStringContainsString('PRIVACY_HASH_KEY', $html);
        $this->assertStringContainsString(__('customers.sections.data'), $html, 'the rest of the page still renders');
        $this->assertStringNotContainsString(__('customers.actions.export'), $html);
        $this->assertStringNotContainsString(__('customers.actions.request_confirmation'), $html);
        $this->assertStringNotContainsString(__('customers.sections.requests'), $html);
    }

    // ---- the lists filter ----------------------------------------------------------------------------------------------------------

    public function test_the_lists_filter_feeds_the_card_and_a_throwing_filter_is_contained(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);

        Hook::filter(CustomerPageReader::FILTER, function (array $lists, string $emailKey): array {
            return [['title' => 'Newsletter', 'items' => ['Spring list for '.$emailKey]], ['broken' => true], 'nonsense'];
        });
        $this->get('/admin/customers/o-'.$id)->assertOk()->assertSee('Newsletter')->assertSee('Spring list for me@example.com');

        app(HookRegistry::class)->clear(CustomerPageReader::FILTER);
        Hook::filter(CustomerPageReader::FILTER, static function (): never {
            throw new \RuntimeException('LISTSECRET me@example.com');
        });

        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged): void {
            $logged[] = json_encode([$message->message, $message->context]);
        });

        $this->get('/admin/customers/o-'.$id)->assertOk()->assertSee(__('customers.lists.empty'));
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString('LISTSECRET', implode('', $logged), 'only the class is logged, never the message or the email');
        $this->assertStringNotContainsString('me@example.com', implode('', $logged));
    }

    // ---- the staff note ----------------------------------------------------------------------------------------------------------

    public function test_the_note_is_saved_with_the_staff_id_and_escaped_when_shown(): void
    {
        $staff = $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);

        Livewire::test(CustomerPage::class, ['key' => 'o-'.$id])
            ->set('noteBody', "Calls late\n<script>alert(1)</script>")
            ->call('saveNote');

        $note = DB::table('customer_notes')->where('email_key', 'me@example.com')->first();
        $this->assertSame("Calls late\n<script>alert(1)</script>", $note->body);
        $this->assertSame((string) $staff->getKey(), (string) $note->staff_id);

        $html = (string) $this->get('/admin/customers/o-'.$id)->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        // a second save replaces it (one note per person), an empty one deletes it
        Livewire::test(CustomerPage::class, ['key' => 'o-'.$id])->set('noteBody', 'Second')->call('saveNote');
        $this->assertSame(1, DB::table('customer_notes')->count());
        $this->assertSame('Second', DB::table('customer_notes')->value('body'));
        Livewire::test(CustomerPage::class, ['key' => 'o-'.$id])->set('noteBody', '  ')->call('saveNote');
        $this->assertSame(0, DB::table('customer_notes')->count());
    }

    public function test_a_note_over_2000_characters_or_with_control_characters_is_refused(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);

        foreach ([str_repeat('x', 2001), "bad\x07bell", "bidi \u{202E}override"] as $body) {
            Livewire::test(CustomerPage::class, ['key' => 'o-'.$id])->set('noteBody', $body)->call('saveNote');
        }

        $this->assertSame(0, DB::table('customer_notes')->count());

        Livewire::test(CustomerPage::class, ['key' => 'o-'.$id])->set('noteBody', str_repeat('я', 2000))->call('saveNote');
        $this->assertSame(1, DB::table('customer_notes')->count(), '2000 characters are fine (characters, not bytes)');
    }

    public function test_saving_the_note_needs_customer_view_on_the_server(): void
    {
        $this->actingAsStaff('Administrator');
        $id = $this->insertOrder(['email' => 'me@example.com']);
        $component = Livewire::test(CustomerPage::class, ['key' => 'o-'.$id]);

        $this->actingAsCustomRole([Permission::ORDER_VIEW]);

        try {
            $component->set('noteBody', 'sneaky')->call('saveNote');
        } catch (\Throwable) {
            // refused (403)
        }

        $this->assertSame(0, DB::table('customer_notes')->count());
    }

    // ---- the query count ------------------------------------------------------------------------------------------------------------

    /** ★ the page's query count does not grow with the number of orders */
    public function test_the_query_count_of_the_page_does_not_grow_with_5_versus_50_orders(): void
    {
        $this->actingAsStaff('Administrator');
        $accountId = $this->insertAccount('few@example.com');
        $this->insertAccount('many@example.com');
        $few = $this->insertOrders(array_map(fn (int $i): array => ['email' => 'few@example.com', 'phone' => '+3598881000'.$i, 'consent' => true, 'account_id' => $accountId], range(10, 14)));
        $many = $this->insertOrders(array_map(fn (int $i): array => ['email' => 'many@example.com', 'phone' => '+359888'.str_pad((string) (200000 + $i), 6, '0', STR_PAD_LEFT), 'consent' => true], range(1, 50)));
        DB::table('addresses')->insert([['account_id' => $accountId, 'delivery_type' => 'street_address', 'recipient_first_name' => 'a', 'recipient_last_name' => 'b', 'phone' => '+359877111111', 'country' => 'BG', 'city' => 'X', 'postal_code' => '1', 'address_line_1' => 'Y', 'created_at' => now(), 'updated_at' => now()]]);
        $this->get('/admin/customers/o-'.$few[0])->assertOk(); // warm: the first request of a process loads more than the second

        $count = function (int $orderId): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            $this->get('/admin/customers/o-'.$orderId)->assertOk();

            return $n;
        };

        $small = $count($few[0]);
        $large = $count($many[0]);

        fwrite(STDERR, "\n[query-count] customer page: 5 orders {$small}, 50 orders {$large}\n");
        $this->assertSame($small, $large, 'the number of orders and phones does not change the number of queries');
    }
}
