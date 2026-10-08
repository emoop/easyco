<?php

namespace Tests\Feature;

use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
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

    // --- Input hardening pass 2: the two credential fields' width limit -----------------

    /**
     * A syntactically valid address of exactly $characters ASCII characters: a
     * 64-character local part, then short (at most 10-character) domain labels
     * and a three-character final one.
     *
     * THE BUILDER IS NOT DECORATION: `email` carries NO length bound of its own
     * — a 256-character address built this way passes it — so a long-enough
     * address is exactly the input that would reach accounts.email's
     * varchar(255), or the Account domain's own 254-character filter_var()
     * ceiling, and come back as a 500 if this layer carried no width rule of its
     * own. Labels of 63 characters are deliberately avoided: the validator
     * refuses those constructions whatever their total length, which would prove
     * nothing.
     */
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
     * 254 is the longest address this endpoint accepts, and the controller's own
     * `max:254` is exactly that ceiling — deliberately the DOMAIN's number, not
     * accounts.email's column width: Account::normalizeAndValidateEmail() checks
     * the address with filter_var(FILTER_VALIDATE_EMAIL), which refuses anything
     * longer than 254 characters, so 255 is the ONE width between the column and
     * that ceiling — the one width `max:255` let through to an uncaught
     * InvalidArgumentException, i.e. a 500 (the case directly below).
     */
    public function test_a_254_character_email_is_accepted_and_creates_the_account(): void
    {
        $email = $this->emailOfLength(254);

        $response = $this->postJson('/api/account/register', [
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('email', $email);

        $this->assertSame(1, AccountModel::count());
        $this->assertSame($email, AccountModel::first()->email);
    }

    public function test_a_256_character_email_is_refused_by_the_width_limit_not_the_email_rule(): void
    {
        $email = $this->emailOfLength(256);

        $this->assertTrue(
            Validator::make(['email' => $email], ['email' => 'email'])->passes(),
            'The address rule itself accepts this address, so only the width limit refuses it.'
        );

        $response = $this->postJson('/api/account/register', [
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $this->assertSame(0, AccountModel::count());
    }

    /**
     * INPUT HARDENING (third pass, 3b) — this WAS a reported gap, and this test is
     * the one that changed with it. 255 characters is the ONE width between
     * accounts.email's varchar(255) and the Account domain's own 254-character
     * filter_var() ceiling: with `max:255` on the field it passed this layer and
     * was then refused by the domain with an InvalidArgumentException the
     * controller does not catch — an uncaught 500 rather than a field error,
     * confirmed against the running app. The controller's `max:254` closes it, so
     * the refusal is now THIS layer's own, a 422 naming the field, and nothing is
     * written. The address rule is asserted to still accept the address, because
     * the point is that the WIDTH is what refuses it.
     */
    public function test_a_255_character_email_is_refused_by_the_width_rule_as_a_field_error_not_a_500(): void
    {
        $email = $this->emailOfLength(255);

        $this->assertTrue(
            Validator::make(['email' => $email], ['email' => 'email'])->passes(),
            'The address rule itself accepts this address — the width rule is what refuses it.'
        );

        $response = $this->postJson('/api/account/register', [
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'email' => __('validation.max.string', ['attribute' => 'email', 'max' => 254], 'en'),
        ]);
        $this->assertSame(0, AccountModel::count());
    }

    public function test_a_255_character_password_is_accepted_and_a_256_character_one_is_a_422_field_error(): void
    {
        $atLimit = str_repeat('p', 255);

        $accepted = $this->postJson('/api/account/register', [
            'email' => 'first@example.com',
            'password' => $atLimit,
            'password_confirmation' => $atLimit,
        ]);

        $accepted->assertStatus(201);
        $this->assertSame(1, AccountModel::count());

        $overLimit = str_repeat('p', 256);

        $refused = $this->postJson('/api/account/register', [
            'email' => 'second@example.com',
            'password' => $overLimit,
            'password_confirmation' => $overLimit,
        ]);

        $refused->assertStatus(422);
        $refused->assertJsonValidationErrors(['password']);
        $this->assertSame(1, AccountModel::count());
    }
}
