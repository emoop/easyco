<?php

namespace Tests\Feature;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's EnsureFrontendRequestsAreStateful only engages its
        // session/CSRF pipeline for requests it recognizes as
        // "from the frontend" — matched via Referer/Origin against
        // config('sanctum.stateful'). 'localhost' is in that list by
        // default (config/sanctum.php).
        $this->withHeader('Referer', 'http://localhost/');
    }

    public function test_happy_path_registration_returns_201_and_establishes_a_session(): void
    {
        $response = $this->postJson('/api/account/register', [
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('email', 'user@example.com');
        $this->assertSame(1, AccountModel::count());

        // The actual point of "log the new account in immediately" —
        // a follow-up request with no separate login call succeeds.
        $me = $this->getJson('/api/account/me');
        $me->assertStatus(200);
        $me->assertJsonPath('email', 'user@example.com');
    }

    public function test_registered_account_never_exposes_the_password_hash(): void
    {
        $response = $this->postJson('/api/account/register', [
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertJsonMissing(['password']);
        $response->assertJsonMissing(['passwordHash']);
        $response->assertJsonMissing(['password_hash']);
    }

    public function test_duplicate_email_returns_422_and_creates_no_duplicate_row(): void
    {
        $first = $this->postJson('/api/account/register', [
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $first->assertStatus(201);

        $second = $this->postJson('/api/account/register', [
            'email' => 'USER@EXAMPLE.COM',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $second->assertStatus(422);
        $this->assertSame(1, AccountModel::count());
    }

    public function test_invalid_email_format_returns_422(): void
    {
        $response = $this->postJson('/api/account/register', [
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AccountModel::count());
    }

    public function test_password_under_eight_characters_returns_422(): void
    {
        $response = $this->postJson('/api/account/register', [
            'email' => 'user@example.com',
            'password' => 'short1',
            'password_confirmation' => 'short1',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AccountModel::count());
    }

    public function test_password_confirmation_mismatch_returns_422(): void
    {
        $response = $this->postJson('/api/account/register', [
            'email' => 'user@example.com',
            'password' => 'password123',
            'password_confirmation' => 'somethingelse',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AccountModel::count());
    }

    public function test_missing_password_confirmation_returns_422(): void
    {
        $response = $this->postJson('/api/account/register', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AccountModel::count());
    }
}
