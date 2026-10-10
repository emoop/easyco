<?php

namespace App\Mail\Transports;

/**
 * The registry of selectable transports (mail-design.md §2). `env` is not an entry: it means "no transport
 * chosen here, use the .env mailer" and is represented by an empty `mail.transport`.
 */
final class MailTransports
{
    public const ENV = 'env';

    /** @var array<string, MailTransport> */
    private array $transports = [];

    public function __construct()
    {
        // An API provider is one more line here.
        foreach ([new SmtpTransport()] as $transport) {
            $this->transports[$transport->key()] = $transport;
        }
    }

    /** @return array<string, MailTransport> */
    public function all(): array
    {
        return $this->transports;
    }

    public function find(?string $key): ?MailTransport
    {
        return $key === null ? null : ($this->transports[$key] ?? null);
    }
}
