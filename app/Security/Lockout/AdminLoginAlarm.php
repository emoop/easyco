<?php

namespace App\Security\Lockout;

use App\Mail\MailConfigurator;
use App\Mail\MailHeader;
use App\Mail\RenderedMail;
use App\Mail\RenderedMailable;
use App\Settings\Contracts\SiteSettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * P8d: the account-wide ALARM (never a lock: the owner's IP is dynamic, so an account-wide lock would let anyone lock him out). `alarm.failures` (20) failed submits for
 * one account from ANY IP inside `alarm.window` (1 h) → ONE `warning` log line and ONE mail to the shop owner, at most one mail per account per `alarm.mail_every` (1 h).
 * It works in both security modes and never blocks anything. The owner's address is the shop's «reply-to» (the contact address), else the transactional sender.
 */
final class AdminLoginAlarm
{
    public function failure(string $email): void
    {
        $hash = AdminLoginLockout::hash($email);
        $counter = 'security.alarm.count.'.$hash;
        $window = (int) config('security.alarm.window', 3600);

        // a fixed window that starts with the first failure
        Cache::add($counter, 0, $window);
        $count = (int) Cache::increment($counter);

        if ($count < (int) config('security.alarm.failures', 20)) {
            return;
        }

        // ONE alarm per account per hour, whatever the count does afterwards
        if (! Cache::add('security.alarm.sent.'.$hash, 1, (int) config('security.alarm.mail_every', 3600))) {
            return;
        }

        // a mail ONLY for an email that belongs to a staff account (one query, on this path only) and at most `max_mails_per_hour` per hour in total; the rest is only logged
        $mail = $this->isStaff($email) && $this->underTheCap();

        Log::warning('Many failed admin logins for one account.', ['email_hash' => $hash, 'failures' => $count, 'window_seconds' => $window, 'mailed' => $mail]);

        $mail && $this->mail($hash, $count);
    }

    private function isStaff(string $email): bool
    {
        try {
            return app(\EasyCo\Staff\Contracts\StaffRepository::class)->findByEmail(AdminLoginLockout::normalise($email)) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function underTheCap(): bool
    {
        Cache::add('security.alarm.mails.hour', 0, 3600);

        return (int) Cache::increment('security.alarm.mails.hour') <= (int) config('security.alarm.max_mails_per_hour', 3);
    }

    private function mail(string $hash, int $count): void
    {
        try {
            $settings = app(SiteSettingsRepository::class);
            $configurator = app(MailConfigurator::class);
            $sender = $configurator->apply('transactional');
            $to = MailHeader::address((string) $settings->get('mail.reply_to')) ?? $sender->fromAddress;

            $subject = MailHeader::clean(__('security.alarm.subject', ['shop' => (string) config('app.name')]), 180);
            $body = __('security.alarm.body', ['count' => $count, 'minutes' => intdiv((int) config('security.alarm.window', 3600), 60), 'ref' => substr($hash, 0, 12)]);

            Mail::mailer($sender->mailer)->to($to)->send(new RenderedMailable(new RenderedMail($subject, '<p>'.e($body).'</p>', $body), $sender));
        } catch (Throwable $e) {
            Log::error('The admin-login alarm mail could not be sent.', ['exception' => $e::class]);
        }
    }
}
