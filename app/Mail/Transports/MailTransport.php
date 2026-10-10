<?php

namespace App\Mail\Transports;

use App\Mail\MailConfigurationException;
use Closure;

/**
 * A selectable way of sending mail (mail-design.md §2). The sending code never changes when one is added:
 * a transport only describes its form fields and turns the stored settings into a `mail.mailers.*` array.
 * An API provider would add its own class and one entry in MailTransports (plus its Mail::extend()).
 */
interface MailTransport
{
    /** The value stored in `mail.transport`. */
    public function key(): string;

    /** Lang key under `mail.` for the Select option. */
    public function label(): string;

    /** @return list<TransportField> */
    public function fields(): array;

    /**
     * @param Closure(string): ?string $value  a trimmed stored setting, null when empty
     * @param Closure(string): ?string $secret a stored secret DECRYPTED; called only here, at send time
     * @return array<string, mixed>
     *
     * @throws MailConfigurationException with a message that never contains a secret
     */
    public function mailerConfig(Closure $value, Closure $secret): array;
}
