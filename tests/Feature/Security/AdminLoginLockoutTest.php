<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\Auth\AdminLogin;
use App\Mail\RenderedMailable;
use App\Security\Lockout\AdminLoginLockout;
use EasyCo\Staff\Contracts\PasswordHasher as StaffPasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Seeders\StaffSystemRolesSeeder;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** P8d: the admin login lockout (account + IP), its generic answer, the decay, and the account-wide alarm that never blocks. */
class AdminLoginLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.mode' => 'block']);
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:00:00'));
        $this->staff('owner@example.com');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function staff(string $email): void
    {
        $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');

        if ($role === null) {
            app(StaffSystemRolesSeeder::class)->run(app(RoleRepository::class));
            $role = app(RoleRepository::class)->findSystemRoleByName('Product Entry');
        }

        app(StaffRepository::class)->save(Staff::create($email, app(StaffPasswordHasher::class)->hash('right-password'), 'Test Staff', $role));
    }

    /** one submit of the real login page; returns the message under the email field (null = no error) */
    private function submit(string $email, string $password): ?string
    {
        Auth::guard('staff')->logout();

        $test = Livewire::test(AdminLogin::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
        $errors = $test->errors()->get('data.email');

        return $errors[0] ?? null;
    }

    private function failTimes(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->submit($email, 'wrong-password');
        }
    }

    public function test_five_failures_lock_the_pair_for_five_minutes_with_the_generic_message(): void
    {
        $wrong = $this->submit('owner@example.com', 'wrong-password');
        $this->assertNotNull($wrong);
        $this->failTimes('owner@example.com', 4);

        // the 6th try with the RIGHT password is refused, with EXACTLY the message of a wrong password
        $locked = $this->submit('owner@example.com', 'right-password');

        $this->assertSame($wrong, $locked, 'the locked answer must be the generic wrong-password message');
        $this->assertFalse(Auth::guard('staff')->check());
        $this->assertStringNotContainsStringIgnoringCase('lock', (string) $locked);
        $this->assertStringNotContainsStringIgnoringCase('блок', (string) $locked);

        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        $this->assertNull($this->submit('owner@example.com', 'right-password'), 'after 5 minutes the right password works');
        $this->assertTrue(Auth::guard('staff')->check());
    }

    public function test_the_steps_are_five_minutes_then_thirty_minutes_then_a_day(): void
    {
        $lockout = app(AdminLoginLockout::class);

        $this->failTimes('owner@example.com', 5);
        $this->assertEqualsWithDelta(300, $lockout->lockedFor('owner@example.com', '127.0.0.1'), 2);

        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        $this->failTimes('owner@example.com', 5);
        $this->assertEqualsWithDelta(1800, $lockout->lockedFor('owner@example.com', '127.0.0.1'), 2);
        $this->assertNotNull($this->submit('owner@example.com', 'right-password'), 'still locked after 5 minutes of the 30');

        Carbon::setTestNow(Carbon::now()->addSeconds(1801));
        $this->failTimes('owner@example.com', 5);
        $this->assertEqualsWithDelta(86400, $lockout->lockedFor('owner@example.com', '127.0.0.1'), 2);

        Carbon::setTestNow(Carbon::now()->addSeconds(3600));
        $this->assertNotNull($this->submit('owner@example.com', 'right-password'), 'still locked an hour into the 24');

        Carbon::setTestNow(Carbon::now()->addSeconds(86401));
        $this->assertNull($this->submit('owner@example.com', 'right-password'));
    }

    public function test_a_success_resets_the_counter(): void
    {
        $this->failTimes('owner@example.com', 4);
        $this->assertNull($this->submit('owner@example.com', 'right-password'));

        // four more failures after the success are not the 5th failure of a streak
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->failTimes('owner@example.com', 4);

        $this->assertSame(0, app(AdminLoginLockout::class)->lockedFor('owner@example.com', '127.0.0.1'));
    }

    public function test_the_counter_decays_a_day_after_the_last_failure(): void
    {
        $this->failTimes('owner@example.com', 4);
        Carbon::setTestNow(Carbon::now()->addSeconds(86401));

        $this->failTimes('owner@example.com', 4); // 8 in total without the decay: that would have locked at the 5th

        $this->assertSame(0, app(AdminLoginLockout::class)->lockedFor('owner@example.com', '127.0.0.1'));
        $this->assertNull($this->submit('owner@example.com', 'right-password'));
    }

    /** ★ keyed by account AND ip: another IP for the same account, and another account from the same IP, are not locked */
    public function test_the_lock_is_per_account_and_ip(): void
    {
        $lockout = app(AdminLoginLockout::class);

        for ($i = 0; $i < 5; $i++) {
            $lockout->recordFailure('Owner@Example.com ', '10.0.0.1');
        }

        $this->assertGreaterThan(0, $lockout->lockedFor('owner@example.com', '10.0.0.1'), 'the email is normalised');
        $this->assertSame(0, $lockout->lockedFor('owner@example.com', '10.0.0.2'), 'the real owner from another IP');
        $this->assertSame(0, $lockout->lockedFor('someone-else@example.com', '10.0.0.1'), 'another account from the same IP');
    }

    /** the account-wide alarm: 20 failures from 20 IPs → one log line and one mail, never a second in the hour, never a lock */
    public function test_the_account_wide_alarm_mails_once_and_locks_nobody(): void
    {
        Mail::fake();
        Log::spy();
        $lockout = app(AdminLoginLockout::class);

        for ($i = 1; $i <= 19; $i++) {
            $lockout->recordFailure('owner@example.com', "10.0.1.{$i}");
        }

        Mail::assertNothingSent();

        $lockout->recordFailure('owner@example.com', '10.0.1.20');
        Mail::assertSent(RenderedMailable::class, 1);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'Many failed admin logins'))->once();

        for ($i = 21; $i <= 40; $i++) {
            $lockout->recordFailure('owner@example.com', "10.0.1.{$i}");
        }

        Mail::assertSent(RenderedMailable::class, 1); // ★ a mail on every failure would be 21 here

        for ($i = 1; $i <= 40; $i++) {
            $this->assertSame(0, $lockout->lockedFor('owner@example.com', "10.0.1.{$i}"), "10.0.1.{$i} failed once: no lock");
        }

        // an hour later the alarm may speak again
        Carbon::setTestNow(Carbon::now()->addSeconds(3601));
        for ($i = 1; $i <= 20; $i++) {
            $lockout->recordFailure('owner@example.com', "10.0.2.{$i}");
        }
        Mail::assertSent(RenderedMailable::class, 2);
    }

    public function test_the_alarm_works_in_log_mode_too_and_never_blocks(): void
    {
        config(['security.mode' => 'log']);
        Mail::fake();

        for ($i = 1; $i <= 20; $i++) {
            app(AdminLoginLockout::class)->recordFailure('owner@example.com', "10.0.3.{$i}");
        }

        Mail::assertSent(RenderedMailable::class, 1);
    }

    /** `log` mode: five failures are counted and the lock is logged, but the right password still gets in */
    public function test_log_mode_logs_the_lock_and_does_not_block(): void
    {
        config(['security.mode' => 'log']);
        Log::spy();

        $this->failTimes('owner@example.com', 5);
        Carbon::setTestNow(Carbon::now()->addSeconds(61)); // Filament's own 5-per-minute limit is not what is under test

        $this->assertNull($this->submit('owner@example.com', 'right-password'));
        $this->assertTrue(Auth::guard('staff')->check());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'would block'))->atLeast()->once();
    }

    public function test_every_lock_is_logged_with_a_hashed_email_the_ip_and_the_step(): void
    {
        Log::spy();

        $this->failTimes('owner@example.com', 5);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []): bool {
            return $message === 'Admin login locked.'
                && $context['email_hash'] === AdminLoginLockout::hash('owner@example.com')
                && ! in_array('owner@example.com', $context, true)
                && $context['step'] === 1
                && isset($context['ip']);
        })->once();
    }
}
