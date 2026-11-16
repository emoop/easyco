<?php

namespace App\Security;

use Illuminate\Support\Facades\Log;

/**
 * P8d: the ONE switch of every new blocking rule. `config('security.mode')`: `block` blocks, anything else (including a typo) is `log`: a rule that logs by mistake
 * is safe, a rule that blocks by mistake is not.
 *
 * Every rule asks `SecurityMode::decide($rule, $context)`: in `log` mode it writes one `warning` line («would block») and answers false; in `block` mode it answers true.
 */
final class SecurityMode
{
    public static function blocks(): bool
    {
        return config('security.mode') === 'block';
    }

    /** @param  array<string, scalar|null>  $context  never a secret, never a raw email */
    public static function decide(string $rule, array $context = []): bool
    {
        if (self::blocks()) {
            return true;
        }

        Log::warning("Security rule «{$rule}» would block (SECURITY_MODE=log).", $context);

        return false;
    }
}
