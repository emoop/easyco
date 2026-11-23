<?php

namespace Tests\Feature\Privacy;

use App\Privacy\BuildPrivacyExportJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyExportRequester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsShippingZones;
use Tests\TestCase;

/**
 * ACC-4c (acc-4-gdpr-design.md D2 revised, §7): the staff-only download. Login + `privacy_manage` (Administrator only); an unknown or expired export is a 404;
 * an attachment with no email in its name, `no-store`.
 */
class PrivacyExportDownloadTest extends TestCase
{
    use BuildsShippingZones;
    use RefreshDatabase;

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::disk('local')->deleteDirectory(BuildPrivacyExportJob::DIRECTORY);
        $this->uuid = (string) Str::uuid();
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory(BuildPrivacyExportJob::DIRECTORY);
        parent::tearDown();
    }

    private function export(string $status = 'done', ?string $expiresAt = null): PrivacyRequestModel
    {
        $path = BuildPrivacyExportJob::DIRECTORY.'/'.$this->uuid.'.zip';
        Storage::disk('local')->put($path, 'PK-fake-zip-bytes');

        $request = app(PrivacyExportRequester::class)->request('secret.person@example.com', null, queue: false);
        $request->update(['status' => $status, 'export_path' => $path, 'export_expires_at' => $expiresAt ?? now()->addDays(3)]);

        return $request;
    }

    private function url(?string $uuid = null): string
    {
        return '/admin/privacy/exports/'.($uuid ?? $this->uuid);
    }

    public function test_an_administrator_downloads_the_zip_as_an_attachment_with_no_email_in_the_name_and_no_store(): void
    {
        $this->export();
        $this->actingAsStaff('Administrator');

        $response = $this->get($this->url());

        $response->assertOk();
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment', $disposition);
        $this->assertStringContainsString('easyco-data-'.now()->format('Y-m-d').'.zip', $disposition);
        $this->assertStringNotContainsString('secret', $disposition);
        $this->assertStringNotContainsString('@', $disposition);
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertSame('PK-fake-zip-bytes', $response->streamedContent());
    }

    public function test_without_the_permission_it_is_403_even_for_an_unknown_uuid(): void
    {
        $this->export();

        foreach (['Manager', 'Product Entry'] as $role) {
            $this->actingAsStaff($role);
            $this->get($this->url())->assertForbidden();
            $this->get($this->url((string) Str::uuid()))->assertForbidden();
        }
    }

    public function test_not_logged_in_is_sent_to_the_admin_login(): void
    {
        $this->export();

        $this->get($this->url())->assertRedirect('/admin/login');
    }

    public function test_unknown_malformed_expired_and_not_done_are_all_404(): void
    {
        $this->actingAsStaff('Administrator');

        $this->get($this->url((string) Str::uuid()))->assertNotFound();
        $this->get($this->url('not-a-uuid'))->assertNotFound();

        $request = $this->export(expiresAt: now()->subSecond()->toDateTimeString());
        $this->get($this->url())->assertNotFound();

        $request->update(['export_expires_at' => now()->addDay(), 'status' => 'processing']);
        $this->get($this->url())->assertNotFound();

        $request->update(['status' => 'done']);
        $this->get($this->url())->assertOk();

        Storage::disk('local')->delete(BuildPrivacyExportJob::DIRECTORY.'/'.$this->uuid.'.zip');
        $this->get($this->url())->assertNotFound();
    }

    public function test_the_local_disk_is_not_web_reachable(): void
    {
        $this->export();

        $this->assertStringNotContainsString(str_replace('\\', '/', public_path()), str_replace('\\', '/', Storage::disk('local')->path(BuildPrivacyExportJob::DIRECTORY)));
        $this->get('/storage/'.BuildPrivacyExportJob::DIRECTORY.'/'.$this->uuid.'.zip')->assertStatus(403);
        $this->get('/privacy-exports/'.$this->uuid.'.zip')->assertNotFound();
    }
}
