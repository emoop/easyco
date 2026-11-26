<?php

namespace Tests\Feature\Couriers;

use App\Couriers\CourierDirectory;
use App\Couriers\CourierSettings;
use App\Couriers\SyncCourierPointsJob;
use App\Filament\Pages\Settings\CouriersSettings;
use App\Filament\StaffPanelUser;
use App\Models\ActivityLogModel;
use App\Settings\Contracts\SiteSettingsRepository;
use EasyCo\CourierBoxNow\BoxNowDescriptor;
use EasyCo\CourierBoxNow\Tests\FakeCourierHttp;
use EasyCo\Shipping\Carrier\CourierEnvironment;
use EasyCo\Shipping\Carrier\CourierResponse;
use EasyCo\Shipping\Contracts\CourierHttp;
use EasyCo\Staff\Contracts\PasswordHasher;
use EasyCo\Staff\Contracts\RoleRepository;
use EasyCo\Staff\Contracts\StaffRepository;
use EasyCo\Staff\Enums\Permission;
use EasyCo\Staff\Role;
use EasyCo\Staff\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\EnablesBoxNow;
use Tests\Feature\Mail\ActsAsMailStaff;
use Tests\TestCase;

/** courier-integration-design.md §5, §10.1: the «Куриери» page. Every value here is fake; nothing is sent to a courier. */
class CouriersSettingsPageTest extends TestCase
{
    use ActsAsMailStaff;
    use EnablesBoxNow;
    use RefreshDatabase;

    private const SECRET = 'TYPED-SECRET-VALUE-123';

    protected function setUp(): void
    {
        parent::setUp();
        // Enabling a courier on the page queues its first sync (QUEUE_CONNECTION is sync in tests): never let a test run it.
        Queue::fake();
    }

    private function stored(string $key): ?string
    {
        return app(SiteSettingsRepository::class)->get($key);
    }

    /** @param list<Permission> $permissions */
    private function actAsCustomRole(array $permissions): StaffPanelUser
    {
        $role = Role::create('Custom '.Str::uuid(), $permissions);
        app(RoleRepository::class)->save($role);
        $staff = Staff::create('custom-'.Str::uuid().'@example.com', app(PasswordHasher::class)->hash('password123'), 'Custom Tester', $role);
        app(StaffRepository::class)->save($staff);
        $user = StaffPanelUser::find($staff->id());
        $this->actingAs($user, 'staff');
        session()->forget('password_hash_staff');

        return $user;
    }

    private function snapshotJson($component): string
    {
        return json_encode(\Livewire\invade($component)->lastState->getSnapshot());
    }

    // --- who may open it ---------------------------------------------------------------------------------------------

    public function test_the_page_is_for_settings_manage_only(): void
    {
        $this->actAsPanelAdministrator();
        $this->assertTrue(CouriersSettings::canAccess());
        Livewire::test(CouriersSettings::class)->assertSuccessful()->assertSee('BOX NOW');

        $this->actingAs($this->staffWithRole('Product Entry'), 'staff');
        $this->assertFalse(CouriersSettings::canAccess());
        Livewire::test(CouriersSettings::class)->assertForbidden();

        $this->actAsCustomRole([Permission::SHIPPING_MANAGE]);
        $this->assertFalse(CouriersSettings::canAccess(), 'shipping_manage alone is not settings_manage');
        Livewire::test(CouriersSettings::class)->assertForbidden();
    }

    public function test_every_installed_courier_is_off_by_default_and_the_page_shows_only_what_exists(): void
    {
        $this->actAsPanelAdministrator();

        $component = Livewire::test(CouriersSettings::class);

        $this->assertFalse($component->get('data.boxnow.enabled'));
        $this->assertSame('test', $component->get('data.boxnow.environment'));
        foreach (['boxnow', 'econt', 'speedy'] as $code) {
            $this->assertFalse($component->get("data.{$code}.enabled"), $code);
            $this->assertFalse(app(CourierSettings::class)->isEnabled($code), $code);
        }

        $html = $component->html();
        foreach (['BOX NOW', 'Econt', 'Speedy'] as $name) {
            $this->assertStringContainsString($name, $html);
        }

        // COUR-3a's sender profile and defaults are not here, and no empty section stands in for them.
        $this->assertStringNotContainsString('sender', strtolower($html));
    }

