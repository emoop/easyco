<?php

namespace Tests\Feature\Storefront;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use EasyCo\Account\Security\SignedLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-3a: the storefront account pages: headers, markup rules, the guest redirect, no state change on a GET, a cached page that is the same for everybody. */
class AccountPagesTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
    }

    /** @return array<string, array{0: string, 1: bool}> path => [path, carries a token in its url (so no-referrer)] */
    public static function pages(): array
    {
        return [
            'login' => ['/account/login', false],
            'register' => ['/account/register', false],
            'forgot' => ['/account/forgot', false],
            'reset' => ['/account/reset?token=SECRET-TOKEN-123&email=a%40b.test', true],
            'verify' => ['/account/verify?id=1&expires=9999999999&signature=SECRET-SIGNATURE-456', true],
            'email confirm' => ['/account/email/confirm?id=1&token=SECRET-TOKEN-789&expires=9999999999&signature=SECRET-SIGNATURE-456', true],
        ];
    }

    // ---- headers ---------------------------------------------------------------------------------------------------------------

    #[DataProvider('pages')]
    public function test_each_page_is_200_never_cached_and_noindex(string $url, bool $tokenPage): void
    {
        $response = $this->get($url)->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('xkey'), 'never a surrogate key: Varnish cannot keep it');
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex">', false);

        if ($tokenPage) {
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        } else {
            $this->assertNotSame('no-referrer', $response->headers->get('Referrer-Policy'));
        }
    }

    public function test_the_account_page_is_never_cached_either(): void
    {
        $this->actingAs($this->verifiedAccount('me@example.com'), 'customer');

        $response = $this->get('/account')->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->headers->get('xkey'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        $response->assertSee('<meta name="robots" content="noindex">', false);
    }

    // ---- markup rules ----------------------------------------------------------------------------------------------------------

    #[DataProvider('pages')]
    public function test_no_inline_script_no_style_attribute_and_no_resource_from_another_host(string $url, bool $tokenPage): void
    {
        $html = (string) $this->get($url)->assertOk()->getContent();

        $this->assertNoInlineScriptOrStyleOrForeignResource($html);
    }

    public function test_the_same_rules_hold_on_the_account_page(): void
    {
        $this->actingAs($this->verifiedAccount('me@example.com'), 'customer');

        $this->assertNoInlineScriptOrStyleOrForeignResource((string) $this->get('/account')->assertOk()->getContent());
    }

    private function assertNoInlineScriptOrStyleOrForeignResource(string $html): void
    {
        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER);

        foreach ($scripts as [$all, $attributes, $body]) {
            $this->assertSame('', trim($body), 'no inline script body: '.$all);
            $this->assertMatchesRegularExpression('#\bsrc=#i', $attributes, 'a script has a src');
        }

        $this->assertDoesNotMatchRegularExpression('#<style\b#i', $html, 'no <style> element');
        $this->assertDoesNotMatchRegularExpression('#\sstyle\s*=#i', $html, 'no style= attribute');
        $this->assertDoesNotMatchRegularExpression('#\son[a-z]+\s*=#i', $html, 'no inline event handler');

        preg_match_all('#<(?:script|link|img|iframe|source)\b[^>]*\b(?:src|href)\s*=\s*"([^"]*)"#i', $html, $urls);
        $own = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        foreach ($urls[1] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $this->assertTrue($host === null || $host === $own || $host === 'localhost', "a resource from another host: {$url}");
        }
    }

    // ---- the pages' content ----------------------------------------------------------------------------------------------------

    public function test_the_login_page_has_labelled_fields_with_the_right_autocomplete_and_the_links(): void
    {
        $response = $this->get('/account/login')->assertOk();

        $response->assertSee('autocomplete="email"', false)->assertSee('autocomplete="current-password"', false);
        $response->assertSee('for="sf-acc-email"', false)->assertSee('for="sf-acc-password"', false);
        $response->assertSee('name="remember"', false)->assertSee('data-sf-password-toggle', false);
        $response->assertSee('href="/account/forgot"', false)->assertSee('href="/account/register"', false);
        $response->assertSee('aria-live="polite"', false);
    }

    public function test_the_register_and_reset_pages_ask_for_a_new_password(): void
    {
        $this->get('/account/register')->assertOk()->assertSee('autocomplete="new-password"', false)->assertSee('8', false);
        $this->get('/account/reset?token=t&email=a%40b.test')->assertOk()->assertSee('name="password_confirmation"', false);
    }

    public function test_the_login_page_passes_only_a_safe_redirect_on_and_shows_the_reset_notice(): void
    {
        $this->get('/account/login?redirect=/account/orders')->assertSee('data-redirect="/account/orders"', false);
        $this->get('/account/login?redirect=//evil.com')->assertSee('data-redirect="/account"', false)->assertDontSee('evil.com');
        $this->get('/account/login?redirect=https://evil.com')->assertSee('data-redirect="/account"', false)->assertDontSee('evil.com');
        $this->get('/account/login?notice=password-reset')->assertSee(__('account_pages.notice_password_reset'));
        $this->get('/account/login?notice=<script>')->assertDontSee('<script>', false);
    }

    public function test_the_texts_are_in_both_languages_with_the_same_keys(): void
    {
        $bg = require base_path('lang/bg/account_pages.php');
        $en = require base_path('lang/en/account_pages.php');

        $this->assertSame(array_keys($bg), array_keys($en));
        $this->assertSame(array_keys($bg['js']), array_keys($en['js']));
        $this->assertSame('Моят профил', $bg['title']);
    }

    // ---- /account --------------------------------------------------------------------------------------------------------------

    public function test_the_account_page_sends_a_guest_to_the_login_with_the_way_back(): void
    {
        $this->get('/account')->assertRedirect('/account/login?redirect=/account');
    }

    public function test_the_account_page_greets_a_signed_in_customer_and_has_a_sign_out_form(): void
    {
        $account = $this->verifiedAccount('me@example.com');
        $this->login('me@example.com')->assertOk();

        $response = $this->get('/account')->assertOk();

        $response->assertSee(__('account_pages.home_greeting', ['email' => 'me@example.com']));
        $response->assertSee('data-sf-account-logout-form', false);
        $this->assertSame((int) $account->getKey(), (int) Auth::guard('customer')->id());
    }

    public function test_a_stale_session_is_a_guest_on_the_account_page(): void
    {
        $account = $this->verifiedAccount('me@example.com');
        $this->login('me@example.com')->assertOk();
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertOk();

        // another session still carries the old version
        $this->flushSession();
        $this->withSession([AccountSessionVersion::SESSION_KEY => 0]);
        $this->actingAs($account->fresh(), 'customer');

        $this->get('/account')->assertRedirect('/account/login?redirect=/account');
    }

    // ---- a GET changes nothing; no token in the HTML ---------------------------------------------------------------------------

    public function test_loading_the_verify_page_does_not_verify_the_account(): void
    {
        $account = $this->unverifiedAccount('pending@example.com');
        $params = SignedLink::params('verify', (string) $account->getKey(), 3600);

        $html = (string) $this->get('/account/verify?'.http_build_query($params))->assertOk()->getContent();

        $this->assertNull(AccountModel::findOrFail($account->getKey())->email_verified_at, 'a mail scanner opening the link verifies nothing');
        $this->assertStringNotContainsString($params['signature'], $html, 'the signature is not in the HTML');
        $this->assertStringContainsString(__('account_pages.verify_button'), $html);
    }

    public function test_loading_the_email_confirm_page_does_not_change_the_email(): void
    {
        $account = $this->verifiedAccount('old@example.com');
        $params = SignedLink::params('email', (string) $account->getKey(), 3600, 'TOKEN-ABCDEF-123456');
        DB::table('account_email_changes')->insert(['account_id' => $account->getKey(), 'new_email' => 'new@example.com', 'token_hash' => hash('sha256', 'TOKEN-ABCDEF-123456'), 'expires_at' => now()->addHour(), 'created_at' => now()]);
        $before = [(array) DB::table('accounts')->where('id', $account->getKey())->first(), DB::table('account_email_changes')->count()];

        $html = (string) $this->get('/account/email/confirm?'.http_build_query($params))->assertOk()->getContent();

        $this->assertSame($before, [(array) DB::table('accounts')->where('id', $account->getKey())->first(), DB::table('account_email_changes')->count()], 'nothing changed: the email is still the old one and the request is still waiting');
        $this->assertSame('old@example.com', AccountModel::findOrFail($account->getKey())->email);
        $this->assertStringNotContainsString('TOKEN-ABCDEF-123456', $html);
        $this->assertStringNotContainsString($params['signature'], $html);
    }

    public function test_the_reset_page_never_holds_the_token_or_the_email_of_the_link(): void
    {
        $html = (string) $this->get('/account/reset?token=SECRET-TOKEN-123&email=someone%40example.com')->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET-TOKEN-123', $html);
        $this->assertStringNotContainsString('someone@example.com', $html);
    }

    // ---- the cached pages ------------------------------------------------------------------------------------------------------

    public function test_the_cached_home_page_is_identical_for_a_guest_and_a_signed_in_customer_and_links_to_the_account(): void
    {
        $guest = $this->get('/')->assertOk();
        $guestHtml = (string) $guest->getContent();

        $this->login($this->verifiedAccount('me@example.com')->email)->assertOk();
        $customer = $this->get('/')->assertOk();

        $this->assertSame($guestHtml, (string) $customer->getContent(), 'no per-visitor HTML in a cached page');
        $this->assertStringContainsString('href="/account"', $guestHtml, 'the header person icon / drawer row link to /account for everybody');
        $this->assertStringNotContainsString('href="/account/login"', $guestHtml);
    }
}
