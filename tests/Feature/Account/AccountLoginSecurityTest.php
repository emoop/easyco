<?php

namespace Tests\Feature\Account;

use App\Security\Lockout\AdminLoginLockout;
use App\Security\Lockout\CustomerLoginLockout;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\AccountSessionVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-1 decisions 3, 4 and 5: the generic login answer, the customer lockout, remember me, the session version. */
class AccountLoginSecurityTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        config(['ratelimits.registration' => 100]);
        // the inline `throttle:6,1` of the login route is the older IP budget (AccountSessionControllerTest): out of the way here
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    public function test_a_wrong_email_and_a_wrong_password_get_the_same_401_body(): void
    {
        $this->verifiedAccount('user@example.com');

        $wrongPassword = $this->login('user@example.com', 'the-wrong-password-1');
        $wrongEmail = $this->login('nobody@example.com', 'the-wrong-password-1');

        $wrongPassword->assertStatus(401);
        $wrongEmail->assertStatus(401);
        $this->assertSame($wrongPassword->getContent(), $wrongEmail->getContent());
        $this->assertSame('Invalid email or password.', $wrongEmail->json('message'));
    }

    public function test_the_correct_password_on_an_unverified_account_is_403_email_unverified_and_a_wrong_one_stays_401(): void
    {
        $this->unverifiedAccount('pending@example.com');

        $this->login('pending@example.com')->assertStatus(403)->assertJsonPath('code', 'email_unverified');
        $this->login('pending@example.com', 'the-wrong-password-1')->assertStatus(401)->assertJsonMissingPath('code');
        $this->getJson('/api/account/me')->assertStatus(401);
    }

    /** the bg texts of decision 3 */
    public function test_the_generic_answer_is_in_the_store_locale(): void
    {
        app(\App\Settings\Contracts\SiteSettingsRepository::class)->set('site.locale', 'bg');

        $this->login('nobody@example.com', 'the-wrong-password-1')->assertStatus(401)->assertJsonPath('message', 'Невалиден имейл или парола.');
    }

    // ---- lockout --------------------------------------------------------------------------------------------------------------

    /** ★ 5 failures from one IP lock the (email, IP) pair for 5 minutes, even for the RIGHT password; another IP and another email are not locked; a success resets */
    public function test_five_failures_lock_the_pair_for_five_minutes_per_email_and_ip(): void
    {
        $this->verifiedAccount('user@example.com');
        $this->verifiedAccount('other@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->login('user@example.com', 'the-wrong-password-1')->assertStatus(401);
        }

        // locked: the right password gets the answer of a wrong one, and is not counted
        $locked = $this->login('user@example.com');
        $locked->assertStatus(401);
        $this->assertSame('Invalid email or password.', $locked->json('message'));
        $this->getJson('/api/account/me')->assertStatus(401);

        // ★ the key is (email AND IP): the same email from another IP, and another email from this IP, get in
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->login('user@example.com')->assertStatus(200);
        $this->postJson('/api/account/logout');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->login('other@example.com')->assertStatus(200);

        // after the 5 minutes the pair is free again
        $this->postJson('/api/account/logout');
        $this->travel(301)->seconds();
        $this->login('user@example.com')->assertStatus(200);
    }

    public function test_the_steps_are_the_admin_steps_and_the_scopes_do_not_share_counters(): void
    {
        $customer = app(CustomerLoginLockout::class);
        $admin = app(AdminLoginLockout::class);

        for ($i = 0; $i < 5; $i++) {
            $customer->recordFailure('same@example.com', '10.0.0.1');
        }

        $this->assertSame(300, $customer->lockedFor('same@example.com', '10.0.0.1'));
        $this->assertSame(0, $admin->lockedFor('same@example.com', '10.0.0.1'), 'a customer lock is not an admin lock');

        for ($i = 0; $i < 5; $i++) {
            $customer->recordFailure('same@example.com', '10.0.0.1');
        }
        $this->assertSame(1800, $customer->lockedFor('same@example.com', '10.0.0.1'));
    }

    public function test_log_mode_logs_the_lock_and_does_not_block(): void
    {
        config(['security.mode' => 'log']);
        $this->verifiedAccount('user@example.com');
        Log::spy();

        for ($i = 0; $i < 6; $i++) {
            $this->login('user@example.com', 'the-wrong-password-1')->assertStatus(401);
        }

        $this->login('user@example.com')->assertStatus(200);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []): bool => $m === 'Customer login locked.' && ! in_array('user@example.com', $c, true))->once();
    }

    public function test_a_customer_failure_never_feeds_the_admin_alarm(): void
    {
        \Illuminate\Support\Facades\Mail::fake();

        for ($i = 0; $i < 25; $i++) {
            app(CustomerLoginLockout::class)->recordFailure('user@example.com', "10.1.0.{$i}");
        }

        \Illuminate\Support\Facades\Mail::assertNothingSent();
        \Illuminate\Support\Facades\Mail::assertNothingQueued();
    }

    // ---- remember me ----------------------------------------------------------------------------------------------------------

    private function rememberCookie($response): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'remember_customer_')) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_the_remember_cookie_is_set_only_with_remember_and_lasts_thirty_days(): void
    {
        $this->verifiedAccount('user@example.com');

        $without = $this->login('user@example.com')->assertStatus(200);
        $this->assertNull($this->rememberCookie($without));
        $this->postJson('/api/account/logout');

        $with = $this->login('user@example.com', null, ['remember' => true])->assertStatus(200);
        $cookie = $this->rememberCookie($with);

        $this->assertNotNull($cookie, 'the guard\'s own remember cookie');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertEqualsWithDelta(time() + 30 * 86400, $cookie->getExpiresTime(), 120);
        $this->assertSame(config('session.secure') ? true : false, (bool) $cookie->isSecure());
    }

    // ---- session version ------------------------------------------------------------------------------------------------------

    /** ★ the password changed in session A: session B is logged out on its next request, A stays */
    public function test_a_password_change_in_one_session_logs_the_other_out_and_keeps_this_one(): void
    {
        $account = $this->verifiedAccount('user@example.com');

        $this->login()->assertStatus(200);                                   // session A: version 0
        $this->assertSame(0, (int) session(AccountSessionVersion::SESSION_KEY));

        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'a-brand-new-pass-77', 'password_confirmation' => 'a-brand-new-pass-77'])->assertStatus(200);
        $this->assertSame(1, (int) $account->fresh()->session_version);
        $this->getJson('/api/account/me')->assertStatus(200);                // A stays

        // session B: it still carries version 0
        $this->flushSession();
        $this->withSession([AccountSessionVersion::SESSION_KEY => 0]);
        $this->actingAs($account->fresh(), 'customer');
        $this->getJson('/api/account/me')->assertStatus(401);
    }

    public function test_a_session_without_a_version_on_a_never_bumped_account_adopts_it_and_after_a_bump_it_is_dead(): void
    {
        $account = $this->verifiedAccount('user@example.com');

        // an old (pre-feature) session: logged in, no version in it
        $this->actingAs($account, 'customer')->getJson('/api/account/me')->assertStatus(200);
        $this->assertSame(0, (int) session(AccountSessionVersion::SESSION_KEY));

        AccountSessionVersion::bump($account);

        $this->flushSession();
        $this->actingAs($account->fresh(), 'customer')->getJson('/api/account/me')->assertStatus(401);
    }

    public function test_a_bump_rotates_the_remember_token(): void
    {
        $account = $this->verifiedAccount('user@example.com');
        $account->forceFill(['remember_token' => 'old-remember-token'])->save();

        AccountSessionVersion::bump($account);

        $this->assertNotSame('old-remember-token', (string) $account->fresh()->remember_token);
        $this->assertSame(60, strlen((string) $account->fresh()->remember_token));
    }
}