    // --- saving ------------------------------------------------------------------------------------------------------

    /**
     * The values travel in ONE request, as a browser sends them: the page drops a typed secret from its state after every
     * response (dehydrate), so a secret set in one request and saved in the next would be gone. With $save the same request saves.
     */
    private function fill($component, array $values, bool $save = false)
    {
        $updates = [];

        foreach ($values as $path => $value) {
            $updates['data.'.$path] = $value;
        }

        return $component->update(
            calls: $save ? [['method' => 'save', 'params' => [], 'path' => '']] : [],
            updates: $updates,
        );
    }

    public function test_saving_stores_the_values_and_keeps_test_and_production_apart(): void
    {
        $this->actAsPanelAdministrator();

        $this->fill(Livewire::test(CouriersSettings::class), [
            'boxnow.enabled' => true,
            'boxnow.environment' => 'production',
            'boxnow.map_enabled' => true,
            'boxnow.test.api_host' => ' API-Stage.BoxNow.bg ',
            'boxnow.test.partner_id' => 'P-TEST',
            'boxnow.test.client_id' => 'cid-test',
            'boxnow.test.client_secret' => 'secret-test',
            'boxnow.production.api_host' => 'api-production.boxnow.gr',
            'boxnow.production.partner_id' => 'P-PROD',
            'boxnow.production.client_id' => 'cid-prod',
            'boxnow.production.client_secret' => 'secret-prod',
        ], save: true)->assertHasNoFormErrors();

        $this->assertSame('1', $this->stored('couriers.boxnow.enabled'));
        $this->assertNotNull($this->stored('couriers.boxnow.enabled_at'), 'the stale-sync clock starts when it is switched on');
        $this->assertSame('production', $this->stored('couriers.boxnow.environment'));
        $this->assertSame('1', $this->stored('couriers.boxnow.map_enabled'));

        $this->assertSame('api-stage.boxnow.bg', $this->stored('couriers.boxnow.test.api_host'));
        $this->assertSame('cid-test', $this->stored('couriers.boxnow.test.client_id'));
        $this->assertSame('P-TEST', $this->stored('couriers.boxnow.test.partner_id'));
        $this->assertSame('cid-prod', $this->stored('couriers.boxnow.production.client_id'));
        $this->assertSame('P-PROD', $this->stored('couriers.boxnow.production.partner_id'));

        // Encrypted, each under its own environment's key.
        foreach (['test' => 'secret-test', 'production' => 'secret-prod'] as $env => $plain) {
            $stored = (string) $this->stored("couriers.boxnow.{$env}.client_secret");
            $this->assertNotSame($plain, $stored);
            $this->assertStringNotContainsString($plain, $stored);
            $this->assertSame($plain, Crypt::decryptString($stored));
        }

        // The environment decides which set is used: production is current, so the credentials are production's.
        $descriptor = new BoxNowDescriptor;
        $credentials = app(CourierSettings::class)->credentials($descriptor);
        $this->assertSame(CourierEnvironment::PRODUCTION, $credentials->environment());
        $this->assertSame('cid-prod', $credentials->value('client_id'));
        $this->assertSame('secret-prod', $credentials->value('client_secret'));
        $this->assertSame('api-production.boxnow.gr', $credentials->value('api_host'));

        $this->fill(Livewire::test(CouriersSettings::class), ['boxnow.environment' => 'test'], save: true);
        $credentials = app(CourierSettings::class)->credentials($descriptor);
        $this->assertSame('cid-test', $credentials->value('client_id'));
        $this->assertSame('secret-test', $credentials->value('client_secret'));
    }

    public function test_an_api_host_outside_the_boxnow_domains_is_refused_and_nothing_is_stored(): void
    {
        $this->actAsPanelAdministrator();

        foreach (['evil.example.com', 'https://api.boxnow.bg', 'api.boxnow.bg/x', 'boxnow.bg.evil.com', '10.0.0.1'] as $bad) {
            $this->fill(Livewire::test(CouriersSettings::class), ['boxnow.enabled' => true, 'boxnow.test.api_host' => $bad], save: true)

                ->assertHasFormErrors(['boxnow.test.api_host']);
        }

        $this->assertNull($this->stored('couriers.boxnow.test.api_host'));
        $this->assertNull($this->stored('couriers.boxnow.enabled'));
    }

