<?php

namespace Tests\Feature\Mail;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** A test transport: fails the first $failures sends with the given SMTP-style code (0 = connection problem), then records. */
final class FlakyTransport extends AbstractTransport
{
    /** @var list<SentMessage> */
    public static array $sent = [];

    public static int $attempts = 0;

    public static int $failures = 0;

    public static int $code = 0;

    public static function reset(int $failures = 0, int $code = 0): void
    {
        self::$sent = [];
        self::$attempts = 0;
        self::$failures = $failures;
        self::$code = $code;
    }

    protected function doSend(SentMessage $message): void
    {
        self::$attempts++;

        if (self::$failures > 0) {
            self::$failures--;

            // The text carries an address and a URL with credentials: neither may reach mail_log.last_error.
            throw new TransportException('Expected response code "250" but got "'.self::$code.'" for buyer@example.com via smtp://user:secret@mail.example.com', self::$code);
        }

        self::$sent[] = $message;
    }

    public function __toString(): string
    {
        return 'flaky://test';
    }
}
