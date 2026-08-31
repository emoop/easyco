<?php

namespace EasyCo\Account\Security;

use EasyCo\Account\Contracts\PasswordHasher;
use Illuminate\Support\Facades\Hash;

final class LaravelPasswordHasher implements PasswordHasher
{
    public function hash(string $plainPassword): string
    {
        return Hash::make($plainPassword);
    }

    public function verify(string $plainPassword, string $hash): bool
    {
        return Hash::check($plainPassword, $hash);
    }
}
