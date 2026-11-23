<?php

namespace App\Http\Controllers\Admin;

use App\Privacy\BuildPrivacyExportJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ACC-4c: the staff-only download of a personal-data export (acc-4-gdpr-design.md D2 revised, §7). Registered inside the admin panel's authenticated routes (login required) with
 * `staff.can:privacy_manage` (Administrator only by default). There is NO customer-facing link anywhere.
 *
 * An unknown uuid, a request that is not `done`, an expired one and a file that is gone are the SAME 404. The file is streamed as an attachment named `easyco-data-{Y-m-d}.zip` (no email in
 * the name: a downloads folder is not a safe place for one) with `Cache-Control: no-store, private`.
 */
final class PrivacyExportDownloadController
{
    public function __invoke(string $uuid): StreamedResponse
    {
        abort_unless(Str::isUuid($uuid), 404);

        $path = BuildPrivacyExportJob::DIRECTORY.'/'.strtolower($uuid).'.zip';
        $request = PrivacyRequestModel::query()
            ->where('kind', PrivacyRequestModel::KIND_EXPORT)
            ->where('status', PrivacyRequestModel::STATUS_DONE)
            ->where('export_path', $path)
            ->where('export_expires_at', '>', now())
            ->first();

        abort_if($request === null || ! Storage::disk('local')->exists($path), 404);

        // an audit line without any personal data: who took which request
        Log::info('privacy.export_downloaded', ['request_id' => $request->getKey(), 'staff_id' => auth('staff')->id()]);

        return Storage::disk('local')->download($path, 'easyco-data-'.now()->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
