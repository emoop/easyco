<?php

namespace Tests\Feature\Privacy;

use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacySettings;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ACC-4a, Part E: the housekeeping commands, their settings and their place in the schedule. */
class PrivacyHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $override = []): PrivacyRequestModel
    {
        return PrivacyRequestModel::query()->create($override + [
            'kind' => 'erase', 'source' => 'storefront', 'status' => 'done', 'email' => 'a@example.com', 'email_hash' => (string) app(PrivacyHash::class)->email('a@example.com'),
            'phone' => '+359888123456', 'phone_hash' => (string) app(PrivacyHash::class)->phone('+359888123456'),
        ]);
    }

    private function mailRow(string $queuedAt): void
    {
        DB::table('mail_log')->insert([
            'template_key' => 'order.confirmation', 'idempotency_key' => uniqid('k', true), 'category' => 'transactional', 'to_email' => 'x@example.com', 'locale' => 'en',
            'status' => 'queued', 'queued_at' => $queuedAt, 'created_at' => $queuedAt, 'updated_at' => $queuedAt,
        ]);
    }

    // ---- 9: the clear view ------------------------------------------------------------------------------------------------------

    public function test_the_clear_email_and_phone_go_only_after_clear_until_and_the_hash_lookup_keeps_working(): void
    {
        $past = $this->request(['clear_until' => now()->subMinute()]);
        $future = $this->request(['email' => 'b@example.com', 'email_hash' => (string) app(PrivacyHash::class)->email('b@example.com'), 'clear_until' => now()->addDays(3)]);
        $none = $this->request(['kind' => 'export', 'email' => 'c@example.com', 'email_hash' => (string) app(PrivacyHash::class)->email('c@example.com')]);

        $this->artisan('privacy:expire-clear-view')->expectsOutputToContain('Cleared the email and phone of 1 privacy request(s).')->assertSuccessful();

        $this->assertNull($past->fresh()->email);
        $this->assertNull($past->fresh()->phone);
        $this->assertSame('b@example.com', $future->fresh()->email);
        $this->assertSame('c@example.com', $none->fresh()->email, 'a row without clear_until is not an erasure and is left alone');

        $hash = app(PrivacyHash::class)->email('A@example.com');
        $this->assertTrue(PrivacyRequestModel::query()->where('kind', 'erase')->where('email_hash', $hash)->exists(), 'was there a forget request for this email? Yes, through the hash');
        $this->assertSame(app(PrivacyHash::class)->phone('+359888123456'), $past->fresh()->phone_hash);

        $this->artisan('privacy:expire-clear-view')->expectsOutputToContain('Cleared the email and phone of 0 privacy request(s).')->assertSuccessful();
    }

    // ---- 10: expire requests ----------------------------------------------------------------------------------------------------

    // ACC-4d: 7 days (was 24 hours), the same constant as the confirmation link; both edges
    public function test_a_request_waiting_more_than_7_days_expires_and_loses_its_email_and_one_at_6_days_23_hours_does_not(): void
    {
        $old = $this->request(['kind' => 'export', 'status' => 'awaiting_confirmation']);
        $fresh = $this->request(['kind' => 'export', 'status' => 'awaiting_confirmation']);
        $doneOld = $this->request();
        PrivacyRequestModel::query()->whereKey([$old->getKey(), $doneOld->getKey()])->update(['created_at' => now()->subDays(7)->subHour()]);
        PrivacyRequestModel::query()->whereKey($fresh->getKey())->update(['created_at' => now()->subDays(6)->subHours(23)]);

        $this->artisan('privacy:expire-requests')->assertSuccessful();

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertNull($old->fresh()->email);
        $this->assertNotNull($old->fresh()->email_hash);
        $this->assertSame('awaiting_confirmation', $fresh->fresh()->status);
        $this->assertSame('a@example.com', $fresh->fresh()->email);
        $this->assertSame('done', $doneOld->fresh()->status);
        $this->assertSame('a@example.com', $doneOld->fresh()->email, 'a finished request is not touched');
    }

    public function test_the_mail_log_is_pruned_by_its_retention_with_the_bounds_respected(): void
    {
        $this->mailRow(now()->subDays(400)->toDateTimeString());
        $this->mailRow(now()->subDays(100)->toDateTimeString());
        $this->mailRow(now()->subDay()->toDateTimeString());
        $settings = app(SiteSettingsRepository::class);

        // out of bounds is the default (365): 29 and 3651 and text are ignored
        foreach (['29', '3651', 'abc', '5'] as $bad) {
            $settings->set(PrivacySettings::MAIL_LOG_RETENTION_DAYS_KEY, $bad);
            $this->assertSame(365, app(PrivacySettings::class)->mailLogRetentionDays(), $bad);
        }

        $this->artisan('mail:prune-log')->expectsOutputToContain('Deleted 1 mail log row(s) older than 365 day(s).')->assertSuccessful();
        $this->assertSame(2, DB::table('mail_log')->count());

        $settings->set(PrivacySettings::MAIL_LOG_RETENTION_DAYS_KEY, '30');
        $this->assertSame(30, app(PrivacySettings::class)->mailLogRetentionDays());
        $this->artisan('mail:prune-log')->expectsOutputToContain('Deleted 1 mail log row(s) older than 30 day(s).')->assertSuccessful();
        $this->assertSame(1, DB::table('mail_log')->count(), 'only the row of yesterday is left');

        $settings->set(PrivacySettings::MAIL_LOG_RETENTION_DAYS_KEY, '3650');
        $this->assertSame(3650, app(PrivacySettings::class)->mailLogRetentionDays());
    }

    public function test_an_expired_reset_token_is_deleted_and_a_live_one_stays(): void
    {
        DB::table('account_password_reset_tokens')->insert([
            ['email' => 'old@example.com', 'token_hash' => str_repeat('a', 64), 'created_at' => now()->subHours(2)],
            ['email' => 'new@example.com', 'token_hash' => str_repeat('b', 64), 'created_at' => now()->subMinutes(10)],
        ]);

        $this->artisan('accounts:prune-reset-tokens')->expectsOutputToContain('Deleted 1 expired reset token(s).')->assertSuccessful();

        $this->assertSame(['new@example.com'], DB::table('account_password_reset_tokens')->pluck('email')->all());
    }

    public function test_the_housekeeping_is_in_the_schedule_daily_and_staggered(): void
    {
        Artisan::call('schedule:list');
        $list = Artisan::output();

        foreach (['privacy:expire-clear-view', 'privacy:expire-requests', 'mail:prune-log', 'cart:prune', 'accounts:prune-reset-tokens', 'queue:prune-failed --hours=720', 'privacy:anonymise-order-ips'] as $command) {
            $this->assertStringContainsString($command, $list, $command.' is not scheduled');
        }

        foreach (['10 2', '20 2', '30 2', '40 2', '50 2', '0  3'] as $at) {
            $this->assertMatchesRegularExpression('/\b'.str_replace(' ', '\s+', $at).'\s+\*\s+\*\s+\*/', $list, "no entry at {$at}");
        }
    }

    // ---- the settings -----------------------------------------------------------------------------------------------------------

    public function test_the_retention_years_default_to_ten_and_keep_their_bounds(): void
    {
        $settings = app(SiteSettingsRepository::class);
        $this->assertSame(10, app(PrivacySettings::class)->orderRetentionYears());

        foreach (['0' => 10, '31' => 10, 'x' => 10, '1' => 1, '30' => 30, '12' => 12] as $stored => $expected) {
            $settings->set(PrivacySettings::ORDER_RETENTION_YEARS_KEY, (string) $stored);
            $this->assertSame($expected, app(PrivacySettings::class)->orderRetentionYears(), (string) $stored);
        }
    }

    public function test_the_manual_steps_are_validated_on_write_and_on_read(): void
    {
        $settings = app(PrivacySettings::class);
        $this->assertSame([], $settings->manualSteps());

        $settings->saveManualSteps(['Махнат от аудиторията във Facebook', ' Махнат от Mailchimp ']);
        $this->assertSame(['Махнат от аудиторията във Facebook', 'Махнат от Mailchimp'], $settings->manualSteps());

        foreach ([[''], ['  '], [str_repeat('я', 81)], ["bad\x07label"], ['same', 'same'], array_map(static fn (int $n): string => "step {$n}", range(1, 11)), [['nested']]] as $bad) {
            try {
                $settings->saveManualSteps($bad);
                $this->fail('the labels '.json_encode($bad, JSON_UNESCAPED_UNICODE).' must be refused');
            } catch (\InvalidArgumentException) {
                // refused
            }
        }

        $this->assertSame(['Махнат от аудиторията във Facebook', 'Махнат от Mailchimp'], $settings->manualSteps(), 'a refused write changes nothing');

        // a hand-edited row is judged again on read: bad entries dropped, at most ten
        app(SiteSettingsRepository::class)->set(PrivacySettings::MANUAL_STEPS_KEY, json_encode(array_merge(['ok', 5, '', "ba\x07d"], array_map(static fn (int $n): string => "s{$n}", range(1, 20)))));
        $read = $settings->manualSteps();
        $this->assertCount(10, $read);
        $this->assertSame(['ok', 's1', 's2'], array_slice($read, 0, 3));

        app(SiteSettingsRepository::class)->set(PrivacySettings::MANUAL_STEPS_KEY, 'not json');
        $this->assertSame([], $settings->manualSteps());
    }
}
