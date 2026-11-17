<?php

namespace Tests\Feature\Account;

use App\Mail\MailTemplates;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use EasyCo\Extensibility\Hook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Account\Concerns\DrivesAccountApi;
use Tests\TestCase;

/** ACC-1 decisions 6, 7 and 8: forgotten password, reset, change password, change email. */
class AccountPasswordAndEmailTest extends TestCase
{
    use DrivesAccountApi;
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a-brand-new-pass-77';

    protected function setUp(): void
    {
        parent::setUp();
        $this->driveAccountApi();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
    }

    private function forgot(string $email)
    {
        return $this->postJson('/api/account/password/forgot', ['email' => $email]);
    }

    /** the raw token of the latest reset mail */
    private function resetToken(string $email): string
    {
        $jobs = $this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, $email);
        $this->assertNotEmpty($jobs, 'a reset mail was queued');
        $params = $this->linkParams(end($jobs));
        $this->assertSame($email, $params['email']);

        return $params['token'];
    }

    private function reset(string $email, string $token, ?string $password = null)
    {
        return $this->postJson('/api/account/password/reset', ['email' => $email, 'token' => $token, 'password' => $password ?? self::NEW_PASSWORD, 'password_confirmation' => $password ?? self::NEW_PASSWORD]);
    }

    // ---- forgot / reset -------------------------------------------------------------------------------------------------------

    public function test_forgot_is_the_same_202_for_an_unknown_and_a_known_address_and_mails_every_existing_account_verified_or_not(): void
    {
        $this->verifiedAccount('known@example.com');
        $this->unverifiedAccount('pending@example.com');

        $unknown = $this->forgot('nobody@example.com')->assertStatus(202);
        $known = $this->forgot('KNOWN@example.com')->assertStatus(202);
        $pending = $this->forgot('pending@example.com')->assertStatus(202);

        $this->assertSame($unknown->getContent(), $known->getContent());
        $this->assertSame($unknown->getContent(), $pending->getContent());
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'known@example.com'));
        $this->assertCount(0, $this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'nobody@example.com'));
        // F2: an unverified account gets the mail too (the reset proves the mailbox and verifies it)
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'pending@example.com'));
    }

    public function test_the_token_is_stored_only_as_a_hash_one_row_per_email_and_a_new_request_replaces_the_old(): void
    {
        $this->verifiedAccount('known@example.com');

        $this->forgot('known@example.com');
        $first = $this->resetToken('known@example.com');
        $this->forgot('known@example.com');
        $second = $this->resetToken('known@example.com');

        $this->assertNotSame($first, $second);
        $rows = DB::table('account_password_reset_tokens')->get();
        $this->assertCount(1, $rows);
        $this->assertNotSame($second, $rows[0]->token_hash);
        $this->assertSame(hash('sha256', $second), $rows[0]->token_hash);

        $this->reset('known@example.com', $first)->assertStatus(422);   // the replaced one is dead
    }

    public function test_at_most_three_reset_mails_an_hour_per_address(): void
    {
        $this->verifiedAccount('known@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->forgot('known@example.com')->assertStatus(202);
        }

        $this->assertCount(3, $this->mails(MailTemplates::ACCOUNT_PASSWORD_RESET, 'known@example.com'));
    }

    /** the reset: new password, token deleted, version bumped, «changed» mail, hook, and an unverified email is verified (the mailbox was proven) */
    public function test_a_valid_reset_sets_the_password_bumps_the_version_mails_and_fires_the_hook(): void
    {
        $account = $this->verifiedAccount('known@example.com');
        $fired = [];
        Hook::action('account.password_changed', function (...$args) use (&$fired): void {
            $fired[] = $args;
        });

        $this->forgot('known@example.com');
        $token = $this->resetToken('known@example.com');

        $this->reset('known@example.com', $token)->assertStatus(200);

        $account->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->password));
        $this->assertSame(1, (int) $account->session_version);
        $this->assertSame(0, DB::table('account_password_reset_tokens')->count());
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_PASSWORD_CHANGED, 'known@example.com'));
        $this->assertSame([[(string) $account->getKey()]], $fired);

        $this->reset('known@example.com', $token)->assertStatus(422);   // single use
        $this->login('known@example.com', self::NEW_PASSWORD)->assertStatus(200);
    }

    public function test_a_reset_also_verifies_an_unverified_email(): void
    {
        $account = $this->unverifiedAccount('pending@example.com');
        DB::table('account_password_reset_tokens')->insert(['email' => 'pending@example.com', 'token_hash' => hash('sha256', 'tok'), 'created_at' => now()]);

        $this->reset('pending@example.com', 'tok')->assertStatus(200);

        $this->assertNotNull($account->fresh()->email_verified_at);
    }

    /** ★ an expired token (61 minutes) is refused */
    public function test_a_token_older_than_sixty_minutes_is_refused_and_one_inside_the_hour_works(): void
    {
        $this->verifiedAccount('known@example.com');
        $this->forgot('known@example.com');
        $token = $this->resetToken('known@example.com');

        $this->travel(59)->minutes();
        $inside = $this->reset('known@example.com', $token);
        $inside->assertStatus(200);

        $this->forgot('known@example.com');
        $token = $this->resetToken('known@example.com');
        $this->travel(61)->minutes();

        $this->reset('known@example.com', $token)->assertStatus(422);
    }

    public function test_every_reset_failure_is_the_same_generic_422(): void
    {
        $this->verifiedAccount('known@example.com');
        $this->forgot('known@example.com');
        $token = $this->resetToken('known@example.com');

        $bodies = [
            $this->reset('known@example.com', 'wrong-token')->assertStatus(422)->getContent(),
            $this->reset('nobody@example.com', $token)->assertStatus(422)->getContent(),
        ];

        // an unverified-only / unknown / wrong token: the answer never says which part was wrong
        $this->assertSame($bodies[0], $bodies[1]);
        $this->assertSame('The link for a new password is invalid or has expired.', json_decode($bodies[0], true)['message']);
    }

    public function test_a_reset_with_a_bad_password_is_a_password_error_and_keeps_the_token(): void
    {
        $this->verifiedAccount('known@example.com');
        $this->forgot('known@example.com');
        $token = $this->resetToken('known@example.com');

        $this->reset('known@example.com', $token, 'password123')->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->reset('known@example.com', $token)->assertStatus(200);
    }

    // ---- change password ------------------------------------------------------------------------------------------------------

    public function test_change_password_needs_the_current_password_and_the_rules(): void
    {
        $account = $this->verifiedAccount('user@example.com');
        $this->login()->assertStatus(200);

        $this->putJson('/api/account/password', ['current_password' => 'not-it-at-all-1', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => self::NEW_PASSWORD, 'password_confirmation' => 'different-one-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check($this->strong, $account->fresh()->password), 'nothing changed');
        $this->assertCount(0, $this->mails(MailTemplates::ACCOUNT_PASSWORD_CHANGED));
    }

    public function test_change_password_mails_fires_the_hook_and_works_only_when_logged_in(): void
    {
        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertStatus(401);

        $account = $this->verifiedAccount('user@example.com');
        $fired = [];
        Hook::action('account.password_changed', function (...$args) use (&$fired): void {
            $fired[] = $args;
        });
        $this->login()->assertStatus(200);

        $this->putJson('/api/account/password', ['current_password' => $this->strong, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])->assertStatus(200);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->fresh()->password));
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_PASSWORD_CHANGED, 'user@example.com'));
        $this->assertSame([[(string) $account->getKey()]], $fired);
    }

    // ---- change email ---------------------------------------------------------------------------------------------------------

    private function requestChange(string $new, ?string $password = null)
    {
        return $this->postJson('/api/account/email', ['current_password' => $password ?? $this->strong, 'email' => $new]);
    }

    /** ★ the confirmation goes to the NEW address, a notice to the OLD one; the email does NOT change at request time */
    public function test_the_request_mails_the_new_address_notifies_the_old_and_changes_nothing_yet(): void
    {
        $account = $this->verifiedAccount('old@example.com');
        $this->login('old@example.com')->assertStatus(200);

        $this->requestChange('new@example.com')->assertStatus(202);

        $this->assertSame('old@example.com', $account->fresh()->email, 'the email changes only on confirm');
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_EMAIL_CONFIRM, 'new@example.com'));
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_EMAIL_NOTICE, 'old@example.com'));
        $this->assertSame(1, DB::table('account_email_changes')->count());
        $this->assertNotSame($this->linkParams($this->mails(MailTemplates::ACCOUNT_EMAIL_CONFIRM)[0])['token'], DB::table('account_email_changes')->value('token_hash'), 'only the hash is stored');
    }

    public function test_the_request_needs_the_current_password_and_a_logged_in_customer(): void
    {
        $this->requestChange('new@example.com')->assertStatus(401);

        $this->verifiedAccount('old@example.com');
        $this->login('old@example.com');

        $this->requestChange('new@example.com', 'not-it-at-all-1')->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertCount(0, $this->mails(MailTemplates::ACCOUNT_EMAIL_CONFIRM));
    }

    public function test_a_taken_or_the_same_address_gets_the_same_202_and_no_mail(): void
    {
        $this->verifiedAccount('old@example.com');
        $this->verifiedAccount('taken@example.com');
        $this->login('old@example.com');

        $fresh = $this->requestChange('free@example.com')->assertStatus(202);
        $taken = $this->requestChange('TAKEN@example.com')->assertStatus(202);
        $same = $this->requestChange('old@example.com')->assertStatus(202);

        $this->assertSame($fresh->getContent(), $taken->getContent());
        $this->assertSame($fresh->getContent(), $same->getContent());
        $this->assertCount(1, $this->mails(MailTemplates::ACCOUNT_EMAIL_CONFIRM), 'only the free address got a link');
    }

    private function confirmParams(string $new): array
    {
        $jobs = $this->mails(MailTemplates::ACCOUNT_EMAIL_CONFIRM, $new);

        return $this->linkParams(end($jobs));
    }

    public function test_confirming_changes_the_email_verifies_it_bumps_the_version_and_fires_the_hook(): void
    {
        $account = $this->verifiedAccount('old@example.com');
        $this->login('old@example.com');
        $this->requestChange('new@example.com');
        $fired = [];
        Hook::action('account.email_changed', function (...$args) use (&$fired): void {
            $fired[] = $args;
        });

        $this->postJson('/api/account/email/confirm', $this->confirmParams('new@example.com'))->assertStatus(200);

        $account->refresh();
        $this->assertSame('new@example.com', $account->email);
        $this->assertNotNull($account->email_verified_at);
        $this->assertSame(1, (int) $account->session_version);
        $this->assertSame(0, DB::table('account_email_changes')->count());
        $this->assertSame([[(string) $account->getKey()]], $fired);
    }

    /** the new address was taken between the request and the confirm: the generic 422, nothing changes */
    public function test_a_confirm_after_the_address_was_taken_is_the_generic_422(): void
    {
        $account = $this->verifiedAccount('old@example.com');
        $this->login('old@example.com');
        $this->requestChange('new@example.com');
        $params = $this->confirmParams('new@example.com');

        $this->verifiedAccount('new@example.com');

        $taken = $this->postJson('/api/account/email/confirm', $params)->assertStatus(422);
        $this->assertSame('old@example.com', $account->fresh()->email);

        $tampered = $this->postJson('/api/account/email/confirm', ['signature' => 'x'.$params['signature']] + $params)->assertStatus(422);
        $this->assertSame($taken->getContent(), $tampered->getContent(), 'the answer does not say which reason');
    }

    public function test_a_confirm_link_expires_and_is_not_a_verify_link(): void
    {
        $this->verifiedAccount('old@example.com');
        $this->login('old@example.com');
        $this->requestChange('new@example.com');
        $params = $this->confirmParams('new@example.com');

        $this->postJson('/api/account/verify', $params)->assertStatus(422);

        $this->travel(25)->hours();
        $this->postJson('/api/account/email/confirm', $params)->assertStatus(422);
        $this->assertSame('old@example.com', AccountModel::first()->email);
    }

    public function test_one_pending_change_per_account_the_newer_replaces_the_older(): void
    {
        $this->verifiedAccount('old@example.com');
        $this->login('old@example.com');
        $this->requestChange('first@example.com');
        $firstLink = $this->confirmParams('first@example.com');
        $this->requestChange('second@example.com');

        $this->assertSame(1, DB::table('account_email_changes')->count());
        $this->postJson('/api/account/email/confirm', $firstLink)->assertStatus(422);
        $this->postJson('/api/account/email/confirm', $this->confirmParams('second@example.com'))->assertStatus(200);
    }
}
