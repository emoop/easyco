<?php

namespace App\Mail;

/** The transport and sender a mail is sent with, resolved at the start of the job (mail-design.md §2, §3). */
final class MailerSettings
{
    public function __construct(
        public readonly string $mailer,
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly ?string $replyTo,
    ) {
    }
}
