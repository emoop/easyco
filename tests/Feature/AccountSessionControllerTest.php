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
        $response->assertJsonPath('message', 'Invalid credentials.');
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
        $this->assertSame('Invalid credentials.', $nonexistentEmail->json('message'));
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
}
