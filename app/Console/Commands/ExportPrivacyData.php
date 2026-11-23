<?php

namespace App\Console\Commands;

use App\Privacy\BuildPrivacyExportJob;
use App\Privacy\Persistence\PrivacyRequestModel;
use App\Privacy\PrivacyExportRequester;
use App\Privacy\PrivacyNotConfigured;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan privacy:export {email} {--sync}` (ACC-4c): for ops, and for the owner to try the export on dev before the admin button exists (ACC-4e). A thin call of PrivacyExportRequester
 * (the same row, the same job). Without `--sync` the job is queued (queue `default`); with it the job runs here, in this process. Prints the request id and the final status. The ZIP is
 * downloaded from admin; this command never prints a link or the data.
 */
class ExportPrivacyData extends Command
{
    protected $signature = 'privacy:export {email : the email of the customer} {--sync : build the export now, in this process, instead of queueing it}';

    protected $description = 'Start a personal-data export (ZIP) for an email';

    public function handle(PrivacyExportRequester $requester): int
    {
        $sync = (bool) $this->option('sync');

        try {
            $request = $requester->request((string) $this->argument('email'), null, ! $sync);
        } catch (PrivacyNotConfigured|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Request id: '.$request->getKey());

        if ($sync) {
            try {
                app()->call([new BuildPrivacyExportJob((int) $request->getKey()), 'handle']);
            } catch (Throwable $e) {
                $this->error('The export failed: '.$e::class);
            }
        }

        $status = (string) PrivacyRequestModel::query()->whereKey($request->getKey())->value('status');
        $this->line('Status: '.$status);

        return $status === PrivacyRequestModel::STATUS_FAILED ? self::FAILURE : self::SUCCESS;
    }
}
