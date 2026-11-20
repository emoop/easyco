<?php

namespace Tests\Feature\Privacy;

use App\Privacy\PrivacyEraser;
use App\Privacy\PrivacyHash;
use App\Privacy\PrivacyNotConfigured;
use App\Privacy\PrivacySource;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ACC-4a, Part A: the keyed hash of an email and a phone: normalised, keyed, and never silently keyless outside local and testing. */
class PrivacyHashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SiteSettingsRepository::class)->set('site.country', 'BG');
    }

    public function test_the_same_email_in_another_case_or_with_spaces_gives_the_same_hash(): void
    {
        $hash = app(PrivacyHash::class);

        $this->assertSame($hash->email('me@example.com'), $hash->email('  Me@Example.COM '));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $hash->email('me@example.com'));
        $this->assertNotSame($hash->email('me@example.com'), $hash->email('other@example.com'));
        $this->assertNull($hash->email('   '));
        $this->assertNull($hash->email(null));
    }

    public function test_a_national_and_an_international_phone_give_the_same_hash_and_an_unreadable_one_none(): void
    {
        $hash = app(PrivacyHash::class);

        $this->assertSame($hash->phone('+359888123456'), $hash->phone('0888 123 456'));
        $this->assertNotNull($hash->phone('+359888123456'));
        $this->assertNotSame($hash->phone('+359888123456'), $hash->phone('+359888123457'));
        $this->assertNull($hash->phone('abc'));
        $this->assertNull($hash->phone(''));
    }

    public function test_a_different_key_gives_a_different_hash_and_the_kinds_never_collide(): void
    {
        $before = app(PrivacyHash::class)->email('me@example.com');

        config(['privacy.hash_key' => 'another-key-entirely']);

        $this->assertNotSame($before, app(PrivacyHash::class)->email('me@example.com'));
        $this->assertNotSame(app(PrivacyHash::class)->email('1234567890'), hash_hmac('sha256', '1234567890', 'another-key-entirely'), 'the kind is part of what is hashed');
    }

    public function test_without_a_key_testing_derives_a_stable_development_key(): void
    {
        config(['privacy.hash_key' => null]);

        $a = app(PrivacyHash::class)->email('me@example.com');

        $this->assertSame($a, app(PrivacyHash::class)->email('me@example.com'));
        $this->assertSame(hash_hmac('sha256', 'email|me@example.com', hash('sha256', 'privacy-dev-key|'.config('app.key'))), $a);
    }

    public function test_in_production_without_a_key_it_refuses_and_the_eraser_writes_nothing(): void
    {
        config(['privacy.hash_key' => null]);
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            app(PrivacyHash::class)->email('me@example.com');
            $this->fail('production without PRIVACY_HASH_KEY must refuse');
        } catch (PrivacyNotConfigured $e) {
            $this->assertStringContainsString('PRIVACY_HASH_KEY', $e->getMessage());
        }

        $this->expectException(PrivacyNotConfigured::class);

        try {
            app(PrivacyEraser::class)->erase('me@example.com', PrivacySource::STOREFRONT);
        } finally {
            $this->assertSame(0, DB::table('privacy_requests')->count());
            $this->assertSame(0, DB::table('privacy_suppressions')->count());
        }
    }

    public function test_staging_is_not_a_development_environment_either(): void
    {
        config(['privacy.hash_key' => '']);
        $this->app->detectEnvironment(static fn (): string => 'staging');

        $this->expectException(PrivacyNotConfigured::class);

        app(PrivacyHash::class)->phone('+359888123456');
    }
}