    public function test_an_empty_secret_keeps_the_stored_one_and_the_state_is_shown(): void
    {
        $this->actAsPanelAdministrator();
        $this->enableBoxNow();
        $before = $this->stored('couriers.boxnow.test.client_secret');

        $component = Livewire::test(CouriersSettings::class);
        $this->assertNull($component->get('data.boxnow.test.client_secret'), 'never filled back');
        $component->assertSee(__('couriers.secret.help_saved'))->assertSee(__('couriers.secret.help_none'));

        $component->call('save')->assertHasNoFormErrors();

        $this->assertSame($before, $this->stored('couriers.boxnow.test.client_secret'));
    }

    public function test_an_unreadable_secret_is_reported_and_replaced_by_typing_again(): void
    {
        $this->actAsPanelAdministrator();
        $this->enableBoxNow();
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.client_secret', 'not-encrypted-with-this-key');

        $this->assertSame('unreadable', app(CourierSettings::class)->secretState('boxnow', (new BoxNowDescriptor)->fields()[3], CourierEnvironment::TEST));
        Livewire::test(CouriersSettings::class)->assertSee(__('couriers.secret.help_unreadable'));

        $this->fill(Livewire::test(CouriersSettings::class), ['boxnow.test.client_secret' => 'fresh'], save: true);

        $this->assertSame('fresh', Crypt::decryptString((string) $this->stored('couriers.boxnow.test.client_secret')));
    }

    // --- secrets stay secret -----------------------------------------------------------------------------------------

