<?php

namespace EasyCo\Account\Contracts;

/**
 * The infrastructure boundary for password hashing — see
 * account-domain-design.md §3. The Account domain class itself never
 * imports Illuminate\Support\Facades\Hash; only an implementation of
 * this contract does.
 *
 * Used by registration only (hash() a new plaintext password before
 * constructing an Account). Login does NOT go through verify() —
 * Auth::guard('customer')->attempt() uses Laravel's own
 * EloquentUserProvider, which already calls Hash::check() internally.
 * verify() exists for future use (e.g. a "confirm password before
 * changing email" flow), not wired into login today.
 */
interface PasswordHasher
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hash): bool;
}
