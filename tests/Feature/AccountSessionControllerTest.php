<?php

namespace Tests\Feature;

use EasyCo\Account\Account;
use EasyCo\Account\Contracts\AccountRepository;
use EasyCo\Account\Contracts\PasswordHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSessionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // See AccountRegistrationControllerTest — required for Sanctum's
        // EnsureFrontendRequestsAreStateful to recognize these requests
        // as "from the frontend" and engage the session pipeline.
        $this->withHeader('Referer', 'http://localhost/');
    }

    private function registerAccount(string $email = 'user@example.com', string $password = 'password123'): void
    {
        $account = Account::register($email, app(PasswordHasher::class)->hash($password));
        app(AccountRepository::class)->save($account);

        // ACC-1: login needs a VERIFIED account (an unverified one is a 403 `email_unverified`, tested in AccountSecurityLoginTest)
        \EasyCo\Account\Persistence\Eloquent\AccountModel::whereKey($account->id())->update(['email_verified_at' => now()]);
    }

    public function test_login_happy_path_returns_200_and_establishes_a_session(): void
    {
        $this->registerAccount();

        $response = $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('email', 'user@example.com');

        $me = $this->getJson('/api/account/me');
        $me->assertStatus(200);
        $me->assertJsonPath('email', 'user@example.com');
    }

    public function test_wrong_password_returns_401_with_a_generic_message(): void
    {
        $this->registerAccount();

        $response = $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => 'the-wrong-password',
        ]);

        $response->assertStatus(401);
        // ACC-1 (decision 3): the generic 401 text is the shop's «invalid email or password» (was 'Invalid credentials.')
        $response->assertJsonPath('message', 'Invalid email or password.');
    }

    public function test_nonexistent_email_returns_401_with_the_identical_generic_message_as_wrong_password(): void
    {
        $this->registerAccount();

        $wrongPassword = $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => 'the-wrong-password',
        ]);

        $nonexistentEmail = $this->postJson('/api/account/login', [
            'email' => 'nobody@example.com',
            'password' => 'anything123',
        ]);

        $wrongPassword->assertStatus(401);
        $nonexistentEmail->assertStatus(401);

        // The actual point of the anti-enumeration decision: identical
        // response bodies, not just "both are 401".
        $this->assertSame($wrongPassword->json(), $nonexistentEmail->json());
        $this->assertSame('Invalid email or password.', $nonexistentEmail->json('message'));
    }

    public function test_logout_happy_path_clears_the_session(): void
    {
        $this->registerAccount();
        $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $logout = $this->postJson('/api/account/logout');
        $logout->assertStatus(204);

        $me = $this->getJson('/api/account/me');
        $me->assertStatus(401);
    }

    public function test_me_while_never_authenticated_returns_401(): void
    {
        $response = $this->getJson('/api/account/me');

        $response->assertStatus(401);
    }

    public function test_me_while_authenticated_returns_200_with_correct_id_and_email(): void
    {
        $this->registerAccount('user@example.com', 'password123');
        $login = $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);
        $expectedId = $login->json('id');

        $me = $this->getJson('/api/account/me');

        $me->assertStatus(200);
        $me->assertJsonPath('id', $expectedId);
        $me->assertJsonPath('email', 'user@example.com');
    }

    public function test_the_sixth_failed_login_attempt_in_a_minute_is_a_normal_401_the_seventh_is_rate_limited(): void
    {
        $this->registerAccount();

        for ($i = 1; $i <= 6; $i++) {
            $response = $this->postJson('/api/account/login', [
                'email' => 'user@example.com',
                'password' => 'the-wrong-password',
            ]);
            $response->assertStatus(401, "Attempt {$i} should be a normal 401, got {$response->getStatusCode()}.");
        }

        $seventh = $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => 'the-wrong-password',
        ]);

        $seventh->assertStatus(429);
    }

    // --- Input hardening pass 2: the two credential fields' width limit -----------------

    /** Built exactly as AccountRegistrationControllerTest::emailOfLength() builds it. */
    private function emailOfLength(int $characters): string
    {
        $localLength = 65;                       // 64 'a's and the '@'
        $finalLabel = 'com';                     // its own leading dot is counted below
        $domainCharacters = $characters - $localLength - mb_strlen($finalLabel) - 1;

        $labels = (int) ceil(($domainCharacters + 1) / 11);
        $labelCharacters = $domainCharacters - ($labels - 1);
        $base = intdiv($labelCharacters, $labels);
        $longer = $labelCharacters % $labels;

        $domain = [];

        for ($i = 0; $i < $labels; $i++) {
            $domain[] = str_repeat(chr(98 + ($i % 24)), $base + ($i < $longer ? 1 : 0));
        }

        $email = str_repeat('a', 64).'@'.implode('.', $domain).'.'.$finalLabel;

        $this->assertSame($characters, mb_strlen($email), 'The builder must produce an address of exactly the requested length.');

        return $email;
    }

    /**
     * The longest credential pair this endpoint can actually see log in: 254
     * characters is the Account domain's own ceiling for an address (see
     * AccountRegistrationControllerTest), 255 the width this layer allows for
     * the password. Both reach the credential check and are accepted, so
     * `max:255` is not refusing a legitimate long value.
     */
    public function test_a_254_character_email_and_a_72_byte_password_log_in(): void
    {
        $email = $this->emailOfLength(254);
        // ACC-1: 72 bytes is the password ceiling (bcrypt); the login field keeps its own 255 width limit (next test)
        $password = str_repeat('k7', 36);

        $this->registerAccount($email, $password);

        $response = $this->postJson('/api/account/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('email', $email);
    }

    /**
     * 422, not the endpoint's usual 401: an over-wide value is refused by the
     * rules before any credential is looked at, so it can never be confused with
     * "wrong email or password".
     */
    public function test_a_256_character_email_is_a_422_field_error_rather_than_a_401(): void
    {
        $response = $this->postJson('/api/account/login', [
            'email' => $this->emailOfLength(256),
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    public function test_a_256_character_password_is_a_422_field_error_rather_than_a_401(): void
    {
        $this->registerAccount();

        $response = $this->postJson('/api/account/login', [
            'email' => 'user@example.com',
            'password' => str_repeat('p', 256),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['password']);
    }
}