    public function test_a_secret_is_never_in_the_html_snapshot_state_journal_or_log(): void
    {
        $this->actAsPanelAdministrator();
        $this->app->make(SiteSettingsRepository::class)->set('admin.activity_log_enabled', '1');
        Log::spy();

        $component = $this->fill(Livewire::test(CouriersSettings::class), [
            'boxnow.enabled' => true,
            'boxnow.test.api_host' => self::FAKE_API_HOST,
            'boxnow.test.client_id' => 'cid',
            'boxnow.test.client_secret' => self::SECRET,
        ]);

        // Typed but not saved: a re-render must not carry it in the snapshot.
        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));

        // Typed again, and saved in the same request.
        $this->fill($component, ['boxnow.test.client_secret' => self::SECRET], save: true)->assertHasNoFormErrors();

        $this->assertStringNotContainsString(self::SECRET, $component->html());
        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));
        $this->assertNull($component->get('data.boxnow.test.client_secret'), 'the typed secret is dropped from the Livewire state');

        $this->assertNotSame(0, ActivityLogModel::count(), 'the change is journalled');

        foreach (ActivityLogModel::all() as $row) {
            $this->assertStringNotContainsString(self::SECRET, json_encode($row->getAttributes()));
        }

        $this->assertTrue(
            ActivityLogModel::where('field', 'couriers.boxnow.test.client_secret')->where('new_value', 'changed')->whereNull('old_value')->exists(),
            'the journal records "changed", never a value',
        );
        $this->assertTrue(
            ActivityLogModel::where('field', 'couriers.boxnow.test.client_id')->where('new_value', 'changed')->exists(),
            'a credential field is journalled as "changed" too',
        );
        $this->assertTrue(
            ActivityLogModel::where('field', 'couriers.boxnow.enabled')->where('new_value', '1')->exists(),
            'a switch is journalled with its value',
        );

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        $this->assertStringNotContainsString(self::SECRET, (string) @file_get_contents(storage_path('logs/laravel.log')));

        $component->call('$refresh');
        $this->assertStringNotContainsString(self::SECRET, $component->html());
        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));
    }

    public function test_a_typed_secret_is_not_left_in_the_snapshot_even_when_validation_fails(): void
    {
        $this->actAsPanelAdministrator();

        $component = $this->fill(Livewire::test(CouriersSettings::class), [
            'boxnow.test.api_host' => 'evil.example.com',
            'boxnow.test.client_secret' => self::SECRET,
        ], save: true)->assertHasFormErrors(['boxnow.test.api_host']);

        $this->assertStringNotContainsString(self::SECRET, $this->snapshotJson($component));
        $this->assertStringNotContainsString(self::SECRET, $component->html());
        $this->assertNull($this->stored('couriers.boxnow.test.client_secret'));
    }

    // --- «Провери връзката» ------------------------------------------------------------------------------------------

    public function test_the_connection_test_shows_ok(): void
    {
        $this->actAsPanelAdministrator();
        $this->enableBoxNow();
        $http = (new FakeCourierHttp)->answer(new CourierResponse(200, '{"access_token":"TOKEN-MUST-NOT-SHOW","expires_in":3600}'));
        $this->app->instance(CourierHttp::class, $http);

        $component = Livewire::test(CouriersSettings::class)->call('checkConnection', 'boxnow');

        $component->assertSee(__('couriers.test.ok'));
        $this->assertSame(['ok' => true, 'code' => null], $component->get('connection.boxnow'));
        $this->assertStringNotContainsString('TOKEN-MUST-NOT-SHOW', $component->html());
        $this->assertStringNotContainsString(self::FAKE_SECRET, $component->html());
        $this->assertStringNotContainsString(self::FAKE_SECRET, $this->snapshotJson($component));
        $this->assertSame(self::FAKE_API_HOST, $http->calls[0]['host']);
    }

    public function test_a_failed_connection_test_shows_a_sanitised_code_and_never_the_body(): void
    {
        $this->actAsPanelAdministrator();
        $this->enableBoxNow();
        $this->app->instance(CourierHttp::class, (new FakeCourierHttp)->answer(new CourierResponse(401, '{"error":"LEAKED-UPSTREAM-BODY secret=abc"}')));

        $component = Livewire::test(CouriersSettings::class)->call('checkConnection', 'boxnow');

        $this->assertSame(['ok' => false, 'code' => 'auth_failed'], $component->get('connection.boxnow'));
        $component->assertSee('auth_failed')->assertSee(__('couriers.errors.auth_failed'));
        $this->assertStringNotContainsString('LEAKED-UPSTREAM-BODY', $component->html());
        $this->assertStringNotContainsString('LEAKED-UPSTREAM-BODY', $this->snapshotJson($component));
    }

    public function test_a_speedy_error_code_is_shown_next_to_the_closed_code_and_its_text_never(): void
    {
        $this->actAsPanelAdministrator();
        $this->enableCourier('speedy', 'fake-speedy-user-for-tests');
        $this->app->instance(CourierHttp::class, (new FakeCourierHttp)->answer(new CourierResponse(200, '{"error":{"code":7,"id":"X1","message":"LEAKED-TEXT fake-speedy-user-for-tests","context":"LEAKED-CONTEXT"}}')));

        $component = Livewire::test(CouriersSettings::class)->call('checkConnection', 'speedy');

        $this->assertSame(['ok' => false, 'code' => 'courier_error:7'], $component->get('connection.speedy'));
        $component->assertSee(__('couriers.errors.courier_error_code', ['courier' => CourierDirectory::label('speedy'), 'code' => 7]));
        $component->assertSee('courier_error:7');

        foreach (['LEAKED-TEXT', 'LEAKED-CONTEXT'] as $text) {
            $this->assertStringNotContainsString($text, $component->html());
            $this->assertStringNotContainsString($text, $this->snapshotJson($component));
        }

        // the same on the last run of the sync, and a code of 1 is still an authentication failure
        DB::table('courier_sync_runs')->insert(['carrier_code' => 'speedy', 'trigger' => 'manual', 'started_at' => now(), 'finished_at' => now(), 'outcome' => 'failed', 'error_code' => 'courier_error:7']);
        Livewire::test(CouriersSettings::class)->assertSee(__('couriers.errors.courier_error_code', ['courier' => CourierDirectory::label('speedy'), 'code' => 7]));

        $this->app->instance(CourierHttp::class, (new FakeCourierHttp)->answer(new CourierResponse(200, '{"error":{"code":1,"message":"x"}}')));
        $this->assertSame(['ok' => false, 'code' => 'auth_failed'], Livewire::test(CouriersSettings::class)->call('checkConnection', 'speedy')->get('connection.speedy'));
    }

    public function test_the_connection_test_of_a_disabled_courier_or_with_no_credentials_makes_no_call(): void
    {
        $this->actAsPanelAdministrator();
        $http = new FakeCourierHttp;
        $this->app->instance(CourierHttp::class, $http);

        $component = Livewire::test(CouriersSettings::class)->call('checkConnection', 'boxnow');
        $this->assertSame('not_enabled', $component->get('connection.boxnow.code'));

        // The API host is pre-filled (api-stage.boxnow.bg for the test environment), so what is missing is the credentials.
        $this->enableBoxNow(withCredentials: false);
        $component = Livewire::test(CouriersSettings::class)->call('checkConnection', 'boxnow');
        $this->assertSame('not_configured', $component->get('connection.boxnow.code'));

        // A stored host that is not on the BOX NOW domains is dropped, and is NOT replaced by the default: nothing is guessed.
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.api_host', 'evil.example.com');
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.client_id', 'fake-id');
        app(SiteSettingsRepository::class)->set('couriers.boxnow.test.client_secret', Crypt::encryptString('fake-secret'));
        $component = Livewire::test(CouriersSettings::class)->call('checkConnection', 'boxnow');
        $this->assertSame('api_host_missing', $component->get('connection.boxnow.code'));

        $this->assertSame([], $http->calls);
    }

    public function test_an_unknown_courier_is_a_404_and_checks_are_rate_limited(): void
    {
        $this->actAsPanelAdministrator();
        $this->enableBoxNow();
        $http = new FakeCourierHttp;
        foreach (range(1, 10) as $i) {
            $http->answer(new CourierResponse(200, '{"access_token":"t"}'));
        }
        $this->app->instance(CourierHttp::class, $http);

        Livewire::test(CouriersSettings::class)->call('checkConnection', 'nobody')->assertNotFound();

        $component = Livewire::test(CouriersSettings::class);

        foreach (range(1, 11) as $i) {
            $component->call('checkConnection', 'boxnow');
        }

        $this->assertSame('too_many_checks', $component->get('connection.boxnow.code'));
    }

    // --- «Обнови сега» -----------------------------------------------------------------------------------------------

    public function test_update_now_queues_the_sync_once_per_five_minutes(): void
    {
        Queue::fake();
        $this->actAsPanelAdministrator();
        $this->enableBoxNow();

        $component = Livewire::test(CouriersSettings::class)->call('syncNow', 'boxnow');
        Queue::assertPushed(SyncCourierPointsJob::class, fn (SyncCourierPointsJob $job): bool => $job->carrierCode === 'boxnow' && $job->trigger === 'manual');

        $component->call('syncNow', 'boxnow');
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);

        Cache::forget('couriers:sync-now:boxnow');
        $component->call('syncNow', 'boxnow');
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 2);
    }

    public function test_update_now_is_refused_for_a_disabled_courier(): void
    {
        Queue::fake();
        $this->actAsPanelAdministrator();

        Livewire::test(CouriersSettings::class)->call('syncNow', 'boxnow');
        Queue::assertNothingPushed();

        Livewire::test(CouriersSettings::class)->call('syncNow', 'nobody');
        Queue::assertNothingPushed();
    }

    public function test_update_now_needs_shipping_manage_on_top_of_settings_manage(): void
    {
        Queue::fake();
        $this->enableBoxNow();
        $this->actAsCustomRole([Permission::SETTINGS_MANAGE]);

        Livewire::test(CouriersSettings::class)->assertSuccessful()->call('syncNow', 'boxnow')->assertForbidden();
        Queue::assertNothingPushed();

        $this->actAsCustomRole([Permission::SETTINGS_MANAGE, Permission::SHIPPING_MANAGE]);
        Livewire::test(CouriersSettings::class)->call('syncNow', 'boxnow');
        Queue::assertPushedTimes(SyncCourierPointsJob::class, 1);
    }
}
