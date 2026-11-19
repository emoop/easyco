<?php

namespace Tests\Feature\Storefront;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use EasyCo\Address\Address;
use EasyCo\Address\Contracts\AddressRepository;
use EasyCo\Address\Enums\AddressDeliveryType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-3b-2: the address book, the street-address form (new / edit) and «Данни», read on the server. (The writes are the API's and the script's: Js/account-flow.mjs.) */
class AccountAddressPagesTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
    }

    private function me(string $email = 'me@example.com'): AccountModel
    {
        $account = $this->verifiedAccount($email);
        $this->actingAs($account, 'customer');

        return $account;
    }

    private function street(AccountModel $account, string $first = 'Ivan', string $line1 = 'Vitosha Blvd 1', string $phone = '+359888123456', ?string $region = null): string
    {
        $address = Address::create(
            deliveryType: AddressDeliveryType::STREET_ADDRESS,
            recipientFirstName: $first,
            recipientLastName: 'Petrov',
            phone: $phone,
            accountId: (string) $account->getKey(),
            country: 'BG',
            city: 'Sofia',
            postalCode: '1000',
            addressLine1: $line1,
            addressLine2: 'floor 3',
            region: $region,
        );
        app(AddressRepository::class)->save($address);

        return (string) $address->id();
    }

    private function pickup(AccountModel $account): string
    {
        $address = Address::create(
            deliveryType: AddressDeliveryType::PICKUP_POINT,
            recipientFirstName: 'Maria',
            recipientLastName: 'Ivanova',
            phone: '+359888765432',
            accountId: (string) $account->getKey(),
            country: 'BG',
            carrierCode: 'speedy',
            pickupPointReference: 'OFF-1',
            settlement: 'Plovdiv',
            pickupPointName: 'Speedy office Center',
            pickupPointAddress: 'Vasil Levski 52',
            pickupPointKind: 'office',
        );
        app(AddressRepository::class)->save($address);

        return (string) $address->id();
    }

    /** @return array<string, array{0: string}> */
    public static function paths(): array
    {
        return [
            'addresses' => ['/account/addresses'],
            'new' => ['/account/addresses/new'],
            'edit' => ['/account/addresses/{id}/edit'],
            'details' => ['/account/details'],
        ];
    }

    private function resolve(string $path, AccountModel $account): string
    {
        return str_contains($path, '{id}') ? str_replace('{id}', $this->street($account), $path) : $path;
    }

    // ---- access, headers, markup -------------------------------------------------------------------------------------------------

    #[DataProvider('paths')]
    public function test_a_guest_goes_to_the_login_with_this_page_as_the_way_back(string $path): void
    {
        $path = str_replace('{id}', '12', $path);

        $this->get($path)->assertRedirect('/account/login?redirect='.$path);
    }

    #[DataProvider('paths')]
    public function test_a_stale_session_is_a_guest(string $path): void
    {
        $account = $this->verifiedAccount('me@example.com');
        $path = $this->resolve($path, $account);
        $this->login('me@example.com')->assertOk();
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertOk();

        $this->flushSession();
        $this->withSession([AccountSessionVersion::SESSION_KEY => 0]);
        $this->actingAs($account->fresh(), 'customer');

        $this->get($path)->assertRedirect('/account/login?redirect='.$path);
    }

    #[DataProvider('paths')]
    public function test_headers_and_the_markup_rules(string $path): void
    {
        $account = $this->me();
        $response = $this->get($this->resolve($path, $account))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex">', false);

        $html = (string) $response->getContent();

        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER);

        foreach ($scripts as [$all, $attributes, $body]) {
            $this->assertSame('', trim($body), 'no inline script body: '.$all);
            $this->assertMatchesRegularExpression('#\bsrc=#i', $attributes);
        }

        $this->assertDoesNotMatchRegularExpression('#<style\b#i', $html);
        $this->assertDoesNotMatchRegularExpression('#\sstyle\s*=#i', $html);
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $html);

        preg_match_all('#<(?:script|link|img|iframe|source)\b[^>]*\b(?:src|href)\s*=\s*"([^"]*)"#i', $html, $urls);
        $own = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ($urls[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $this->assertTrue($host === null || $host === $own || $host === 'localhost', "a resource from another host: {$url}");
        }

        $this->assertMatchesRegularExpression('#<h1 class="sf-title">#', $html);
        $this->assertSame(1, substr_count((string) preg_replace('#<li class="sf-breadcrumb sf-breadcrumb-current" aria-current="page">.*?</li>#s', '', $html), 'aria-current="page"'), 'exactly one menu item is current');
    }

    // ---- the address book --------------------------------------------------------------------------------------------------------

    public function test_the_list_shows_only_her_addresses_the_default_first_with_the_badge_and_the_right_buttons(): void
    {
        $me = $this->me();
        $other = $this->verifiedAccount('other@example.com');
        $first = $this->street($me, 'Ivan', 'First Street 1');
        $second = $this->street($me, 'Petar', 'Second Street 2', '+359888000111', 'Pernik');
        $pickup = $this->pickup($me);
        $this->street($other, 'Stranger', 'Secret Street 9', '+359877000000');
        app(AddressRepository::class)->setDefault($second, (string) $me->getKey());

        $html = (string) $this->get('/account/addresses')->assertOk()->getContent();

        preg_match_all('#<li class="sf-acc-address" data-sf-address data-address-id="(\d+)">#', $html, $ids);
        $this->assertSame([$second, $first, $pickup], $ids[1], 'the default first, then the old order');
        $this->assertStringNotContainsString('Secret Street 9', $html);
        $this->assertStringNotContainsString('Stranger', $html);
        $this->assertStringNotContainsString('+359877000000', $html);

        $this->assertSame(1, substr_count($html, 'class="sf-acc-badge"'));
        preg_match('#<li class="sf-acc-address" data-sf-address data-address-id="'.$second.'">.*?</li>#s', $html, $card);
        $this->assertStringContainsString(__('account_pages.address_default_badge'), $card[0]);
        $this->assertStringNotContainsString('data-sf-address-default', $card[0], 'no «Направи основен» on the default');
        $this->assertStringContainsString('Petar Petrov', $card[0]);
        $this->assertStringContainsString('+359888000111', $card[0]);
        $this->assertStringContainsString('Second Street 2', $card[0]);
        $this->assertStringContainsString('Sofia 1000', $card[0], '«city postcode»');
        $this->assertStringContainsString('Pernik', $card[0], 'the region');
        $this->assertStringContainsString('href="/account/addresses/'.$second.'/edit"', $card[0]);

        preg_match('#<li class="sf-acc-address" data-sf-address data-address-id="'.$first.'">.*?</li>#s', $html, $card);
        $this->assertStringContainsString('data-sf-address-default', $card[0]);
        $this->assertStringContainsString('data-sf-address-delete', $card[0]);
        $this->assertStringContainsString('data-sf-address-delete-yes', $card[0]);
        $this->assertStringContainsString('data-sf-address-delete-no', $card[0]);

        preg_match('#<li class="sf-acc-address" data-sf-address data-address-id="'.$pickup.'">.*?</li>#s', $html, $card);
        $this->assertStringNotContainsString('/edit', $card[0], 'a pick-up address has no edit');
        $this->assertStringContainsString('Speedy office Center', $card[0]);
        $this->assertStringContainsString('Vasil Levski 52', $card[0]);
        $this->assertStringContainsString('Plovdiv', $card[0]);
        $this->assertStringContainsString('Speedy / '.__('account_pages.address_kind_office'), $card[0]);
        $this->assertStringContainsString('data-sf-address-default', $card[0]);
        $this->assertStringContainsString('data-sf-address-delete', $card[0]);

        $this->assertStringContainsString('href="/account/addresses/new"', $html);
        $this->assertStringContainsString(__('account_pages.address_add'), $html);
        $this->assertStringNotContainsString('sf-acc-full', $html);
    }

    public function test_at_twenty_the_add_button_is_replaced_by_the_full_line(): void
    {
        $me = $this->me();

        for ($i = 1; $i <= 20; $i++) {
            $this->street($me, 'Ivan', 'Street '.$i);
        }

        $html = (string) $this->get('/account/addresses')->assertOk()->getContent();

        $this->assertStringContainsString(__('account_pages.addresses_full', ['max' => 20]), $html);
        $this->assertStringNotContainsString('href="/account/addresses/new"', $html);
        $this->assertStringNotContainsString('sf-acc-add-button', $html);
        $this->get('/account/addresses/new')->assertRedirect('/account/addresses');
    }

    public function test_the_empty_state_says_so_and_offers_to_add(): void
    {
        $this->me();

        $this->get('/account/addresses')->assertOk()
            ->assertSee(__('account_pages.addresses_empty'))
            ->assertSee('href="/account/addresses/new"', false)
            ->assertDontSee('data-sf-address ', false);
    }

    // ---- the form ----------------------------------------------------------------------------------------------------------------

    public function test_the_new_form_is_empty_with_the_right_autocomplete_and_the_store_country_preselected(): void
    {
        $this->me();
        $html = (string) $this->get('/account/addresses/new')->assertOk()->getContent();

        foreach (['given-name', 'family-name', 'tel', 'country', 'address-level2', 'postal-code', 'address-line1', 'address-line2', 'address-level1'] as $autocomplete) {
            $this->assertStringContainsString('autocomplete="'.$autocomplete.'"', $html);
        }

        foreach (['recipient_first_name', 'recipient_last_name', 'phone', 'country', 'city', 'postal_code', 'address_line_1', 'address_line_2', 'region'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $html);
            $this->assertStringContainsString('data-sf-error-for="'.$name.'"', $html);
        }

        $this->assertSame(0, preg_match('#name="city"[^>]*value="[^"]+"#', $html), 'empty');
        $this->assertStringContainsString('<option value="BG" selected>', $html);
        $this->assertStringContainsString('data-address-id=""', $html);
        $this->assertStringContainsString('data-region-policy="', $html);
        $this->assertStringContainsString('href="/account/addresses"', $html, 'the «Откажи» link');
        $this->assertSame(1, preg_match('#aria-live="polite"#', $html));
    }

    public function test_the_edit_form_is_prefilled_with_her_street_address(): void
    {
        $me = $this->me();
        $id = $this->street($me, 'Ivan', 'Vitosha Blvd 1', '+359888123456', 'Sofia region');

        $html = (string) $this->get('/account/addresses/'.$id.'/edit')->assertOk()->getContent();

        $this->assertStringContainsString('data-address-id="'.$id.'"', $html);
        $this->assertStringContainsString('value="Ivan"', $html);
        $this->assertStringContainsString('value="Petrov"', $html);
        $this->assertStringContainsString('value="+359888123456"', $html);
        $this->assertStringContainsString('value="Sofia"', $html);
        $this->assertStringContainsString('value="1000"', $html);
        $this->assertStringContainsString('value="Vitosha Blvd 1"', $html);
        $this->assertStringContainsString('value="floor 3"', $html);
        $this->assertStringContainsString('value="Sofia region"', $html);
        $this->assertStringContainsString('<option value="BG" selected>', $html);
    }

    /** ★ another account's address, a pick-up address and an unknown id are the IDENTICAL 404 */
    public function test_someone_elses_a_pickup_and_an_unknown_address_are_the_same_404_and_show_nothing_of_them(): void
    {
        $me = $this->me();
        $other = $this->verifiedAccount('other@example.com');
        $mine = $this->street($me);
        $theirs = $this->street($other, 'Stranger', 'Secret Street 9', '+359877000000');
        $pickup = $this->pickup($me);

        $this->get('/account/addresses/'.$mine.'/edit')->assertOk();

        $bodies = [];

        foreach ([$theirs, $pickup, '99999999', 'abc', '0'] as $id) {
            $bodies[] = $this->get('/account/addresses/'.$id.'/edit')->assertNotFound()->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
        $this->assertStringNotContainsString('Secret Street 9', $bodies[0]);
        $this->assertStringNotContainsString('Stranger', $bodies[0]);
    }

    // ---- notices -----------------------------------------------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function notices(): array
    {
        return [
            'address-saved' => ['address-saved', 'notice_address_saved'],
            'address-deleted' => ['address-deleted', 'notice_address_deleted'],
            'address-default' => ['address-default', 'notice_address_default'],
            'email-sent' => ['email-sent', 'notice_email_sent'],
            'password-changed' => ['password-changed', 'notice_password_changed'],
        ];
    }

    #[DataProvider('notices')]
    public function test_each_notice_key_shows_its_text(string $key, string $lang): void
    {
        $this->me();

        $this->get('/account/addresses?notice='.$key)->assertOk()->assertSee('<p class="sf-account-notice" role="status">'.e(__('account_pages.'.$lang)).'</p>', false);
        $this->get('/account/details?notice='.$key)->assertOk()->assertSee(e(__('account_pages.'.$lang)), false);
    }

    public function test_an_unknown_notice_key_shows_nothing(): void
    {
        $this->me();

        foreach (['nope', '<script>alert(1)</script>', 'address-saved%00', '', 'ADDRESS-SAVED'] as $key) {
            $html = (string) $this->get('/account/addresses?notice='.urlencode($key))->assertOk()->getContent();

            $this->assertStringNotContainsString('<p class="sf-account-notice" role="status">', $html, "«{$key}»");
            $this->assertStringNotContainsString('<script>alert', $html);
        }

        $this->get('/account/details?notice[]=address-saved')->assertOk()->assertDontSee('<p class="sf-account-notice" role="status">', false);
    }

    // ---- details -----------------------------------------------------------------------------------------------------------------

    public function test_details_show_the_current_email_the_two_forms_and_never_the_hash_or_a_token(): void
    {
        $account = $this->me('me@example.com');
        $html = (string) $this->get('/account/details')->assertOk()->getContent();

        $this->assertStringContainsString('<strong>me@example.com</strong>', $html);
        $this->assertStringContainsString('data-sf-account-email-form', $html);
        $this->assertStringContainsString('data-sf-account-password-form', $html);
        $this->assertSame(4, substr_count($html, 'data-sf-password-toggle'), 'current password of the email form, current + new + repeat of the password form');
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString(__('account_pages.password_hint'), $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);

        $this->assertStringNotContainsString((string) $account->password, $html, 'the password hash');
        if ((string) $account->remember_token !== '') {
            $this->assertStringNotContainsString((string) $account->remember_token, $html);
        }
        $this->assertStringNotContainsString('signature=', $html);
        $this->assertStringNotContainsString('token_hash', $html);
    }

    // ---- query counts ------------------------------------------------------------------------------------------------------------

    private function queries(string $path): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_query_count_pins(): void
    {
        $me = $this->me();
        $id = $this->street($me);

        $this->queries('/account/addresses');                       // warm
        $one = $this->queries('/account/addresses');
        $this->queries('/account/addresses/'.$id.'/edit');
        $edit = $this->queries('/account/addresses/'.$id.'/edit');
        $this->queries('/account/details');
        $details = $this->queries('/account/details');
        $this->queries('/account/addresses/new');
        $new = $this->queries('/account/addresses/new');

        for ($i = 0; $i < 19; $i++) {
            $this->street($me, 'Ivan', 'Street '.$i);
        }

        $many = $this->queries('/account/addresses');

        fwrite(STDERR, "\n[query-count] GET /account/addresses: 1 address {$one}, 20 addresses {$many}; /new: {$new}; /{id}/edit: {$edit}; /account/details: {$details}\n");

        $this->assertSame($one, $many, 'the list is constant in the number of addresses');
        $this->assertSame(2, $one);
        $this->assertSame(1, $edit);
        $this->assertSame(1, $new);
        $this->assertSame(0, $details);
    }
}
