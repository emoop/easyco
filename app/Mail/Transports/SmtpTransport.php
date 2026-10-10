<?php

namespace App\Mail\Transports;

use App\Mail\MailConfigurationException;
use Closure;

final class SmtpTransport implements MailTransport
{
    public const HOST = 'mail.smtp.host';

    public const PORT = 'mail.smtp.port';

    public const ENCRYPTION = 'mail.smtp.encryption';

    public const USERNAME = 'mail.smtp.username';

    public const PASSWORD = 'mail.smtp.password';

    public const HOST_PATTERN = '/^[A-Za-z0-9]([A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?$/';

    public function key(): string
    {
        return 'smtp';
    }

    public function label(): string
    {
        return 'transport.smtp';
    }

    public function fields(): array
    {
        return [
            new TransportField('smtp_host', self::HOST, TransportField::TEXT, 'smtp.host', required: true, rules: ['regex:'.self::HOST_PATTERN], max: 253),
            new TransportField('smtp_port', self::PORT, TransportField::NUMBER, 'smtp.port', required: true, default: '587', rules: ['integer'], min: 1, max: 65535),
            new TransportField('smtp_encryption', self::ENCRYPTION, TransportField::SELECT, 'smtp.encryption', required: true, default: 'tls', options: [
                'tls' => 'smtp.encryption_tls',
                'ssl' => 'smtp.encryption_ssl',
                'none' => 'smtp.encryption_none',
            ]),
            new TransportField('smtp_username', self::USERNAME, TransportField::TEXT, 'smtp.username', max: 255),
            new TransportField('smtp_password', self::PASSWORD, TransportField::SECRET, 'smtp.password', max: 500),
        ];
    }

    public function mailerConfig(Closure $value, Closure $secret): array
    {
        $host = $value(self::HOST) ?? '';

        if (preg_match(self::HOST_PATTERN, $host) !== 1) {
            throw new MailConfigurationException('The SMTP host is not valid.');
        }

        $port = $value(self::PORT) ?? '587';

        if (! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new MailConfigurationException('The SMTP port is not valid.');
        }

        $encryption = $value(self::ENCRYPTION) ?? 'tls';

        return [
            'transport' => 'smtp',
            'host' => $host,
            'port' => (int) $port,
            // "ssl" = implicit TLS (smtps); "tls" negotiates STARTTLS when the server offers it.
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'username' => $value(self::USERNAME),
            'password' => $secret(self::PASSWORD),
            'timeout' => 15,
        ];
    }
}
