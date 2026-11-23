<?php

namespace Tests\Feature\Privacy;

use App\Mail\MailTemplates;
use App\Mail\SendAccountMailJob;
use App\Mail\TemplateRenderer;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyConfirmationRequester;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyNotConfigured;
use App\Privacy\StaffActor;
use EasyCo\Account\Security\SignedLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ACC-4d (acc-4-gdpr-design.md §6, D2): the identity-confirmation request: the requester, the `privacy.confirm` mail and the no-store confirmation page (GET changes nothing, POST confirms once).
 */
class PrivacyConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '203.0.113.50';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        RateLimiter::clear('privacy-confirm|'.self::IP);
        config(['app.url' => 'https://shop.example']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- fixtures ----------------------------------------------------------------------------------------------------------------

    /** the request the requester writes, and the link it mailed (path + query, relative) */
    private function ask(string $kind = 'export', string $email = 'Me@Example.com'): array
    {
        Queue::fake();
        $request = app(PrivacyConfirmationRequester::class)->request($email, $kind, new StaffActor(7, 'Admin Tester'));
        $job = Queue::pushed(SendAccountMailJob::class)->first();

        return [$request, (string) preg_replace('#^https://shop\.example#', '', $job->links['action_link']['url']), $job];
    }

    private function row(string $status): PrivacyRequestModel
    {
        return PrivacyRequestModel::query()->create([
            'kind' => 'export', 'source' => 'admin', 'status' => $status, 'email' => 'me@example.com', 'email_hash' => (string) app(PrivacyHash::class)->email('me@example.com'),
        ]);
    }

    private function link(PrivacyRequestModel|int $request, string $purpose = PrivacyConfirmationRequester::PURPOSE, int $ttl = 86400): string
    {
        $id = (string) ($request instanceof PrivacyRequestModel ? $request->getKey() : $request);

        return '/account/privacy/confirm?'.http_build_query(SignedLink::params($purpose, $id, $ttl));
    }

    /** @return array{0: int, 1: string} the status and the body, for a comparison of two answers */
    private function shape(TestResponse $response): array
    {
        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    // ---- the requester -----------------------------------------------------------------------------------------------------------

    public function test_the_requester_writes_the_awaiting_row_and_mails_the_link(): void
    {
        [$request, $path, $job] = $this->ask('erase');

        $this->assertSame('awaiting_confirmation', $request->status);
        $this->assertSame('erase', $request->kind);
        $this->assertSame('admin', $request->source);
        $this->assertSame('me@example.com', $request->email);
        $this->assertSame((string) app(PrivacyHash::class)->email('me@example.com'), $request->email_hash);
        $this->assertSame(7, (int) $request->staff_id);
        $this->assertSame('Admin Tester', $request->staff_name);
        $this->assertNull($request->confirmed_at);

        $this->assertSame(MailTemplates::PRIVACY_CONFIRM, $job->templateKey);
        $this->assertSame('me@example.com', $job->email);
        $this->assertSame('mail-transactional', $job->queue);
        $this->assertStringStartsWith('/account/privacy/confirm?', $path);
    }

    public function test_the_link_is_valid_for_exactly_the_life_of_the_request(): void
    {
        $this->assertSame(7, PrivacyConfirmationRequester::VALID_DAYS);

        Carbon::setTestNow('2026-12-01 10:00:00');
        [$request, $path] = $this->ask();
        parse_str((string) parse_url($path, PHP_URL_QUERY), $q);

        $this->assertSame((string) $request->getKey(), $q['id']);
        $this->assertSame(Carbon::now()->addDays(7)->getTimestamp(), (int) $q['expires']);
        $this->assertTrue(SignedLink::valid('privacy-confirm', $q['id'], $q['expires'], $q['signature']));
        $this->assertFalse(SignedLink::valid('account-verify', $q['id'], $q['expires'], $q['signature']), 'purpose is part of the signature');

        Carbon::setTestNow(Carbon::now()->addDays(7)->subSecond());
        $this->get($path)->assertOk();
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->get($path)->assertNotFound();
    }

    public function test_the_mail_is_sent_only_after_the_commit(): void
    {
        Queue::fake();

        DB::transaction(function (): void {
            app(PrivacyConfirmationRequester::class)->request('me@example.com', 'export', null);
            Queue::assertNothingPushed();
        });

        Queue::assertPushed(SendAccountMailJob::class, 1);
    }

    public function test_the_requester_refuses_a_bad_kind_a_blank_and_a_malformed_email_and_writes_nothing(): void
    {
        Queue::fake();

        foreach ([['me@example.com', 'processing'], ['me@example.com', ''], ['', 'export'], ['   ', 'export'], ['not-an-email', 'export'], ['a b@example.com', 'erase']] as [$email, $kind]) {
            try {
                app(PrivacyConfirmationRequester::class)->request($email, $kind, null);
                $this->fail('expected a refusal of '.json_encode([$email, $kind]));
            } catch (InvalidArgumentException) {
                // refused
            }
        }

        $this->assertSame(0, PrivacyRequestModel::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_requester_refuses_without_the_hash_key_in_production_and_writes_nothing(): void
    {
        Queue::fake();
        config(['privacy.hash_key' => null]);
        $this->app['env'] = 'production';

        try {
            app(PrivacyConfirmationRequester::class)->request('me@example.com', 'export', null);
            $this->fail('expected PrivacyNotConfigured');
        } catch (PrivacyNotConfigured $e) {
            $this->assertStringContainsString('PRIVACY_HASH_KEY', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, PrivacyRequestModel::query()->count());
        Queue::assertNothingPushed();
    }

    // ---- the mail ----------------------------------------------------------------------------------------------------------------

    public function test_the_mail_names_the_request_carries_the_link_and_holds_no_other_data_in_both_languages(): void
    {
        $definition = app(MailTemplates::class)->get(MailTemplates::PRIVACY_CONFIRM);

        foreach (['bg' => ['export' => 'копие на личните ви данни', 'erase' => 'изтриване на личните ви данни'], 'en' => ['export' => 'a copy of your personal data', 'erase' => 'the deletion of your personal data']] as $locale => $types) {
            foreach ($types as $kind => $phrase) {
                $job = new SendAccountMailJob(MailTemplates::PRIVACY_CONFIRM, 'me@example.com', $locale, ['action_link' => ['url' => 'https://shop.example/account/privacy/confirm?id=1&expires=2&signature=abc', 'label' => 'privacy_confirm']], 'mail-transactional', [
                    'request_type' => (string) __('mail.privacy_confirm.'.$kind, [], $locale), 'valid_days' => '7',
                ]);
                $blocks = ['action_link' => \App\Mail\MailBlocks::link($job->links['action_link']['url'], (string) __('mail.account.privacy_confirm', [], $locale), $locale)];
                $mail = app(TemplateRenderer::class)->render($definition, $locale, ['shop_name' => 'Raf Shop'] + $job->scalars, $blocks);

                $this->assertStringContainsString($phrase, $mail->text);
                $this->assertStringContainsString('Raf Shop', $mail->subject);
                $this->assertStringContainsString('signature=abc', $mail->html);
                $this->assertStringContainsString('7', $mail->text);
                $this->assertStringNotContainsString('{{', $mail->html.$mail->text);
                $this->assertStringNotContainsString('me@example.com', $mail->html.$mail->text.$mail->subject, 'the address is the recipient, never in the text');
                $this->assertStringContainsString($locale === 'bg' ? 'игнорирайте писмото' : 'ignore this message', $mail->text);
            }
        }
    }

    public function test_the_job_keeps_working_for_a_payload_queued_before_the_scalars_existed(): void
    {
        // unserialize() builds the object WITHOUT the constructor: a payload queued before the property existed leaves it at its declared default
        $old = (new \ReflectionClass(SendAccountMailJob::class))->newInstanceWithoutConstructor();

        $this->assertSame([], $old->scalars);
        $this->assertSame([], (new SendAccountMailJob(MailTemplates::ACCOUNT_VERIFY, 'me@example.com', 'bg'))->scalars);
    }

    // ---- the page: GET writes nothing, POST confirms once -----------------------------------------------------------------------

    public function test_a_get_never_changes_the_row_and_a_post_changes_it_exactly_once(): void
    {
        [$request, $path] = $this->ask();
        $before = PrivacyRequestModel::query()->find($request->getKey())->getAttributes();

        $page = $this->get($path)->assertOk();
        $this->get($path)->assertOk();
        $page->assertSee(__('privacy_confirm.title'), false)->assertSee(__('privacy_confirm.button'), false)->assertSee('method="post"', false);

        $this->assertSame($before, PrivacyRequestModel::query()->find($request->getKey())->getAttributes(), 'GET wrote nothing');

        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->post($path)->assertOk()->assertSee(__('privacy_confirm.done_title'), false);
        $first = PrivacyRequestModel::query()->find($request->getKey());
        $this->assertSame('confirmed', $first->status);
        $this->assertNotNull($first->confirmed_at);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->post($path)->assertOk()->assertSee(__('privacy_confirm.done_title'), false);
        $second = PrivacyRequestModel::query()->find($request->getKey());
        $this->assertSame($first->confirmed_at->toDateTimeString(), $second->confirmed_at->toDateTimeString(), 'the second POST changed nothing');
        $this->assertSame($first->updated_at->toDateTimeString(), $second->updated_at->toDateTimeString());

        $this->get($path)->assertOk()->assertSee(__('privacy_confirm.done_title'), false);
    }

    public function test_a_confirmation_exports_and_erases_nothing(): void
    {
        [$request, $path] = $this->ask('erase');
        DB::table('accounts')->insert(['email' => 'me@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $tables = ['accounts', 'orders', 'privacy_suppressions'];
        $counts = array_map(fn (string $t): int => DB::table($t)->count(), $tables);

        $this->post($path)->assertOk();

        $this->assertSame($counts, array_map(fn (string $t): int => DB::table($t)->count(), $tables));
        $this->assertSame(1, PrivacyRequestModel::query()->count(), 'no export or erase row appeared');
        $this->assertNull($request->fresh()->export_path);
    }

    public function test_every_invalid_link_gets_the_same_neutral_page_and_status_and_writes_nothing(): void
    {
        config(['privacy.confirm_per_minute' => 1000]); // the limiter has its own test: this one posts many links from one address
        [$request, $good] = $this->ask();
        $neutral = $this->shape($this->get($this->link(999999)));

        $this->assertSame(404, $neutral[0]);
        $this->assertStringContainsString(__('privacy_confirm.invalid_title'), $neutral[1]);

        $cases = [
            'tampered signature' => preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $good),
            'tampered id' => preg_replace('/id=\d+/', 'id='.($request->getKey() + 1), $good),
            'wrong purpose' => $this->link($request, 'email'),
            'expired link' => $this->link($request, PrivacyConfirmationRequester::PURPOSE, -5),
            'unknown request' => $this->link(987654),
            'no parameters' => '/account/privacy/confirm',
            'array parameters' => '/account/privacy/confirm?id[]=1&expires[]=2&signature[]=3',
        ];

        foreach (['expired', 'processing', 'done', 'failed'] as $status) {
            $cases['status '.$status] = $this->link($this->row($status));
        }

        $rowsBefore = PrivacyRequestModel::query()->orderBy('id')->get()->toJson();

        foreach ($cases as $name => $url) {
            $this->assertSame($neutral, $this->shape($this->get($url)), 'GET: '.$name);
            $this->assertSame($neutral, $this->shape($this->post($url)), 'POST: '.$name);
        }

        $this->assertSame($rowsBefore, PrivacyRequestModel::query()->orderBy('id')->get()->toJson(), 'nothing was written');
        $this->assertSame('awaiting_confirmation', $request->fresh()->status);
    }

    public function test_the_answers_are_no_store_private_set_no_cookie_and_show_no_email_or_order_data(): void
    {
        [$request, $path] = $this->ask();
        DB::table('accounts')->insert(['email' => 'me@example.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([$this->get($path), $this->post($path), $this->get($path), $this->get($this->link(999999)), $this->post($this->link(999999))] as $response) {
            $cache = (string) $response->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cache);
            $this->assertStringContainsString('private', $cache);
            $this->assertStringNotContainsString('s-maxage', $cache);
            $this->assertSame([], $response->headers->getCookies(), 'no cookie');
            $this->assertNull($response->headers->get('xkey'));
            $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            $this->assertStringNotContainsString('me@example.com', (string) $response->getContent());
            $this->assertStringNotContainsString('Me@Example.com', (string) $response->getContent());
        }

        $html = (string) $this->get($path)->getContent();
        $this->assertStringNotContainsString('signature=', $html, 'the HTML never holds the signed parameters');
        $this->assertStringNotContainsString('<form class="sf-account-form" method="post" action', $html);
    }

    public function test_the_reason_check_still_demands_a_reason_of_a_forget_log_row_but_not_of_a_confirmation_row(): void
    {
        $base = ['kind' => 'erase', 'source' => 'admin', 'email' => 'me@example.com', 'email_hash' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];

        foreach (['awaiting_confirmation', 'confirmed', 'expired'] as $status) {
            DB::table('privacy_requests')->insert($base + ['status' => $status]);
        }

        foreach (['processing', 'done', 'failed'] as $status) {
            try {
                DB::table('privacy_requests')->insert($base + ['status' => $status]);
                $this->fail('an admin erase row in `'.$status.'` needs a reason');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame(3819, $e->errorInfo[1] ?? null, 'a CHECK violation');
            }
        }

        DB::table('privacy_requests')->insert($base + ['status' => 'done', 'reason' => 'requested by the customer']);
        $this->assertSame(4, DB::table('privacy_requests')->count());
    }

    public function test_array_parameters_for_expires_or_signature_get_the_neutral_page_never_an_error(): void
    {
        config(['privacy.confirm_per_minute' => 1000]);
        [$request] = $this->ask();
        $id = (string) $request->getKey();
        $neutral = $this->shape($this->get($this->link(999999)));
        $this->assertSame(404, $neutral[0]);

        foreach (["/account/privacy/confirm?id={$id}&expires[]=1&signature=x", "/account/privacy/confirm?id={$id}&expires=1&signature[]=x", "/account/privacy/confirm?id[]={$id}&expires=1&signature=x"] as $url) {
            $this->assertSame($neutral, $this->shape($this->get($url)), 'GET '.$url);
            $this->assertSame($neutral, $this->shape($this->post($url)), 'POST '.$url);
        }

        $this->assertSame('awaiting_confirmation', $request->fresh()->status);
    }

    /**
     * Laravel skips CSRF verification while running unit tests, so the HTTP tests above cannot prove that production has no CSRF or session on this page. The ROUTE's resolved middleware list can.
     */
    public function test_the_confirmation_routes_run_in_the_cookie_free_pipeline_with_the_no_store_headers_and_the_limiter(): void
    {
        $router = app('router');
        $forbidden = [
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        ];
        class_exists(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class) && $forbidden[] = \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class;

        foreach (['storefront.privacy.confirm' => false, 'storefront.privacy.confirm.store' => true] as $name => $limited) {
            $route = $router->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $list = array_map(fn ($m): string => is_string($m) ? $m : get_debug_type($m), $router->gatherRouteMiddleware($route));

            foreach ($forbidden as $class) {
                foreach ($list as $middleware) {
                    $this->assertFalse(is_a(explode(':', $middleware)[0], $class, true), "{$name} must not run {$class} (found {$middleware})");
                }
            }

            $this->assertContains(\App\Storefront\Account\AccountPageHeaders::class.':no-referrer', $list, $name);
            $throttle = \Illuminate\Routing\Middleware\ThrottleRequests::class.':privacy-confirm';
            $limited ? $this->assertContains($throttle, $list, $name) : $this->assertNotContains($throttle, $list, $name);
        }
    }

    public function test_the_post_is_limited_per_ip_and_refused_as_a_no_store_429(): void
    {
        config(['privacy.confirm_per_minute' => 3]);
        [, $path] = $this->ask();

        for ($i = 0; $i < 3; $i++) {
            $this->post($path)->assertOk();
        }

        $refused = $this->post($path);
        $refused->assertStatus(429);
        $this->assertStringContainsString('no-store', (string) $refused->headers->get('Cache-Control'));
        $this->assertNotNull($refused->headers->get('Retry-After'));
        $this->assertSame([], $refused->headers->getCookies());

        // another address has its own budget; the GET is not limited
        $this->get($path)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])->post($path)->assertOk();
    }

    // ---- the expiry (privacy:expire-requests) -------------------------------------------------------------------------------------

    public function test_a_link_that_is_still_valid_never_belongs_to_an_expired_request_and_the_page_goes_neutral_after_the_command(): void
    {
        Carbon::setTestNow('2026-12-01 10:00:00');
        [$request, $path] = $this->ask();
        Carbon::setTestNow(Carbon::now()->addDays(7)->addMinute());

        $this->artisan('privacy:expire-requests')->assertSuccessful();

        $this->assertSame('expired', $request->fresh()->status);
        $this->get($path)->assertNotFound();
    }

    // ---- the thin command ----------------------------------------------------------------------------------------------------------

    public function test_the_command_prints_the_id_and_the_status_and_never_the_link_or_the_email(): void
    {
        Queue::fake();

        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'export'])
            ->expectsOutputToContain('Request id: ')
            ->expectsOutputToContain('Status: awaiting_confirmation')
            ->doesntExpectOutputToContain('signature')
            ->doesntExpectOutputToContain('me@example.com')
            ->assertSuccessful();

        Queue::assertPushed(SendAccountMailJob::class, 1);

        $this->artisan('privacy:ask-confirmation', ['email' => 'me@example.com', 'kind' => 'nope'])->assertFailed();
        $this->assertSame(1, PrivacyRequestModel::query()->count());
    }
}
