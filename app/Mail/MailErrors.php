<?php

namespace App\Mail;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/** Permanent-versus-transient classification and the sanitised text stored in `mail_log.last_error` (mail-design.md §4, §10). */
final class MailErrors
{
    /**
     * Permanent: retrying cannot help (an SMTP 5xx answer, an API 4xx other than timeout/rate-limit, an
     * unusable recipient, a template that cannot be rendered). Everything else is transient and is retried.
     */
    public static function isPermanent(Throwable $e): bool
    {
        if ($e instanceof TemplateException || $e instanceof InvalidRecipientException) {
            return true;
        }

        if ($e instanceof HttpTransportException) {
            $status = $e->getResponse()->getStatusCode();

            return $status >= 400 && $status < 500 && $status !== 408 && $status !== 429;
        }

        if ($e instanceof TransportExceptionInterface) {
            $code = (int) $e->getCode();

            return $code >= 500 && $code <= 599;
        }

        return false;
    }

    /**
     * Class name plus a scrubbed message, at most 255 characters: URLs (they can carry credentials),
     * email addresses (recipient content) and control characters are removed. Never the stack trace.
     */
    public static function describe(Throwable $e): string
    {
        $message = (string) preg_replace('#[a-zA-Z][a-zA-Z0-9+.\-]*://\S+#', '[url]', $e->getMessage());
        $message = (string) preg_replace('/[^\s@<>]+@[^\s@<>]+/', '[email]', $message);
        $message = MailHeader::clean($message, 200);

        return mb_substr(class_basename($e).($message === '' ? '' : ': '.$message), 0, 255);
    }
}
