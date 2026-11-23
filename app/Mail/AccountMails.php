<?php

namespace App\Mail;

use App\Settings\StoreLocale;
use EasyCo\Account\Security\SignedLink;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ACC-1: the customer-account mails. Builds the absolute storefront links (the pages are ACC-3's: /account/verify, /account/reset, /account/email/confirm, /account/login,
 * /account/forgot) and queues SendAccountMailJob. It never throws into a request (a queue that is down must not turn a generic 202 into a 500, nor tell the caller
 * anything): a failure is logged with the template name only.
 */
class AccountMails
{
    public const VERIFY_TTL = 86400;

    public const EMAIL_CONFIRM_TTL = 86400;

    public function __construct(
        private readonly StoreLocale $locale,
    ) {
    }

    public function verification(string $accountId, string $email): void
    {
        $this->queue(MailTemplates::ACCOUNT_VERIFY, $email, ['action_link' => ['url' => $this->url('/account/verify', SignedLink::params('verify', $accountId, self::VERIFY_TTL)), 'label' => 'verify']]);
    }

    public function alreadyRegistered(string $email): void
    {
        $this->queue(MailTemplates::ACCOUNT_ALREADY_REGISTERED, $email, [
            'login_link' => ['url' => $this->url('/account/login'), 'label' => 'login'],
            'reset_link' => ['url' => $this->url('/account/forgot'), 'label' => 'reset'],
        ]);
    }

    public function passwordReset(string $email, string $token): void
    {
        $this->queue(MailTemplates::ACCOUNT_PASSWORD_RESET, $email, ['action_link' => ['url' => $this->url('/account/reset', ['token' => $token, 'email' => $email]), 'label' => 'reset']]);
    }

    public function passwordChanged(string $email): void
    {
        $this->queue(MailTemplates::ACCOUNT_PASSWORD_CHANGED, $email, ['reset_link' => ['url' => $this->url('/account/forgot'), 'label' => 'reset']]);
    }

    public function emailConfirmation(string $accountId, string $newEmail, string $token): void
    {
        $this->queue(MailTemplates::ACCOUNT_EMAIL_CONFIRM, $newEmail, ['action_link' => ['url' => $this->url('/account/email/confirm', SignedLink::params('email', $accountId, self::EMAIL_CONFIRM_TTL, $token)), 'label' => 'email_confirm']]);
    }

    public function emailNotice(string $oldEmail): void
    {
        $this->queue(MailTemplates::ACCOUNT_EMAIL_NOTICE, $oldEmail, []);
    }

    /** ACC-4a: the confirmation that the person was forgotten (no link: nothing to click). */
    public function privacyErased(string $email): void
    {
        $this->queue(MailTemplates::PRIVACY_ERASED, $email, []);
    }

    /**
     * ACC-4d: asks the person to confirm that a privacy request is theirs. `$link` is the absolute storefront url with the signed parameters (see privacyConfirmUrl);
     * `$kind` is `export` or `erase`; the request type is written in the mail's own language. No data of the person but the address.
     * `$locale` is the language of the mail (ACC-4e-1: the caller passes the SHOP's default locale, never the admin's own); null = the store locale.
     *
     * @return bool false when the mail could not be queued (the caller must say so)
     */
    public function privacyConfirmation(string $email, string $kind, string $link, int $validDays, ?string $locale = null): bool
    {
        $locale ??= $this->locale->current();

        return $this->queue(MailTemplates::PRIVACY_CONFIRM, $email, ['action_link' => ['url' => $link, 'label' => 'privacy_confirm']], [
            'request_type' => (string) __('mail.privacy_confirm.'.$kind, [], $locale),
            'valid_days' => (string) $validDays,
        ], $locale);
    }

    /**
     * The absolute url of the confirmation page. It lives under /account/..., a prefix the page cache (Varnish) never keeps.
     *
     * @param  array<string, scalar>  $query
     */
    public function privacyConfirmUrl(array $query): string
    {
        return $this->url('/account/privacy/confirm', $query);
    }

    /** @param  array<string, scalar>  $query */
    private function url(string $path, array $query = []): string
    {
        return rtrim((string) config('app.url'), '/').$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param  array<string, array{url: string, label: string}>  $links
     * @param  array<string, string>  $scalars
     */
    private function queue(string $template, string $email, array $links, array $scalars = [], ?string $locale = null): bool
    {
        try {
            SendAccountMailJob::dispatch($template, $email, $locale ?? $this->locale->current(), $links, 'mail-transactional', $scalars);

            return true;
        } catch (Throwable $e) {
            Log::error('mail.account_dispatch_failed', ['template' => $template, 'exception' => $e::class]);

            return false;
        }
    }
}
