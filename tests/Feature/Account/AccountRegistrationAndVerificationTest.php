<?php

namespace Tests\Feature\Account;

use App\Mail\MailTemplates;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Account\Security\SignedLink;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-1 decisions 1 and 2: registration never reveals whether an email exists; verification. */
class AccountRegistrationAndVerificationTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        config(['ratelimits.registration' => 100]); // the IP budget (5 a minute) is ApiRateLimitersTest's business
    }

    private function register(string $email, ?string $password = null)
    {
        return $this->postJson('/api/account/register', ['email' => $email, 'password' => $password ?? $this->strong, 'password_confirmation' => $password ?? $this->strong]);
    }

    /** ★ a new / a verified-existing / an unverified-existing address: the SAME status and body; each gets the mail it should; nobody is logged in */
    public function test_the_three_kinds_of_address_get_the_identical_answer_and_their_own_mail(): void
    {
        $this->verifiedAccount('verified@example.com');
        $this->unverifiedAccount('pending@example.com');

        $new = $this->register('new@example.com');
        $verified = $this->register('verified@example.com');
        $pending = $this->register('pending@example.com');

        foreach ([$new, $verified, $pending] as $response) {
            $response->assertStatus(202);
        }
        $this->assertSame($new->getContent(), $verified->getContent());
        $this->assertSame($new->getContent(), $pending->getContent());
        $this->assertSame('Check your mailbox to activate the account.', $new->json('message'));

        // the mails
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'new@example.com'));
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_ALREADY_REGISTERED, 'verified@example.com'));
        $this->assertCount(0, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'verified@example.com'));
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'pending@example.com'));

        // the «already registered» mail carries a login and a reset link
        $already = $this->mails(MailTemplates::ACCOUNT_ALREADY_REGISTERED)[0];
        $this->assertSame('https://shop.test/account/login', $already->links['login_link']['url']);
        $this->assertSame('https://shop.test/account/forgot', $already->links['reset_link']['url']);

        // no login, no new row for the existing ones
        $this->assertSame(3, AccountModel::query()->count());
        $this->assertNull(AccountModel::query()->where('email', 'new@example.com')->first()->email_verified_at);
        $this->getJson('/api/account/me')->assertStatus(401);
    }

    public function test_an_unverified_address_gets_its_password_replaced_by_the_new_one(): void
    {
        $this->unverifiedAccount('pending@example.com');
        $before = (string) AccountModel::first()->password;

        $this->register('pending@example.com', 'a-different-pass-42')->assertStatus(202);

        $after = AccountModel::first();
        $this->assertNotSame($before, $after->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('a-different-pass-42', $after->password));
    }

    public function test_a_verified_account_is_not_touched_by_a_registration_with_its_address(): void
    {
        $account = $this->verifiedAccount('verified@example.com');
        $hash = (string) $account->password;

        $this->register('verified@example.com', 'someone-elses-pass-9')->assertStatus(202);

        $this->assertSame($hash, (string) AccountModel::first()->password, 'the owner\'s password stays');
    }

    public function test_at_most_three_mails_an_hour_go_to_one_address_from_registration(): void
    {
        $this->unverifiedAccount('pending@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->register('pending@example.com')->assertStatus(202);
        }

        $this->assertCount(3, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'pending@example.com'));
    }

    /** the real mail: a verification mail is composed through the mail machinery (queued job, renderer, sender) with an https link in the body and nothing else personal */
    public function test_the_verification_mail_is_really_rendered_and_sent(): void
    {
        \Illuminate\Support\Facades\Queue::fake()->except(\App\Mail\SendAccountMailJob::class); // this job really runs (sync driver) in this one test
        \Illuminate\Support\Facades\Mail::fake();

        $this->register('real@example.com')->assertStatus(202);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\RenderedMailable::class, function (\App\Mail\RenderedMailable $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('real@example.com')
                && str_contains($html, 'href="https://shop.test/account/verify?id=')
                && str_contains($html, 'signature=')
                && ! str_contains($html, 'plum-cart');
        });
    }

    // ---- verification -----------------------------------------------------------------------------------------------------------

    private function verifyLink(): array
    {
        $this->register('new@example.com')->assertStatus(202);

        return $this->linkParams($this->mails(MailTemplates::ACCOUNT_VERIFY, 'new@example.com')[0]);
    }

    /** ★ a valid link verifies and fires the hook (the id only) once; it does NOT log in (F1: the link proves the mailbox, it is not a login) */
    public function test_a_valid_link_verifies_does_not_log_in_and_fires_the_hook_once(): void
    {
        $fired = [];
        Hook::action('account.verified', function (...$args) use (&$fired): void {
            $fired[] = $args;
        });

        $params = $this->verifyLink();

        $answer = $this->postJson('/api/account/verify', $params)->assertStatus(200);
        $this->assertSame(['message' => 'Your email is confirmed. You can log in now.'], $answer->json(), 'no id, no email in the body');

        $account = AccountModel::first();
        $this->assertNotNull($account->email_verified_at);
        $this->getJson('/api/account/me')->assertStatus(401);
        $this->assertSame([[(string) $account->getKey()]], $fired);

        // a replay: the same 200, no change, and the hook does not fire again
        $this->postJson('/api/account/verify', $params)->assertStatus(200);
        $this->assertCount(1, $fired);
    }

    public function test_an_already_verified_account_is_a_success_without_a_change_and_without_a_login(): void
    {
        $params = $this->verifyLink();
        $this->postJson('/api/account/verify', $params)->assertStatus(200);
        $verifiedAt = AccountModel::first()->email_verified_at;

        $this->travel(2)->hours();
        $this->postJson('/api/account/verify', $params)->assertStatus(200);

        $this->assertEquals($verifiedAt, AccountModel::first()->fresh()->email_verified_at, 'unchanged');
        $this->getJson('/api/account/me')->assertStatus(401);
    }

    public function test_an_expired_link_a_tampered_link_and_another_purpose_are_one_generic_422(): void
    {
        $params = $this->verifyLink();

        $bodies = [];

        // tampered signature, tampered id, missing parts
        $bodies[] = $this->postJson('/api/account/verify', ['signature' => 'x'.$params['signature']] + $params)->assertStatus(422)->getContent();
        $other = $this->verifiedAccount('other@example.com');
        $bodies[] = $this->postJson('/api/account/verify', ['id' => (string) $other->getKey()] + $params)->assertStatus(422)->getContent();
        $bodies[] = $this->postJson('/api/account/verify', ['id' => '99999'] + SignedLink::params('verify', '99999', 3600))->assertStatus(422)->getContent();

        // a link of ANOTHER purpose (an email-change link) is not a verify link
        $bodies[] = $this->postJson('/api/account/verify', SignedLink::params('email', $params['id'], 3600))->assertStatus(422)->getContent();

        // expired
        $this->travel(25)->hours();
        $bodies[] = $this->postJson('/api/account/verify', $params)->assertStatus(422)->getContent();

        $this->assertCount(1, array_unique($bodies), 'one generic answer for every failure');
        $this->assertNull(AccountModel::query()->where('email', 'new@example.com')->first()->email_verified_at);
    }

    /**
     * ★ the hole of the review: the victim registers, an attacker registers the same address with HIS password, the victim then uses her FIRST link.
     * The link verifies and logs nobody in; her own password no longer works; «забравена парола» works for the (still unverified) account, the reset
     * sets the victim's password, and the session the attacker opened with his password before the reset is dead afterwards.
     */
    public function test_the_victims_link_does_not_log_her_into_the_attackers_password_and_a_reset_takes_the_account_back(): void
    {
        $this->register('v@example.com', 'victim-secret-4242')->assertStatus(202);
        $victimLink = $this->linkParams($this->mails(MailTemplates::ACCOUNT_VERIFY, 'v@example.com')[0]);

        $this->register('v@example.com', 'attacker-secret-9191')->assertStatus(202);   // replaces the password of the unverified account

        // the victim clicks HER link: verified, and not logged in
        $this->postJson('/api/account/verify', $victimLink)->assertStatus(200);
        $this->getJson('/api/account/me')->assertStatus(401);
        $this->assertNotNull(AccountModel::first()->email_verified_at);

        // her own password is refused (it was replaced): the first login tells her
        $this->login('v@example.com', 'victim-secret-4242')->assertStatus(401);

        // the attacker logs in with HIS password (the mailbox was verified by the victim) and holds a session
        $this->login('v@example.com', 'attacker-secret-9191')->assertStatus(200);
        $this->getJson('/api/account/me')->assertStatus(200);
        $attackerSessionVersion = (int) session(\EasyCo\Account\Security\AccountSessionVersion::SESSION_KEY);

        // the victim: forgot → reset with her password
        $this->flushSession();
        $this->postJson('/api/account/password/forgot', ['email' => 'v@example.com'])->assertStatus(202);
        $reset = $this->linkParams($this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'v@example.com')[0]);
        $this->postJson('/api/account/password/reset', ['email' => 'v@example.com', 'token' => $reset['token'], 'password' => 'victim-secret-4242', 'password_confirmation' => 'victim-secret-4242'])->assertStatus(200);

        $this->login('v@example.com', 'attacker-secret-9191')->assertStatus(401);
        $this->login('v@example.com', 'victim-secret-4242')->assertStatus(200);

        // the attacker's earlier session (it carries the old version) is dead
        $this->flushSession();
        $this->withSession([\EasyCo\Account\Security\AccountSessionVersion::SESSION_KEY => $attackerSessionVersion]);
        $this->actingAs(AccountModel::first(), 'customer');
        $this->getJson('/api/account/me')->assertStatus(401);
    }

    public function test_resend_is_always_202_sends_only_for_an_unverified_account_and_is_limited_per_address(): void
    {
        $this->unverifiedAccount('pending@example.com');
        $this->verifiedAccount('verified@example.com');

        $unknown = $this->postJson('/api/account/verify/resend', ['email' => 'nobody@example.com'])->assertStatus(202);
        $verified = $this->postJson('/api/account/verify/resend', ['email' => 'verified@example.com'])->assertStatus(202);
        $pending = $this->postJson('/api/account/verify/resend', ['email' => 'PENDING@example.com'])->assertStatus(202);
        $this->assertSame($unknown->getContent(), $verified->getContent());
        $this->assertSame($unknown->getContent(), $pending->getContent());

        $this->assertCount(0, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'nobody@example.com'));
        $this->assertCount(0, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'verified@example.com'));
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'pending@example.com'));

        // the 3-an-hour budget per address: the 4th, 5th ... are still 202 and send nothing (the IP limiter is raised out of the way)
        config(['ratelimits.verify-resend' => 100]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/account/verify/resend', ['email' => 'pending@example.com'])->assertStatus(202);
        }
        $this->assertCount(3, $this->mails(MailTemplates::ACCOUNT_VERIFY, 'pending@example.com'));
    }

    /** ★ the password rules: 7 characters, 73 bytes, = the email, a common password */
    public function test_the_password_rules_refuse_a_short_a_long_the_email_and_a_common_password(): void
    {
        $cases = [
            'seven characters' => ['abcdefg', 'too_short'],
            '73 bytes' => [str_repeat('k7', 36).'x', 'too_long'],
            'multibyte over 72 bytes' => [str_repeat('я', 37), 'too_long'], // 74 bytes, 37 characters
            'the email' => ['User@Example.com', 'same_as_email'],
            'a common password' => ['Password123', 'common'],
            'a common one, other case' => ['QWERTY12345', 'common'],
        ];

        foreach ($cases as $name => [$password, $key]) {
            $response = $this->register('user@example.com', $password);
            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['password']);
            $this->assertSame((string) __('account.password.'.$key, [], 'en'), $response->json('errors.password.0'), $name);
        }

        $this->assertSame(0, AccountModel::query()->count());

        // the bounds themselves are fine: 8 and 72 bytes
        $this->register('eight@example.com', 'k7m9x2qz')->assertStatus(202);
        $this->register('max@example.com', str_repeat('k7', 36))->assertStatus(202);
    }

    public function test_the_common_password_list_is_a_local_file_within_its_cap(): void
    {
        $lines = file(resource_path('security/common-passwords.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $this->assertNotEmpty($lines);
        $this->assertLessThanOrEqual(10000, count($lines));
    }
}
