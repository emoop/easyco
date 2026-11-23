<?php

namespace Tests\Feature\Privacy;

use App\Console\Commands\PrunePrivacyExports;
use App\Privacy\BuildPrivacyExportJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyExportRequester;
use App\Privacy\PrivacyNotConfigured;
use App\Privacy\StaffActor;
use App\Settings\Contracts\SiteSettingsRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PlacesRegionOrders;
use Tests\TestCase;
use ZipArchive;

/**
 * ACC-4c (acc-4-gdpr-design.md §3, §4, §5): the requester, the build job and its ZIP, the failure path, the daily prune and the thin `privacy:export` command.
 */
class PrivacyExportJobTest extends TestCase
{
    use PlacesRegionOrders;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRegionOrders();
        app(SiteSettingsRepository::class)->set('site.country', 'BG');
        Storage::disk('local')->deleteDirectory(BuildPrivacyExportJob::DIRECTORY);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory(BuildPrivacyExportJob::DIRECTORY);
        parent::tearDown();
    }

    private function request(string $email = 'me@example.com'): PrivacyRequestModel
    {
        return app(PrivacyExportRequester::class)->request($email, new StaffActor(7, 'Admin Tester'), queue: false);
    }

    private function build(PrivacyRequestModel $request): void
    {
        app()->call([new BuildPrivacyExportJob((int) $request->getKey()), 'handle']);
    }

    /** a finished export row with a real file on the private disk */
    private function doneExport(string $expiresAt, ?string $path = null): PrivacyRequestModel
    {
        $request = $this->request();
        $path ??= BuildPrivacyExportJob::DIRECTORY.'/'.\Illuminate\Support\Str::uuid().'.zip';
        Storage::disk('local')->put($path, 'zip-bytes');
        $request->update(['status' => 'done', 'export_path' => $path, 'export_expires_at' => $expiresAt, 'completed_at' => now()]);

        return $request->fresh();
    }

    // ---- requester ---------------------------------------------------------------------------------------------------------------

    public function test_the_requester_writes_the_row_with_the_right_fields_and_queues_the_job_on_default(): void
    {
        Bus::fake();

        $request = app(PrivacyExportRequester::class)->request(' Me@Example.com ', new StaffActor(7, 'Admin Tester'));

        $this->assertSame('export', $request->kind);
        $this->assertSame('admin', $request->source);
        $this->assertSame('processing', $request->status);
        $this->assertSame('me@example.com', $request->email);
        $this->assertSame((string) app(\App\Privacy\PrivacyHash::class)->email('me@example.com'), $request->email_hash);
        $this->assertSame(7, (int) $request->staff_id);
        $this->assertSame('Admin Tester', $request->staff_name);
        Bus::assertDispatched(BuildPrivacyExportJob::class, fn (BuildPrivacyExportJob $job): bool => $job->requestId === (int) $request->getKey() && $job->queue === 'default');
    }

    public function test_the_requester_refuses_without_the_hash_key_in_production_and_writes_nothing(): void
    {
        Bus::fake();
        config(['privacy.hash_key' => null]);
        $this->app['env'] = 'production';

        try {
            app(PrivacyExportRequester::class)->request('me@example.com', null);
            $this->fail('expected PrivacyNotConfigured');
        } catch (PrivacyNotConfigured $e) {
            $this->assertStringContainsString('PRIVACY_HASH_KEY', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(0, PrivacyRequestModel::query()->count());
        Bus::assertNothingDispatched();
    }

    // ---- the job -----------------------------------------------------------------------------------------------------------------

    public function test_the_job_makes_a_zip_with_exactly_two_files_on_the_private_disk_and_closes_the_row(): void
    {
        $this->checkout($this->streetFields('BG'), ['email' => 'me@example.com'])->assertStatus(201);
        $this->travelTo(Carbon::parse('2026-11-23 10:00:00'));
        $request = $this->request();

        $this->build($request);

        $request = $request->fresh();
        $this->assertSame('done', $request->status);
        $this->assertMatchesRegularExpression('#^privacy-exports/[0-9a-f-]{36}\.zip$#', $request->export_path);
        $this->assertTrue(now()->copy()->addDays(7)->equalTo($request->export_expires_at));
        $this->assertTrue(now()->equalTo($request->completed_at));
        $this->assertTrue(now()->copy()->addDays(30)->equalTo($request->clear_until), 'the clear email ages out like an erase');

        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($request->export_path));
        $this->assertStringStartsNotWith(public_path(), $disk->path($request->export_path), 'never under public/');
        $realRoot = str_replace('\\', '/', (string) (include config_path('filesystems.php'))['disks']['local']['root']);
        $this->assertStringEndsWith('app/private', $realRoot, 'the configured local disk is the private one (the test harness fakes it)');
        $this->assertStringStartsNotWith(str_replace('\\', '/', public_path()), $realRoot);

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($disk->path($request->export_path)));
        $this->assertSame(2, $zip->numFiles);
        $names = [$zip->getNameIndex(0), $zip->getNameIndex(1)];
        sort($names);
        $this->assertSame(['data.html', 'data.json'], $names);
        $this->assertStringContainsString('me@example.com', (string) $zip->getFromName('data.json'));
        $this->assertNotFalse(json_decode((string) $zip->getFromName('data.json'), true));
        $zip->close();

        $this->assertFileDoesNotExist(sys_get_temp_dir().DIRECTORY_SEPARATOR.'privacy-export-'.$request->getKey().'.zip', 'the temp file is gone');
    }

    public function test_the_clear_view_command_nulls_the_email_of_a_finished_export_after_30_days(): void
    {
        $request = $this->request();
        $this->build($request);

        $this->travelTo(now()->addDays(31));
        $this->artisan('privacy:expire-clear-view')->assertSuccessful();

        $this->assertNull($request->fresh()->email, 'the existing command does not filter on kind = erase');
        $this->assertNotNull($request->fresh()->email_hash);
    }

    public function test_a_failing_build_marks_the_row_failed_stores_nothing_and_leaves_no_temp_file(): void
    {
        $request = $this->request();
        // the ZIP is made (so a temp file exists), then the private disk refuses to store it
        $disk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('writeStream')->once()->andReturnUsing(function () use ($request): never {
            $this->assertFileExists(sys_get_temp_dir().DIRECTORY_SEPARATOR.'privacy-export-'.$request->getKey().'.zip', 'the temp ZIP exists when the disk is asked to store it');
            throw new \RuntimeException('boom');
        });
        $disk->shouldReceive('delete', 'deleteDirectory')->zeroOrMoreTimes();
        Storage::set('local', $disk);

        try {
            $this->build($request);
            $this->fail('the failure must propagate to the worker');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $request = $request->fresh();
        $this->assertSame('failed', $request->status);
        $this->assertNull($request->export_path);
        $this->assertNotNull($request->clear_until, 'a failed row\'s clear email still ages out');
        $this->assertFileDoesNotExist(sys_get_temp_dir().DIRECTORY_SEPARATOR.'privacy-export-'.$request->getKey().'.zip', 'the temp file is removed');
    }

    public function test_the_workers_failed_hook_marks_the_row_failed_and_removes_a_leftover_temp_file(): void
    {
        $request = $this->request();
        $temp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'privacy-export-'.$request->getKey().'.zip';
        file_put_contents($temp, 'half a zip');

        (new BuildPrivacyExportJob((int) $request->getKey()))->failed(new \RuntimeException('timeout'));

        $this->assertSame('failed', $request->fresh()->status);
        $this->assertFileDoesNotExist($temp);
    }

    public function test_a_request_that_is_not_waiting_is_not_built_twice(): void
    {
        $request = $this->request();
        $this->build($request);
        $path = $request->fresh()->export_path;

        $this->build($request);

        $this->assertSame($path, $request->fresh()->export_path);
        $this->assertCount(1, Storage::disk('local')->files(BuildPrivacyExportJob::DIRECTORY));
    }

    public function test_the_job_is_on_default_with_one_try_and_a_timeout_below_the_worker_timeout(): void
    {
        $job = new BuildPrivacyExportJob(1);

        $this->assertSame('default', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertLessThan(90, $job->timeout, 'below QUEUE_WORKER_TIMEOUT (90 s in compose)');
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
    }

    // ---- prune -------------------------------------------------------------------------------------------------------------------

    public function test_prune_deletes_an_expired_file_and_keeps_a_valid_one_and_a_second_run_changes_nothing(): void
    {
        $expired = $this->doneExport(now()->subMinute()->toDateTimeString());
        $valid = $this->doneExport(now()->addDay()->toDateTimeString());
        $expiredPath = $expired->export_path;

        $this->artisan('privacy:prune-exports')->expectsOutputToContain('Expired 1 export(s); removed 0 orphan file(s).')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($expiredPath));
        $this->assertNull($expired->fresh()->export_path);
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertTrue(Storage::disk('local')->exists($valid->export_path));
        $this->assertSame('done', $valid->fresh()->status);

        $before = [PrivacyRequestModel::query()->orderBy('id')->get()->toJson(), Storage::disk('local')->files(BuildPrivacyExportJob::DIRECTORY)];
        $this->artisan('privacy:prune-exports')->expectsOutputToContain('Expired 0 export(s); removed 0 orphan file(s).')->assertSuccessful();
        $this->assertSame($before, [PrivacyRequestModel::query()->orderBy('id')->get()->toJson(), Storage::disk('local')->files(BuildPrivacyExportJob::DIRECTORY)]);
    }

    public function test_prune_removes_an_orphan_older_than_eight_days_and_keeps_a_young_one_and_a_referenced_one(): void
    {
        $disk = Storage::disk('local');
        $referenced = $this->doneExport(now()->addDay()->toDateTimeString());
        $disk->put(BuildPrivacyExportJob::DIRECTORY.'/old-orphan.zip', 'x');
        $disk->put(BuildPrivacyExportJob::DIRECTORY.'/young-orphan.zip', 'x');
        touch($disk->path(BuildPrivacyExportJob::DIRECTORY.'/old-orphan.zip'), now()->subDays(PrunePrivacyExports::ORPHAN_DAYS)->subHour()->getTimestamp());
        touch($disk->path($referenced->export_path), now()->subDays(20)->getTimestamp());

        $this->artisan('privacy:prune-exports')->expectsOutputToContain('removed 1 orphan file(s)')->assertSuccessful();

        $this->assertFalse($disk->exists(BuildPrivacyExportJob::DIRECTORY.'/old-orphan.zip'));
        $this->assertTrue($disk->exists(BuildPrivacyExportJob::DIRECTORY.'/young-orphan.zip'));
        $this->assertTrue($disk->exists($referenced->export_path), 'a file a request points to is never an orphan, however old');
    }

    public function test_prune_is_in_the_schedule_daily(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'privacy:prune-exports'));

        $this->assertNotNull($event, 'privacy:prune-exports must be scheduled');
        $this->assertSame('20 3 * * *', $event->expression);
    }

    // ---- the command -------------------------------------------------------------------------------------------------------------

    public function test_the_command_queues_without_sync_and_builds_with_sync_and_prints_id_and_status(): void
    {
        Bus::fake();
        $this->artisan('privacy:export', ['email' => 'me@example.com'])->expectsOutputToContain('Status: processing')->assertSuccessful();
        Bus::assertDispatched(BuildPrivacyExportJob::class);
        Bus::fake([]);

        PrivacyRequestModel::query()->delete();
        app()->forgetInstance(\Illuminate\Contracts\Bus\Dispatcher::class);
        $this->artisan('privacy:export', ['email' => 'me@example.com', '--sync' => true])->expectsOutputToContain('Status: done')->assertSuccessful();

        $row = PrivacyRequestModel::query()->firstOrFail();
        $this->assertSame('done', $row->status);
        $this->assertNull($row->staff_id);
    }

    public function test_the_command_refuses_a_blank_email(): void
    {
        $this->artisan('privacy:export', ['email' => '  '])->assertFailed();
        $this->assertSame(0, DB::table('privacy_requests')->count());
    }
}
