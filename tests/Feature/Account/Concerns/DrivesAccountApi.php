<?php

namespace Tests\Feature\Account\Concerns;

use App\Mail\SendAccountMailJob;
use EasyCo\Account\Persistence\Eloquent\AccountModel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/**
 * ACC-1 test helpers: the account API driven over HTTP, the queued account mails read back from the (faked) queue, the links taken out of them.
 * Every test class using it calls driveAccountApi() in setUp().
 */
trait DrivesAccountApi
{
    protected string $strong = 'plum-cart-7731';

    protected function driveAccountApi(): void
    {
        // Sanctum's stateful pipeline only engages for a recognised Referer (see AccountRegistrationControllerTest)
        $this->withHeader('Referer', 'http://localhost/');
        // https: the mail's sanitizer keeps only https links; 1 ms: the Timebox is not what these tests are about
        config(['app.url' => 'https://shop.test', 'auth.account_timebox_duration' => 1000, 'security.mode' => 'block']);
        Queue::fake();
        app()->setLocale('en');
    }

    /** a verified account with a known password, made without the API */
    protected function verifiedAccount(string $email = 'user@example.com', ?string $password = null): AccountModel
    {
        $account = new AccountModel(['email' => $email, 'password' => Hash::make($password ?? $this->strong)]);
        $account->forceFill(['email_verified_at' => now()])->save();

        return $account;
    }

    protected function unverifiedAccount(string $email = 'user@example.com'): AccountModel
    {
        $account = new AccountModel(['email' => $email, 'password' => Hash::make($this->strong)]);
        $account->save();

        return $account;
    }

    /** @return list<SendAccountMailJob> */
    protected function mails(?string $template = null, ?string $to = null): array
    {
        return Queue::pushed(SendAccountMailJob::class)
            ->filter(fn (SendAccountMailJob $job): bool => ($template === null || $job->templateKey === $template) && ($to === null || $job->email === $to))
            ->values()
            ->all();
    }

    /** the query parameters of the link of a mail's block, as the storefront page would forward them */
    protected function linkParams(SendAccountMailJob $job, string $block = 'action_link'): array
    {
        parse_str((string) parse_url($job->links[$block]['url'], PHP_URL_QUERY), $query);

        return $query;
    }

    protected function login(string $email = 'user@example.com', ?string $password = null, array $extra = [])
    {
        return $this->postJson('/api/account/login', ['email' => $email, 'password' => $password ?? $this->strong] + $extra);
    }
}
